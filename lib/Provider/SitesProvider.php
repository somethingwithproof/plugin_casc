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
use Somethingwithproof\Casc\Secret\SecretResolver;

/**
 * Reconciles Cacti site records keyed by unique site name.
 */
final class SitesProvider implements ResourceProvider {
	private const FIELD_MAP = [
		'address1'    => 'address1',
		'address2'    => 'address2',
		'city'        => 'city',
		'state'       => 'state',
		'postalCode'  => 'postal_code',
		'country'     => 'country',
		'timezone'    => 'timezone',
		'alternateId' => 'alternate_id',
		'notes'       => 'notes',
	];

	/**
	 * Return the sites domain name.
	 *
	 * @return string Domain identifier ('sites').
	 */
	public function domain(): string {
		return 'sites';
	}

	/**
	 * Return the JSON Schema Draft 2020-12 fragment for spec.sites.
	 *
	 * @return array<string, mixed> JSON Schema describing declarative site records.
	 */
	public function describeSchema(): array {
		return [
			'type'        => 'array',
			'description' => 'Declarative Cacti site records keyed by unique site name.',
			'items'       => [
				'type'                 => 'object',
				'required'             => ['name'],
				'additionalProperties' => false,
				'properties'           => [
					'name'        => ['type' => 'string', 'minLength' => 1],
					'address1'    => ['type' => 'string'],
					'address2'    => ['type' => 'string'],
					'city'        => ['type' => 'string'],
					'state'       => ['type' => ['string', 'null']],
					'postalCode'  => ['type' => 'string'],
					'country'     => ['type' => 'string'],
					'timezone'    => ['type' => 'string'],
					'latitude'    => ['type' => 'number'],
					'longitude'   => ['type' => 'number'],
					'zoom'        => ['type' => ['integer', 'null']],
					'alternateId' => ['type' => 'string'],
					'notes'       => ['type' => ['string', 'null']],
				],
			],
		];
	}

	/**
	 * Export all sites sorted lexicographically by name.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<string, mixed> $options Export options.
	 * @return array{spec: list<array<string, mixed>>}
	 */
	public function export(StateRepository $repo, array $options = []): array {
		$rows = $repo->getSites();
		usort($rows, static fn (array $a, array $b): int => strcmp(self::str($a['name'] ?? ''), self::str($b['name'] ?? '')));

		$out = [];

		foreach ($rows as $row) {
			$name = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';

			if ($name === '') {
				continue;
			}

			$entry = ['name' => $name];

			foreach (self::FIELD_MAP as $yamlKey => $dbCol) {
				$val = is_scalar($row[$dbCol] ?? null) ? (string) $row[$dbCol] : '';

				if ($val !== '') {
					$entry[$yamlKey] = $val;
				}
			}

			$lat = is_numeric($row['latitude'] ?? null) ? (float) $row['latitude'] : 0.0;
			$lon = is_numeric($row['longitude'] ?? null) ? (float) $row['longitude'] : 0.0;

			if ($lat !== 0.0 || $lon !== 0.0) {
				$entry['latitude']  = $lat;
				$entry['longitude'] = $lon;
			}

			if (is_numeric($row['zoom'] ?? null)) {
				$entry['zoom'] = (int) $row['zoom'];
			}

			$out[] = $entry;
		}

		return ['spec' => $out];
	}

	/**
	 * Validate site list shape and natural-key uniqueness.
	 *
	 * @param array<array-key, mixed> $desired Desired sites list.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param array<string, mixed> $options Validation options.
	 * @return list<string> Validation error messages.
	 */
	public function validate(array $desired, SecretResolver $secrets, array $options = []): array {
		$errors = [];

		if ($desired !== [] && !array_is_list($desired)) {
			return ['sites must be a list of site objects'];
		}

		$seen = [];

		foreach ($desired as $idx => $entry) {
			if (!is_array($entry)) {
				$errors[] = sprintf('sites[%d] must be a map', (int) $idx);

				continue;
			}

			$name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';

			if ($name === '') {
				$errors[] = sprintf('sites[%d].name is required and must be a non-empty string', (int) $idx);

				continue;
			}

			if (isset($seen[$name])) {
				$errors[] = sprintf('Duplicate site natural key "%s"', $name);
			}

			$seen[$name] = true;
		}

		return $errors;
	}

	/**
	 * Plan site creates, updates, and optional prunes against live repository state.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<array-key, mixed> $desired Desired sites list.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param array<string, mixed> $options Planning options supporting prune.
	 * @return list<PlanAction> Planned site actions.
	 */
	public function plan(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$actions = [];
		$errors  = $this->validate($desired, $secrets, $options);

		foreach ($errors as $error) {
			$actions[] = new PlanAction('sites', PlanAction::ERROR, 'sites', $error);
		}

		if ($errors !== []) {
			return $actions;
		}

		$byName = [];

		foreach ($repo->getSites() as $row) {
			$name = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';

			if (isset($byName[$name])) {
				$actions[] = new PlanAction('sites', PlanAction::ERROR, $name, sprintf('Duplicate site name "%s" exists in database; resolve manually before planning', $name));

				return $actions;
			}

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

			if ($existing === null) {
				$actions[] = new PlanAction('sites', PlanAction::CREATE, $name, sprintf('Create site "%s"', $name));

				continue;
			}

			$diffs = $this->diffSite($existing, $entry);

			if ($diffs !== []) {
				$actions[] = new PlanAction('sites', PlanAction::UPDATE, $name, sprintf('Update site "%s"', $name), $diffs);
			}
		}

		$prune = is_array($options['prune'] ?? null) && in_array('sites', $options['prune'], true);

		if ($prune) {
			foreach ($byName as $name => $row) {
				if (!isset($desiredNameSet[$name])) {
					$actions[] = new PlanAction('sites', PlanAction::DELETE, $name, sprintf('Prune unmanaged site "%s"', $name));
				}
			}
		}

		return $actions;
	}

	/**
	 * Apply planned site mutations inside the active transaction.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<array-key, mixed> $desired Desired sites list.
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

		foreach ($repo->getSites() as $row) {
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
				$payload  = $this->buildDbPayload($action->naturalKey, $entry, $existing);
				$repo->saveSite($payload);
				$applied[] = $action;
			} elseif ($action->operation === PlanAction::DELETE) {
				$existing = $byName[$action->naturalKey] ?? null;

				if ($existing !== null && is_numeric($existing['id'] ?? null)) {
					$repo->deleteSite((int) $existing['id']);
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
	 * Compute field-level diffs between a live site row and a desired manifest entry.
	 *
	 * @param array<string, mixed> $existing Live database site row.
	 * @param array<array-key, mixed> $entry Desired site manifest entry.
	 * @return array<string, array{from: mixed, to: mixed}> Map of changed fields.
	 */
	private function diffSite(array $existing, array $entry): array {
		$diffs = [];

		foreach (self::FIELD_MAP as $yamlKey => $dbCol) {
			if (!array_key_exists($yamlKey, $entry)) {
				continue;
			}

			$cur = is_scalar($existing[$dbCol] ?? null) ? (string) $existing[$dbCol] : '';
			$des = is_scalar($entry[$yamlKey]) ? (string) $entry[$yamlKey] : '';

			if ($cur !== $des) {
				$diffs[$yamlKey] = ['from' => $cur, 'to' => $des];
			}
		}

		foreach (['latitude', 'longitude'] as $coord) {
			if (!array_key_exists($coord, $entry)) {
				continue;
			}

			$cur = is_numeric($existing[$coord] ?? null) ? (float) $existing[$coord] : 0.0;
			$des = is_numeric($entry[$coord]) ? (float) $entry[$coord] : 0.0;

			if (abs($cur - $des) > 0.0000001) {
				$diffs[$coord] = ['from' => $cur, 'to' => $des];
			}
		}

		if (array_key_exists('zoom', $entry)) {
			$cur = is_numeric($existing['zoom'] ?? null) ? (int) $existing['zoom'] : null;
			$des = is_numeric($entry['zoom']) ? (int) $entry['zoom'] : null;

			if ($cur !== $des) {
				$diffs['zoom'] = ['from' => $cur, 'to' => $des];
			}
		}

		return $diffs;
	}

	/**
	 * Build a database save payload merging desired site attributes with existing row defaults.
	 *
	 * @param string $name Canonical site natural key.
	 * @param array<array-key, mixed> $entry Desired site manifest entry.
	 * @param array<string, mixed> $existing Live site row or empty array.
	 * @return array<string, mixed> Payload suitable for StateRepository::saveSite().
	 */
	private function buildDbPayload(string $name, array $entry, array $existing): array {
		$payload = [
			'id'           => isset($existing['id']) && is_numeric($existing['id']) ? (int) $existing['id'] : 0,
			'name'         => $name,
			'address1'     => self::str($entry['address1'] ?? $existing['address1'] ?? ''),
			'address2'     => self::str($entry['address2'] ?? $existing['address2'] ?? ''),
			'city'         => self::str($entry['city'] ?? $existing['city'] ?? ''),
			'state'        => array_key_exists('state', $entry) ? (is_scalar($entry['state']) ? (string) $entry['state'] : null) : ($existing['state'] ?? null),
			'postal_code'  => self::str($entry['postalCode'] ?? $existing['postal_code'] ?? ''),
			'country'      => self::str($entry['country'] ?? $existing['country'] ?? ''),
			'timezone'     => self::str($entry['timezone'] ?? $existing['timezone'] ?? ''),
			'latitude'     => is_numeric($entry['latitude'] ?? null) ? (float) $entry['latitude'] : (is_numeric($existing['latitude'] ?? null) ? (float) $existing['latitude'] : 0.0),
			'longitude'    => is_numeric($entry['longitude'] ?? null) ? (float) $entry['longitude'] : (is_numeric($existing['longitude'] ?? null) ? (float) $existing['longitude'] : 0.0),
			'zoom'         => array_key_exists('zoom', $entry) ? (is_numeric($entry['zoom']) ? (int) $entry['zoom'] : null) : (is_numeric($existing['zoom'] ?? null) ? (int) $existing['zoom'] : null),
			'alternate_id' => self::str($entry['alternateId'] ?? $existing['alternate_id'] ?? ''),
			'notes'        => array_key_exists('notes', $entry) ? (is_scalar($entry['notes']) ? (string) $entry['notes'] : null) : ($existing['notes'] ?? null),
		];

		return $payload;
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
