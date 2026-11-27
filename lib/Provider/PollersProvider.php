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

namespace Somethingwithproof\Casc\Provider;

use Somethingwithproof\Casc\Engine\PlanAction;
use Somethingwithproof\Casc\Repository\StateRepository;
use Somethingwithproof\Casc\Secret\SecretException;
use Somethingwithproof\Casc\Secret\SecretPolicy;
use Somethingwithproof\Casc\Secret\SecretResolver;

/**
 * Reconciles primary and remote Cacti data collectors (pollers) keyed by name
 * while protecting primary poller id=1 from pruning.
 */
final class PollersProvider implements ResourceProvider {
	/**
	 * Return the pollers domain name.
	 *
	 * @return string Domain identifier ('pollers').
	 */
	public function domain(): string {
		return 'pollers';
	}

	/**
	 * Return the JSON Schema Draft 2020-12 fragment for spec.pollers.
	 *
	 * @return array<string, mixed> JSON Schema describing declarative poller records.
	 */
	public function describeSchema(): array {
		return [
			'type'        => 'array',
			'description' => 'Declarative Cacti primary and remote poller records keyed by unique name.',
			'items'       => [
				'type'                 => 'object',
				'required'             => ['name'],
				'additionalProperties' => false,
				'properties'           => [
					'name'         => ['type' => 'string', 'minLength' => 1],
					'hostname'     => ['type' => 'string'],
					'disabled'     => ['type' => 'boolean'],
					'notes'        => ['type' => 'string'],
					'timezone'     => ['type' => 'string'],
					'dbdefault'    => ['type' => 'string'],
					'dbhost'       => ['type' => 'string'],
					'dbuser'       => ['type' => 'string'],
					'dbpass'       => ['type' => 'string'],
					'dbport'       => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535],
					'dbretries'    => ['type' => 'integer', 'minimum' => 0],
					'dbssl'        => ['type' => 'string'],
					'processes'    => ['type' => 'integer', 'minimum' => 1],
					'threads'      => ['type' => 'integer', 'minimum' => 1],
					'syncInterval' => ['type' => 'integer', 'minimum' => 1],
				],
			],
		];
	}

	/**
	 * Export pollers with primary poller first and secrets redacted.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<string, mixed> $options Export options supporting omitSecrets.
	 * @return array{spec: list<array<string, mixed>>}
	 */
	public function export(StateRepository $repo, array $options = []): array {
		$omitSecrets = (bool) ($options['omitSecrets'] ?? false);
		$rows        = $repo->getPollers();

		usort($rows, static function (array $a, array $b): int {
			$aId = is_numeric($a['id'] ?? null) ? (int) $a['id'] : 0;
			$bId = is_numeric($b['id'] ?? null) ? (int) $b['id'] : 0;

			if ($aId === 1) {
				return -1;
			}

			if ($bId === 1) {
				return 1;
			}

			return strcmp(self::str($a['name'] ?? ''), self::str($b['name'] ?? ''));
		});

		$out = [];

		foreach ($rows as $row) {
			$name = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';

			if ($name === '') {
				continue;
			}

			$entry = [
				'name'      => $name,
				'hostname'  => is_scalar($row['hostname'] ?? null) ? (string) $row['hostname'] : '',
				'disabled'  => ($row['disabled'] ?? '') === 'on',
				'processes' => is_numeric($row['processes'] ?? null) ? (int) $row['processes'] : 1,
				'threads'   => is_numeric($row['threads'] ?? null) ? (int) $row['threads'] : 1,
			];

			foreach (['notes' => 'notes', 'timezone' => 'timezone', 'dbdefault' => 'dbdefault', 'dbhost' => 'dbhost', 'dbuser' => 'dbuser'] as $yamlKey => $dbCol) {
				$val = is_scalar($row[$dbCol] ?? null) ? (string) $row[$dbCol] : '';

				if ($val !== '') {
					$entry[$yamlKey] = $val;
				}
			}

			if (is_numeric($row['dbport'] ?? null) && (int) $row['dbport'] !== 3306) {
				$entry['dbport'] = (int) $row['dbport'];
			}

			if (is_numeric($row['sync_interval'] ?? null)) {
				$entry['syncInterval'] = (int) $row['sync_interval'];
			}

			$dbpass = is_scalar($row['dbpass'] ?? null) ? (string) $row['dbpass'] : '';

			if ($dbpass !== '') {
				$redacted = SecretPolicy::exportSecret('poller_' . $name . '_dbpass', $dbpass, $omitSecrets);

				if ($redacted !== null) {
					$entry['dbpass'] = $redacted;
				}
			}

			$out[] = $entry;
		}

		return ['spec' => $out];
	}

	/**
	 * Validate poller list structure, natural-key uniqueness, and dbpass secret references.
	 *
	 * @param array<array-key, mixed> $desired Desired pollers list.
	 * @param SecretResolver $secrets Secret reference validator.
	 * @param array<string, mixed> $options Validation options.
	 * @return list<string> Validation error messages.
	 */
	public function validate(array $desired, SecretResolver $secrets, array $options = []): array {
		$errors = [];

		if ($desired !== [] && !array_is_list($desired)) {
			return ['pollers must be a list of poller objects'];
		}

		$seen = [];

		foreach ($desired as $idx => $entry) {
			if (!is_array($entry)) {
				$errors[] = sprintf('pollers[%d] must be a map', (int) $idx);

				continue;
			}

			$name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';

			if ($name === '') {
				$errors[] = sprintf('pollers[%d].name is required', (int) $idx);

				continue;
			}

			if (isset($seen[$name])) {
				$errors[] = sprintf('Duplicate poller natural key "%s"', $name);
			}

			$seen[$name] = true;

			if (isset($entry['dbpass']) && is_scalar($entry['dbpass'])) {
				try {
					$secrets->validateReference((string) $entry['dbpass']);
				} catch (SecretException $e) {
					$errors[] = sprintf('pollers[%d].dbpass: %s', (int) $idx, $e->getMessage());
				}
			}
		}

		return $errors;
	}

	/**
	 * Plan poller creates, updates, and optional remote-poller prunes while protecting id=1.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<array-key, mixed> $desired Desired pollers list.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param array<string, mixed> $options Planning options supporting prune.
	 * @return list<PlanAction> Planned poller actions.
	 */
	public function plan(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$actions = [];
		$errors  = $this->validate($desired, $secrets, $options);

		foreach ($errors as $error) {
			$actions[] = new PlanAction('pollers', PlanAction::ERROR, 'pollers', $error);
		}

		if ($errors !== []) {
			return $actions;
		}

		$byName = [];

		foreach ($repo->getPollers() as $row) {
			$name          = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';
			$byName[$name] = $row;
		}

		$desiredNameSet = [];

		foreach ($desired as $entry) {
			if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
				continue;
			}

			$name                  = trim($entry['name']);
			$desiredNameSet[$name] = true;
			$existing              = $byName[$name] ?? null;

			if (isset($entry['dbpass']) && is_scalar($entry['dbpass']) && SecretPolicy::isSecretReference((string) $entry['dbpass'])) {
				try {
					$secrets->resolve((string) $entry['dbpass']);
				} catch (SecretException $e) {
					$actions[] = new PlanAction('pollers', PlanAction::ERROR, $name, $e->getMessage());

					continue;
				}
			}

			if ($existing === null) {
				$actions[] = new PlanAction('pollers', PlanAction::CREATE, $name, sprintf('Create remote poller "%s"', $name));

				continue;
			}

			$diffs = $this->diffPoller($existing, $entry, $secrets);

			if ($diffs !== []) {
				$actions[] = new PlanAction('pollers', PlanAction::UPDATE, $name, sprintf('Update poller "%s"', $name), $diffs);
			}
		}

		$prune = is_array($options['prune'] ?? null) && in_array('pollers', $options['prune'], true);

		if ($prune) {
			foreach ($byName as $name => $row) {
				if (isset($desiredNameSet[$name])) {
					continue;
				}

				if (is_numeric($row['id'] ?? null) && (int) $row['id'] === 1) {
					$actions[] = new PlanAction('pollers', PlanAction::WARNING, $name, 'Primary poller (id=1) is protected and will not be pruned');

					continue;
				}

				$actions[] = new PlanAction('pollers', PlanAction::DELETE, $name, sprintf('Prune unmanaged remote poller "%s"', $name));
			}
		}

		return $actions;
	}

	/**
	 * Apply planned poller mutations inside the active transaction.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<array-key, mixed> $desired Desired pollers list.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param array<string, mixed> $options Apply options.
	 * @return array{applied: list<PlanAction>, needsReplication: bool, needsPollerCache: bool}
	 */
	public function apply(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$plan    = self::extractPlannedActions($options) ?? $this->plan($repo, $desired, $secrets, $options);
		$applied = [];

		if ($plan === []) {
			return [
				'applied'          => [],
				'needsReplication' => false,
				'needsPollerCache' => false,
			];
		}

		$byName = [];

		foreach ($repo->getPollers() as $row) {
			$byName[self::str($row['name'] ?? '')] = $row;
		}

		$desiredByName = [];

		foreach ($desired as $entry) {
			if (is_array($entry) && is_string($entry['name'] ?? null)) {
				$desiredByName[trim($entry['name'])] = $entry;
			}
		}

		foreach ($plan as $action) {
			if ($action->operation === PlanAction::ERROR) {
				throw new \RuntimeException($action->summary);
			}

			if ($action->operation === PlanAction::CREATE || $action->operation === PlanAction::UPDATE) {
				$entry    = $desiredByName[$action->naturalKey] ?? [];
				$existing = $byName[$action->naturalKey] ?? [];
				$dbpass   = array_key_exists('dbpass', $entry) && is_scalar($entry['dbpass'])
					? $secrets->resolve((string) $entry['dbpass'])
					: self::str($existing['dbpass'] ?? '');

				$repo->savePoller([
					'id'            => isset($existing['id']) && is_numeric($existing['id']) ? (int) $existing['id'] : 0,
					'name'          => $action->naturalKey,
					'hostname'      => self::str($entry['hostname'] ?? $existing['hostname'] ?? ''),
					'disabled'      => array_key_exists('disabled', $entry) ? ((bool) $entry['disabled'] ? 'on' : '') : self::str($existing['disabled'] ?? ''),
					'notes'         => self::str($entry['notes'] ?? $existing['notes'] ?? ''),
					'timezone'      => self::str($entry['timezone'] ?? $existing['timezone'] ?? ''),
					'dbdefault'     => self::str($entry['dbdefault'] ?? $existing['dbdefault'] ?? 'cacti'),
					'dbhost'        => self::str($entry['dbhost'] ?? $existing['dbhost'] ?? ''),
					'dbuser'        => self::str($entry['dbuser'] ?? $existing['dbuser'] ?? ''),
					'dbpass'        => $dbpass,
					'dbport'        => is_numeric($entry['dbport'] ?? null) ? (int) $entry['dbport'] : (is_numeric($existing['dbport'] ?? null) ? (int) $existing['dbport'] : 3306),
					'dbretries'     => is_numeric($entry['dbretries'] ?? null) ? (int) $entry['dbretries'] : (is_numeric($existing['dbretries'] ?? null) ? (int) $existing['dbretries'] : 2),
					'dbssl'         => self::str($entry['dbssl'] ?? $existing['dbssl'] ?? ''),
					'processes'     => is_numeric($entry['processes'] ?? null) ? (int) $entry['processes'] : (is_numeric($existing['processes'] ?? null) ? (int) $existing['processes'] : 1),
					'threads'       => is_numeric($entry['threads'] ?? null) ? (int) $entry['threads'] : (is_numeric($existing['threads'] ?? null) ? (int) $existing['threads'] : 1),
					'sync_interval' => is_numeric($entry['syncInterval'] ?? null) ? (int) $entry['syncInterval'] : (is_numeric($existing['sync_interval'] ?? null) ? (int) $existing['sync_interval'] : 7200),
				]);

				$applied[] = $action;
			} elseif ($action->operation === PlanAction::DELETE) {
				$existing = $byName[$action->naturalKey] ?? null;

				if ($existing !== null && is_numeric($existing['id'] ?? null) && (int) $existing['id'] !== 1) {
					$repo->deletePoller((int) $existing['id']);
					$applied[] = $action;
				}
			}
		}

		return [
			'applied'          => $applied,
			'needsReplication' => $applied !== [],
			'needsPollerCache' => false,
		];
	}

	/**
	 * Compute masked field-level diffs between a live poller row and desired entry.
	 *
	 * @param array<string, mixed> $existing Live poller row.
	 * @param array<array-key, mixed> $entry Desired poller manifest entry.
	 * @param SecretResolver $secrets Secret resolver for comparing dbpass.
	 * @return array<string, array{from: mixed, to: mixed}> Map of changed fields.
	 */
	private function diffPoller(array $existing, array $entry, SecretResolver $secrets): array {
		$diffs = [];

		foreach (['hostname' => 'hostname', 'notes' => 'notes', 'timezone' => 'timezone', 'dbdefault' => 'dbdefault', 'dbhost' => 'dbhost', 'dbuser' => 'dbuser'] as $yamlKey => $dbCol) {
			if (!array_key_exists($yamlKey, $entry)) {
				continue;
			}

			$cur = is_scalar($existing[$dbCol] ?? null) ? (string) $existing[$dbCol] : '';
			$des = is_scalar($entry[$yamlKey]) ? (string) $entry[$yamlKey] : '';

			if ($cur !== $des) {
				$diffs[$yamlKey] = ['from' => $cur, 'to' => $des];
			}
		}

		foreach (['processes' => 'processes', 'threads' => 'threads', 'dbport' => 'dbport', 'syncInterval' => 'sync_interval'] as $yamlKey => $dbCol) {
			if (!array_key_exists($yamlKey, $entry)) {
				continue;
			}

			$cur = is_numeric($existing[$dbCol] ?? null) ? (int) $existing[$dbCol] : 0;
			$des = is_numeric($entry[$yamlKey]) ? (int) $entry[$yamlKey] : 0;

			if ($cur !== $des) {
				$diffs[$yamlKey] = ['from' => $cur, 'to' => $des];
			}
		}

		if (array_key_exists('disabled', $entry)) {
			$cur = ($existing['disabled'] ?? '') === 'on';
			$des = (bool) $entry['disabled'];

			if ($cur !== $des) {
				$diffs['disabled'] = ['from' => $cur, 'to' => $des];
			}
		}

		if (array_key_exists('dbpass', $entry) && is_scalar($entry['dbpass'])) {
			$cur      = is_scalar($existing['dbpass'] ?? null) ? (string) $existing['dbpass'] : '';
			$raw      = (string) $entry['dbpass'];
			$resolved = $secrets->resolve($raw);

			if (!hash_equals($cur, $resolved)) {
				$diffs['dbpass'] = [
					'from' => SecretPolicy::maskForDiff($cur),
					'to'   => SecretPolicy::maskForDiff($raw),
				];
			}
		}

		return $diffs;
	}

	/**
	 * Extract precomputed domain actions supplied by Applier::apply() when present.
	 *
	 * @param array<string, mixed> $options Apply options map.
	 * @return list<PlanAction>|null Precomputed actions or null when standalone.
	 */
	private static function extractPlannedActions(array $options): ?array {
		if (!isset($options['plannedActions']) || !is_array($options['plannedActions'])) {
			return null;
		}

		$actions = [];

		foreach ($options['plannedActions'] as $action) {
			if ($action instanceof PlanAction) {
				$actions[] = $action;
			}
		}

		return $actions;
	}

	/**
	 * Convert a scalar value to string, returning an empty string for non-scalars.
	 *
	 * @param mixed $value Candidate scalar value.
	 * @return string String representation.
	 */
	private static function str(mixed $value): string {
		return is_scalar($value) ? (string) $value : '';
	}
}
