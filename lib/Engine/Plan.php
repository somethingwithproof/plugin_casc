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

/**
 * Deterministic reconciliation plan with a canonical SHA-256 plan hash.
 */
final class Plan {
	/**
	 * Construct a deterministic reconciliation plan.
	 *
	 * @param string $planHash Canonical 64-char SHA-256 hash of manifest, prune options, and actions.
	 * @param string $manifestSha256 SHA-256 hash of the canonicalized manifest document.
	 * @param list<PlanAction> $actions Ordered list of planned actions.
	 */
	public function __construct(
		public readonly string $planHash,
		public readonly string $manifestSha256,
		public readonly array $actions,
	) {
	}

	/**
	 * Determine whether the plan contains any mutating action (create, update, or delete).
	 *
	 * @return bool True when at least one mutation is planned.
	 */
	public function hasChanges(): bool {
		foreach ($this->actions as $action) {
			if ($action->isMutation()) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether the plan contains any blocking error action.
	 *
	 * @return bool True when at least one error action is present.
	 */
	public function hasErrors(): bool {
		foreach ($this->actions as $action) {
			if ($action->operation === PlanAction::ERROR) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tally action counts grouped by operation type.
	 *
	 * @return array{create: int, update: int, delete: int, warning: int, error: int}
	 */
	public function counts(): array {
		$counts = [
			'create'  => 0,
			'update'  => 0,
			'delete'  => 0,
			'warning' => 0,
			'error'   => 0,
		];

		foreach ($this->actions as $action) {
			if (isset($counts[$action->operation])) {
				$counts[$action->operation]++;
			}
		}

		return $counts;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'planHash'       => $this->planHash,
			'manifestSha256' => $this->manifestSha256,
			'hasChanges'     => $this->hasChanges(),
			'hasErrors'      => $this->hasErrors(),
			'counts'         => $this->counts(),
			'actions'        => array_map(static fn (PlanAction $a): array => $a->toArray(), $this->actions),
		];
	}

	/**
	 * Render a human-readable multi-line plan diff summary.
	 *
	 * @return string Formatted text plan output.
	 */
	public function formatText(): string {
		$lines = [];

		foreach ($this->actions as $action) {
			$symbol = match ($action->operation) {
				PlanAction::CREATE  => '+',
				PlanAction::UPDATE  => '~',
				PlanAction::DELETE  => '-',
				PlanAction::WARNING => '!',
				PlanAction::ERROR   => 'X',
				default             => ' ',
			};

			$lines[] = sprintf('[%s] %s/%s: %s', $symbol, $action->domain, $action->naturalKey, $action->summary);

			foreach ($action->changes as $field => $diff) {
				$from = json_encode($diff['from'], JSON_UNESCAPED_SLASHES);
				$to   = json_encode($diff['to'], JSON_UNESCAPED_SLASHES);
				$lines[] = sprintf('    %s: %s -> %s', $field, $from === false ? 'null' : $from, $to === false ? 'null' : $to);
			}
		}

		$counts = $this->counts();
		$lines[] = sprintf(
			'Plan %s: %d create, %d update, %d delete, %d warning, %d error',
			$this->planHash,
			$counts['create'],
			$counts['update'],
			$counts['delete'],
			$counts['warning'],
			$counts['error']
		);

		return implode("\n", $lines) . "\n";
	}

	/**
	 * Render the plan as pretty-printed JSON.
	 *
	 * @return string JSON plan output ending with a newline.
	 */
	public function formatJson(): string {
		return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	}

	/**
	 * Nagios/Icinga-compatible single-line status output with perfdata.
	 *
	 * @return array{code: int, line: string}
	 */
	public function formatNagios(): array {
		$c = $this->counts();
		$perf = sprintf(
			'create=%d update=%d delete=%d warning=%d error=%d',
			$c['create'],
			$c['update'],
			$c['delete'],
			$c['warning'],
			$c['error']
		);

		if ($this->hasErrors()) {
			return [
				'code' => 2,
				'line' => sprintf('CASC CRITICAL - %d plan errors (%d create, %d update, %d delete) | %s', $c['error'], $c['create'], $c['update'], $c['delete'], $perf),
			];
		}

		if ($this->hasChanges()) {
			return [
				'code' => 1,
				'line' => sprintf('CASC WARNING - drift detected (%d create, %d update, %d delete, plan %s) | %s', $c['create'], $c['update'], $c['delete'], substr($this->planHash, 0, 12), $perf),
			];
		}

		return [
			'code' => 0,
			'line' => sprintf('CASC OK - no configuration drift (plan %s) | %s', substr($this->planHash, 0, 12), $perf),
		];
	}
}
