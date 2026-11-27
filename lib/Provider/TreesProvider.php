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
use Somethingwithproof\Casc\Model\CascDocument;
use Somethingwithproof\Casc\Repository\StateRepository;
use Somethingwithproof\Casc\Secret\SecretResolver;

final class TreesProvider implements ResourceProvider {
	private const SORT_TO_NAME = [
		1 => 'manual',
		2 => 'alpha',
		3 => 'numeric',
		4 => 'natural',
	];

	private const NAME_TO_SORT = [
		'manual'  => 1,
		'alpha'   => 2,
		'numeric' => 3,
		'natural' => 4,
	];

	public function domain(): string {
		return 'trees';
	}

	public function export(StateRepository $repo, array $options = []): array {
		$rows = $repo->getTrees();
		usort($rows, static fn (array $a, array $b): int => strcmp(self::str($a['name'] ?? ''), self::str($b['name'] ?? '')));

		$out = [];

		foreach ($rows as $row) {
			$name = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';

			if ($name === '') {
				continue;
			}

			$sortCode = is_numeric($row['sort_type'] ?? null) ? (int) $row['sort_type'] : 1;
			$entry    = [
				'name'     => $name,
				'enabled'  => ($row['enabled'] ?? 'on') === 'on',
				'sortType' => self::SORT_TO_NAME[$sortCode] ?? 'manual',
			];

			if (isset($row['items']) && is_array($row['items']) && $row['items'] !== []) {
				$entry['items'] = array_values($row['items']);
			}

			$out[] = $entry;
		}

		return ['spec' => $out];
	}

	public function validate(array $desired, SecretResolver $secrets, array $options = []): array {
		$errors = [];

		if ($desired !== [] && !array_is_list($desired)) {
			return ['trees must be a list of tree objects'];
		}

		$seen = [];

		foreach ($desired as $idx => $entry) {
			if (!is_array($entry)) {
				$errors[] = sprintf('trees[%d] must be a map', (int) $idx);

				continue;
			}

			$name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';

			if ($name === '') {
				$errors[] = sprintf('trees[%d].name is required', (int) $idx);

				continue;
			}

			if (isset($seen[$name])) {
				$errors[] = sprintf('Duplicate tree natural key "%s"', $name);
			}

			$seen[$name] = true;

			if (isset($entry['sortType']) && (!is_string($entry['sortType']) || !isset(self::NAME_TO_SORT[$entry['sortType']]))) {
				$errors[] = sprintf('trees[%d].sortType must be one of manual, alpha, numeric, natural', (int) $idx);
			}

			if (isset($entry['items']) && (!is_array($entry['items']) || ($entry['items'] !== [] && !array_is_list($entry['items'])))) {
				$errors[] = sprintf('trees[%d].items must be a list', (int) $idx);
			}
		}

		return $errors;
	}

	public function plan(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$actions = [];
		$errors  = $this->validate($desired, $secrets, $options);

		foreach ($errors as $error) {
			$actions[] = new PlanAction('trees', PlanAction::ERROR, 'trees', $error);
		}

		if ($errors !== []) {
			return $actions;
		}

		$byName = [];

		foreach ($repo->getTrees() as $row) {
			$byName[self::str($row['name'] ?? '')] = $row;
		}

		$desiredNames = [];

		foreach ($desired as $entry) {
			if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
				continue;
			}

			$name           = trim($entry['name']);
			$desiredNames[] = $name;
			$existing       = $byName[$name] ?? null;

			if ($existing === null) {
				$actions[] = new PlanAction('trees', PlanAction::CREATE, $name, sprintf('Create graph tree "%s"', $name));

				continue;
			}

			$diffs = $this->diffTree($existing, $entry);

			if ($diffs !== []) {
				$actions[] = new PlanAction('trees', PlanAction::UPDATE, $name, sprintf('Update graph tree "%s"', $name), $diffs);
			}
		}

		$prune = is_array($options['prune'] ?? null) && in_array('trees', $options['prune'], true);

		if ($prune) {
			foreach ($byName as $name => $row) {
				if (!in_array($name, $desiredNames, true)) {
					$actions[] = new PlanAction('trees', PlanAction::DELETE, $name, sprintf('Prune unmanaged graph tree "%s"', $name));
				}
			}
		}

		return $actions;
	}

	public function apply(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$plan    = $this->plan($repo, $desired, $secrets, $options);
		$applied = [];

		$byName = [];

		foreach ($repo->getTrees() as $row) {
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
				$sortName = is_string($entry['sortType'] ?? null) ? $entry['sortType'] : 'manual';

				$repo->saveTree([
					'id'        => isset($existing['id']) && is_numeric($existing['id']) ? (int) $existing['id'] : 0,
					'name'      => $action->naturalKey,
					'enabled'   => array_key_exists('enabled', $entry) ? ((bool) $entry['enabled'] ? 'on' : '') : self::str($existing['enabled'] ?? 'on'),
					'sort_type' => self::NAME_TO_SORT[$sortName] ?? (is_numeric($existing['sort_type'] ?? null) ? (int) $existing['sort_type'] : 1),
					'items'     => isset($entry['items']) && is_array($entry['items']) ? array_values($entry['items']) : ($existing['items'] ?? []),
				]);

				$applied[] = $action;
			} elseif ($action->operation === PlanAction::DELETE) {
				$existing = $byName[$action->naturalKey] ?? null;

				if ($existing !== null && is_numeric($existing['id'] ?? null)) {
					$repo->deleteTree((int) $existing['id']);
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
	 * @param array<string, mixed> $existing
	 * @param array<array-key, mixed> $entry
	 * @return array<string, array{from: mixed, to: mixed}>
	 */
	private function diffTree(array $existing, array $entry): array {
		$diffs = [];

		if (array_key_exists('enabled', $entry)) {
			$cur = ($existing['enabled'] ?? 'on') === 'on';
			$des = (bool) $entry['enabled'];

			if ($cur !== $des) {
				$diffs['enabled'] = ['from' => $cur, 'to' => $des];
			}
		}

		if (isset($entry['sortType']) && is_string($entry['sortType'])) {
			$curCode = is_numeric($existing['sort_type'] ?? null) ? (int) $existing['sort_type'] : 1;
			$curName = self::SORT_TO_NAME[$curCode] ?? 'manual';

			if ($curName !== $entry['sortType']) {
				$diffs['sortType'] = ['from' => $curName, 'to' => $entry['sortType']];
			}
		}

		if (array_key_exists('items', $entry)) {
			$curItems = self::canonicalizeItems(isset($existing['items']) && is_array($existing['items']) ? array_values($existing['items']) : []);
			$desItems = self::canonicalizeItems(is_array($entry['items']) ? array_values($entry['items']) : []);

			if ($curItems !== $desItems) {
				$diffs['items'] = ['from' => count($curItems) . ' root items', 'to' => count($desItems) . ' root items'];
			}
		}

		return $diffs;
	}

	/**
	 * Normalize nested tree item maps to sorted keys so PHP === comparison is
	 * invariant to map key insertion order while preserving list order.
	 *
	 * @param list<mixed> $items
	 * @return list<mixed>
	 */
	private static function canonicalizeItems(array $items): array {
		$wrapped = CascDocument::canonicalizeMap(['items' => $items]);

		return isset($wrapped['items']) && is_array($wrapped['items']) && array_is_list($wrapped['items'])
			? $wrapped['items']
			: [];
	}

	private static function str(mixed $value): string {
		return is_scalar($value) ? (string) $value : '';
	}
}
