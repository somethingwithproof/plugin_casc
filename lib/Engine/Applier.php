<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_casc, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_casc
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

declare(strict_types=1);

namespace Somethingwithproof\Casc\Engine;

use Somethingwithproof\Casc\Model\CascDocument;
use Somethingwithproof\Casc\Repository\StateRepository;
use Somethingwithproof\Casc\Secret\SecretResolver;

/**
 * Transactional CasC applier with plan-hash drift verification, post-apply
 * replication/poller-cache propagation, and zero-secret audit recording.
 */
final class Applier {
	/**
	 * Construct a transactional applier using the given Planner instance.
	 *
	 * @param Planner $planner Reconciliation planner used for pre-apply drift checks.
	 */
	public function __construct(
		private readonly Planner $planner,
	) {
	}

	/**
	 * Apply a manifest only when the live plan hash computed inside the
	 * transaction matches $confirmPlanHash.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param CascDocument $document Parsed CasC manifest document.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param string $confirmPlanHash Confirmed 64-char SHA-256 plan hash.
	 * @param string $actor Operator identity recorded in plugin_casc_audit.
	 * @param string $source Invocation channel ('cli' or 'web').
	 * @param array<string, mixed> $options Apply options (baseDir, prune, templateCompilers).
	 * @return ApplyResult Execution outcome including audit ID and replication flags.
	 */
	public function apply(
		StateRepository $repo,
		CascDocument $document,
		SecretResolver $secrets,
		string $confirmPlanHash,
		string $actor = 'cli',
		string $source = 'cli',
		array $options = [],
	): ApplyResult {
		$confirm = strtolower(trim($confirmPlanHash));

		if (preg_match('/^[a-f0-9]{64}$/D', $confirm) !== 1) {
			return new ApplyResult(false, '', [], ['A valid 64-character SHA-256 --confirm=<PLAN_HASH> is required to apply'], false, false, 0);
		}

		$applied          = [];
		$needsReplication = false;
		$needsPollerCache = false;
		$livePlanHash     = '';

		$repo->beginTransaction();

		try {
			$livePlan     = $this->planner->plan($repo, $document, $secrets, $options);
			$livePlanHash = $livePlan->planHash;

			if ($livePlan->hasErrors()) {
				$repo->rollback();
				$errors = [];

				foreach ($livePlan->actions as $action) {
					if ($action->operation === PlanAction::ERROR) {
						$errors[] = sprintf('%s/%s: %s', $action->domain, $action->naturalKey, $action->summary);
					}
				}

				$auditId = $this->writeAudit($repo, $actor, $source, $livePlanHash, 'refused', [], $errors);

				return new ApplyResult(false, $livePlanHash, [], $errors, false, false, $auditId);
			}

			if (!hash_equals($livePlanHash, $confirm)) {
				$repo->rollback();
				$error   = sprintf('Plan hash mismatch (expected %s, live %s); controller state drifted since planning. Re-run plan.', $confirm, $livePlanHash);
				$auditId = $this->writeAudit($repo, $actor, $source, $livePlanHash, 'drifted', [], [$error]);

				return new ApplyResult(false, $livePlanHash, [], [$error], false, false, $auditId);
			}

			if (!$livePlan->hasChanges()) {
				$repo->commit();
				$auditId = $this->writeAudit($repo, $actor, $source, $livePlanHash, 'noop', [], []);

				return new ApplyResult(true, $livePlanHash, [], [], false, false, $auditId);
			}

			$applyOptions = $options + ['planHash' => $livePlanHash];

			foreach ($this->planner->orderedDomains() as $domain) {
				if (!isset($document->spec[$domain]) || !is_array($document->spec[$domain])) {
					continue;
				}

				$provider = $this->planner->provider($domain);

				if ($provider === null) {
					continue;
				}

				$result = $provider->apply($repo, $document->spec[$domain], $secrets, $applyOptions);

				foreach ($result['applied'] as $act) {
					$applied[] = $act;
				}

				$needsReplication = $needsReplication || $result['needsReplication'];
				$needsPollerCache = $needsPollerCache || $result['needsPollerCache'];
			}

			$repo->commit();
		} catch (\Throwable $e) {
			$repo->rollback();
			$error   = 'Apply aborted and rolled back: ' . $e->getMessage();
			$auditId = $this->writeAudit($repo, $actor, $source, $livePlanHash !== '' ? $livePlanHash : $confirm, 'failed', [], [$error]);

			return new ApplyResult(false, $livePlanHash !== '' ? $livePlanHash : $confirm, [], [$error], false, false, $auditId);
		}

		if ($needsReplication) {
			$repo->triggerReplication('all');
		}

		if ($needsPollerCache) {
			$repo->triggerPollerCacheUpdate(null);
		}

		$auditId = $this->writeAudit($repo, $actor, $source, $livePlanHash, 'succeeded', $applied, []);

		return new ApplyResult(true, $livePlanHash, $applied, [], $needsReplication, $needsPollerCache, $auditId);
	}

	/**
	 * Write an audit record with counts and masked action summaries (zero secrets).
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param string $actor Operator identity.
	 * @param string $source Invocation source ('cli' or 'web').
	 * @param string $planHash Plan hash associated with the apply attempt.
	 * @param string $status Outcome status ('succeeded', 'noop', 'drifted', 'refused', 'failed').
	 * @param list<PlanAction> $applied Applied plan actions.
	 * @param list<string> $errors Error messages recorded on failure or refusal.
	 * @return int Inserted audit row ID.
	 */
	private function writeAudit(
		StateRepository $repo,
		string $actor,
		string $source,
		string $planHash,
		string $status,
		array $applied,
		array $errors,
	): int {
		$created = 0;
		$updated = 0;
		$deleted = 0;

		foreach ($applied as $action) {
			match ($action->operation) {
				PlanAction::CREATE => $created++,
				PlanAction::UPDATE => $updated++,
				PlanAction::DELETE => $deleted++,
				default            => null,
			};
		}

		$summary = json_encode([
			'applied' => array_map(static fn (PlanAction $a): array => [
				'domain'     => $a->domain,
				'operation'  => $a->operation,
				'naturalKey' => $a->naturalKey,
			], $applied),
			'errors' => $errors,
		], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		return $repo->recordAudit([
			'occurred_at'   => time(),
			'actor'         => $actor,
			'source'        => $source,
			'action'        => 'apply',
			'plan_hash'     => $planHash,
			'status'        => $status,
			'created_count' => $created,
			'updated_count' => $updated,
			'deleted_count' => $deleted,
			'summary_json'  => $summary,
		]);
	}
}
