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
 * Reconciles Cacti devices keyed by external_id or hostname::description with
 * masked SNMP credentials and poller/site/template resolution.
 */
final class DevicesProvider implements ResourceProvider {
	/**
	 * Return the devices domain name.
	 *
	 * @return string Domain identifier ('devices').
	 */
	public function domain(): string {
		return 'devices';
	}

	/**
	 * Return the JSON Schema Draft 2020-12 fragment for spec.devices.
	 *
	 * @return array<string, mixed> JSON Schema describing declarative device records.
	 */
	public function describeSchema(): array {
		return [
			'type'        => 'array',
			'description' => 'Declarative Cacti devices keyed by external_id or hostname::description.',
			'items'       => [
				'type'                 => 'object',
				'required'             => ['hostname', 'description'],
				'additionalProperties' => false,
				'properties'           => [
					'key'                => ['type' => 'string', 'minLength' => 1],
					'hostname'           => ['type' => 'string', 'minLength' => 1],
					'description'        => ['type' => 'string', 'minLength' => 1],
					'site'               => ['type' => 'string'],
					'poller'             => ['type' => 'string'],
					'hostTemplate'       => ['type' => 'string'],
					'disabled'           => ['type' => 'boolean'],
					'location'           => ['type' => 'string'],
					'notes'              => ['type' => 'string'],
					'snmpVersion'        => ['type' => 'integer', 'enum' => [0, 1, 2, 3]],
					'snmpCommunity'      => ['type' => 'string'],
					'snmpUsername'       => ['type' => 'string'],
					'snmpPassword'       => ['type' => 'string'],
					'snmpAuthProtocol'   => ['type' => 'string'],
					'snmpPrivPassphrase' => ['type' => 'string'],
					'snmpPrivProtocol'   => ['type' => 'string'],
					'snmpContext'        => ['type' => 'string'],
					'snmpEngineId'       => ['type' => 'string'],
					'snmpPort'           => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535],
					'snmpTimeout'        => ['type' => 'integer', 'minimum' => 1],
					'maxOids'            => ['type' => 'integer', 'minimum' => 1],
					'deviceThreads'      => ['type' => 'integer', 'minimum' => 1],
					'dataQueries'        => ['type' => 'array', 'items' => ['type' => 'string']],
					'graphTemplates'     => ['type' => 'array', 'items' => ['type' => 'string']],
				],
			],
		];
	}

	/**
	 * Export live devices sorted by natural key with all SNMP secrets redacted.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<string, mixed> $options Export options supporting omitSecrets.
	 * @return array{spec: list<array<string, mixed>>}
	 */
	public function export(StateRepository $repo, array $options = []): array {
		$omitSecrets = (bool) ($options['omitSecrets'] ?? false);

		$sitesById = [];

		foreach ($repo->getSites() as $s) {
			if (is_numeric($s['id'] ?? null) && is_scalar($s['name'] ?? null)) {
				$sitesById[(int) $s['id']] = (string) $s['name'];
			}
		}

		$pollersById = [];

		foreach ($repo->getPollers() as $p) {
			if (is_numeric($p['id'] ?? null) && is_scalar($p['name'] ?? null)) {
				$pollersById[(int) $p['id']] = (string) $p['name'];
			}
		}

		$templatesById = [];

		foreach ($repo->getHostTemplates() as $t) {
			$templatesById[$t['id']] = $t['name'];
		}

		$devices = $repo->getDevices();
		$out     = [];

		foreach ($devices as $row) {
			$naturalKey = self::naturalKeyFromRow($row);
			$entry      = [
				'key'         => $naturalKey,
				'hostname'    => is_scalar($row['hostname'] ?? null) ? (string) $row['hostname'] : '',
				'description' => is_scalar($row['description'] ?? null) ? (string) $row['description'] : '',
				'disabled'    => ($row['disabled'] ?? '') === 'on',
			];

			$siteId = is_numeric($row['site_id'] ?? null) ? (int) $row['site_id'] : 0;

			if ($siteId > 0 && isset($sitesById[$siteId])) {
				$entry['site'] = $sitesById[$siteId];
			}

			$pollerId = is_numeric($row['poller_id'] ?? null) ? (int) $row['poller_id'] : 1;

			if (isset($pollersById[$pollerId])) {
				$entry['poller'] = $pollersById[$pollerId];
			}

			$tplId = is_numeric($row['host_template_id'] ?? null) ? (int) $row['host_template_id'] : 0;

			if ($tplId > 0 && isset($templatesById[$tplId])) {
				$entry['hostTemplate'] = $templatesById[$tplId];
			}

			foreach (['location' => 'location', 'notes' => 'notes', 'snmpUsername' => 'snmp_username', 'snmpAuthProtocol' => 'snmp_auth_protocol', 'snmpPrivProtocol' => 'snmp_priv_protocol', 'snmpContext' => 'snmp_context', 'snmpEngineId' => 'snmp_engine_id'] as $yamlKey => $dbCol) {
				$val = is_scalar($row[$dbCol] ?? null) ? (string) $row[$dbCol] : '';

				if ($val !== '') {
					$entry[$yamlKey] = $val;
				}
			}

			foreach (['snmpVersion' => 'snmp_version', 'snmpPort' => 'snmp_port', 'snmpTimeout' => 'snmp_timeout', 'maxOids' => 'max_oids', 'deviceThreads' => 'device_threads'] as $yamlKey => $dbCol) {
				if (is_numeric($row[$dbCol] ?? null)) {
					$entry[$yamlKey] = (int) $row[$dbCol];
				}
			}

			foreach (['snmpCommunity' => 'snmp_community', 'snmpPassword' => 'snmp_password', 'snmpPrivPassphrase' => 'snmp_priv_passphrase'] as $yamlKey => $dbCol) {
				$raw = is_scalar($row[$dbCol] ?? null) ? (string) $row[$dbCol] : '';

				if ($raw !== '') {
					$redacted = SecretPolicy::exportSecret('device_' . $naturalKey . '_' . $dbCol, $raw, $omitSecrets);

					if ($redacted !== null) {
						$entry[$yamlKey] = $redacted;
					}
				}
			}

			if (isset($row['data_queries']) && is_array($row['data_queries']) && $row['data_queries'] !== []) {
				$dq = array_values(array_filter(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $row['data_queries']), static fn (string $s): bool => $s !== ''));
				sort($dq, SORT_STRING);

				if ($dq !== []) {
					$entry['dataQueries'] = $dq;
				}
			}

			if (isset($row['graph_templates']) && is_array($row['graph_templates']) && $row['graph_templates'] !== []) {
				$gt = array_values(array_filter(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $row['graph_templates']), static fn (string $s): bool => $s !== ''));
				sort($gt, SORT_STRING);

				if ($gt !== []) {
					$entry['graphTemplates'] = $gt;
				}
			}

			$out[] = $entry;
		}

		usort($out, static fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));

		return ['spec' => $out];
	}

	/**
	 * Validate device entries, required fields, natural keys, and SNMP secret references.
	 *
	 * @param array<array-key, mixed> $desired Desired devices list.
	 * @param SecretResolver $secrets Secret reference validator.
	 * @param array<string, mixed> $options Validation options.
	 * @return list<string> Validation error messages.
	 */
	public function validate(array $desired, SecretResolver $secrets, array $options = []): array {
		$errors = [];

		if ($desired !== [] && !array_is_list($desired)) {
			return ['devices must be a list of device objects'];
		}

		$seen = [];

		foreach ($desired as $idx => $entry) {
			if (!is_array($entry)) {
				$errors[] = sprintf('devices[%d] must be a map', (int) $idx);

				continue;
			}

			$hostname    = is_string($entry['hostname'] ?? null) ? trim($entry['hostname']) : '';
			$description = is_string($entry['description'] ?? null) ? trim($entry['description']) : '';

			if ($hostname === '') {
				$errors[] = sprintf('devices[%d].hostname is required', (int) $idx);
			}

			if ($description === '') {
				$errors[] = sprintf('devices[%d].description is required', (int) $idx);
			}

			$key = self::naturalKeyFromEntry($entry);

			if ($key === '') {
				$errors[] = sprintf('devices[%d] requires a non-empty natural key ("key" or hostname+description)', (int) $idx);

				continue;
			}

			if (isset($seen[$key])) {
				$errors[] = sprintf('Duplicate device natural key "%s"', $key);
			}

			$seen[$key] = true;

			foreach (['snmpCommunity', 'snmpPassword', 'snmpPrivPassphrase'] as $secretField) {
				if (isset($entry[$secretField]) && is_scalar($entry[$secretField])) {
					try {
						$secrets->validateReference((string) $entry[$secretField]);
					} catch (SecretException $e) {
						$errors[] = sprintf('devices[%d].%s: %s', (int) $idx, $secretField, $e->getMessage());
					}
				}
			}
		}

		return $errors;
	}

	/**
	 * Plan device creates, updates, and optional prunes with masked credential diffs.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<array-key, mixed> $desired Desired devices list.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param array<string, mixed> $options Planning options supporting prune, plannedSites, plannedPollers.
	 * @return list<PlanAction> Planned device actions.
	 */
	public function plan(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$actions = [];
		$errors  = $this->validate($desired, $secrets, $options);

		foreach ($errors as $error) {
			$actions[] = new PlanAction('devices', PlanAction::ERROR, 'devices', $error);
		}

		if ($errors !== []) {
			return $actions;
		}

		$knownSites   = $this->siteNameMap($repo);
		$knownPollers = $this->pollerNameMap($repo);
		$knownTpls    = $this->templateNameMap($repo);

		$plannedSites   = is_array($options['plannedSites'] ?? null) ? $options['plannedSites'] : [];
		$plannedPollers = is_array($options['plannedPollers'] ?? null) ? $options['plannedPollers'] : [];

		$byKey = [];

		foreach ($repo->getDevices() as $row) {
			$byKey[self::naturalKeyFromRow($row)] = $row;
		}

		$desiredKeys = [];

		foreach ($desired as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$key           = self::naturalKeyFromEntry($entry);
			$desiredKeys[] = $key;

			if (isset($entry['site']) && is_string($entry['site']) && $entry['site'] !== '') {
				if (!isset($knownSites[$entry['site']]) && !in_array($entry['site'], $plannedSites, true)) {
					$actions[] = new PlanAction('devices', PlanAction::ERROR, $key, sprintf('Device "%s" references unknown site "%s"', $key, $entry['site']));

					continue;
				}
			}

			if (isset($entry['poller']) && is_string($entry['poller']) && $entry['poller'] !== '') {
				if (!isset($knownPollers[$entry['poller']]) && !in_array($entry['poller'], $plannedPollers, true)) {
					$actions[] = new PlanAction('devices', PlanAction::ERROR, $key, sprintf('Device "%s" references unknown poller "%s"', $key, $entry['poller']));

					continue;
				}
			}

			$secretError = null;

			foreach (['snmpCommunity', 'snmpPassword', 'snmpPrivPassphrase'] as $secretField) {
				if (isset($entry[$secretField]) && is_scalar($entry[$secretField]) && SecretPolicy::isSecretReference((string) $entry[$secretField])) {
					try {
						$secrets->resolve((string) $entry[$secretField]);
					} catch (SecretException $e) {
						$secretError = $e->getMessage();

						break;
					}
				}
			}

			if ($secretError !== null) {
				$actions[] = new PlanAction('devices', PlanAction::ERROR, $key, $secretError);

				continue;
			}

			$existing = $byKey[$key] ?? null;

			if ($existing === null) {
				$actions[] = new PlanAction('devices', PlanAction::CREATE, $key, sprintf('Create device "%s" (%s)', $key, self::str($entry['hostname'] ?? '')));

				continue;
			}

			$diffs = $this->diffDevice($existing, $entry, $knownSites, $knownPollers, $knownTpls, $secrets);

			if ($diffs !== []) {
				$actions[] = new PlanAction('devices', PlanAction::UPDATE, $key, sprintf('Update device "%s"', $key), $diffs);
			}
		}

		$prune = is_array($options['prune'] ?? null) && in_array('devices', $options['prune'], true);

		if ($prune) {
			foreach ($byKey as $key => $row) {
				if (!in_array($key, $desiredKeys, true)) {
					$actions[] = new PlanAction('devices', PlanAction::DELETE, $key, sprintf('Prune unmanaged device "%s"', $key));
				}
			}
		}

		return $actions;
	}

	/**
	 * Apply planned device mutations inside the active transaction.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<array-key, mixed> $desired Desired devices list.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param array<string, mixed> $options Apply options.
	 * @return array{applied: list<PlanAction>, needsReplication: bool, needsPollerCache: bool}
	 */
	public function apply(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$plan    = $this->plan($repo, $desired, $secrets, $options);
		$applied = [];

		$sites   = $this->siteNameMap($repo);
		$pollers = $this->pollerNameMap($repo);
		$tpls    = $this->templateNameMap($repo);

		$byKey = [];

		foreach ($repo->getDevices() as $row) {
			$byKey[self::naturalKeyFromRow($row)] = $row;
		}

		$desiredByKey = [];

		foreach ($desired as $entry) {
			if (is_array($entry)) {
				$desiredByKey[self::naturalKeyFromEntry($entry)] = $entry;
			}
		}

		foreach ($plan as $action) {
			if ($action->operation === PlanAction::ERROR) {
				throw new \RuntimeException($action->summary);
			}

			if ($action->operation === PlanAction::CREATE || $action->operation === PlanAction::UPDATE) {
				$entry    = $desiredByKey[$action->naturalKey] ?? [];
				$existing = $byKey[$action->naturalKey] ?? [];

				$siteId   = isset($entry['site']) && is_string($entry['site']) && isset($sites[$entry['site']]) ? $sites[$entry['site']] : (is_numeric($existing['site_id'] ?? null) ? (int) $existing['site_id'] : 0);
				$pollerId = isset($entry['poller']) && is_string($entry['poller']) && isset($pollers[$entry['poller']]) ? $pollers[$entry['poller']] : (is_numeric($existing['poller_id'] ?? null) ? (int) $existing['poller_id'] : 1);
				$tplId    = isset($entry['hostTemplate']) && is_string($entry['hostTemplate']) && isset($tpls[$entry['hostTemplate']]) ? $tpls[$entry['hostTemplate']] : (is_numeric($existing['host_template_id'] ?? null) ? (int) $existing['host_template_id'] : 0);

				$snmpCommunity = array_key_exists('snmpCommunity', $entry) && is_scalar($entry['snmpCommunity'])
					? $secrets->resolve((string) $entry['snmpCommunity'])
					: self::str($existing['snmp_community'] ?? '');
				$snmpPassword = array_key_exists('snmpPassword', $entry) && is_scalar($entry['snmpPassword'])
					? $secrets->resolve((string) $entry['snmpPassword'])
					: self::str($existing['snmp_password'] ?? '');
				$snmpPrivPassphrase = array_key_exists('snmpPrivPassphrase', $entry) && is_scalar($entry['snmpPrivPassphrase'])
					? $secrets->resolve((string) $entry['snmpPrivPassphrase'])
					: self::str($existing['snmp_priv_passphrase'] ?? '');

				$externalId = isset($entry['key']) && is_string($entry['key']) && !str_contains($entry['key'], '::')
					? $entry['key']
					: self::str($existing['external_id'] ?? '');

				$repo->saveDevice([
					'id'                   => isset($existing['id']) && is_numeric($existing['id']) ? (int) $existing['id'] : 0,
					'external_id'          => $externalId,
					'hostname'             => self::str($entry['hostname'] ?? $existing['hostname'] ?? ''),
					'description'          => self::str($entry['description'] ?? $existing['description'] ?? ''),
					'site_id'              => $siteId,
					'poller_id'            => $pollerId,
					'host_template_id'     => $tplId,
					'disabled'             => array_key_exists('disabled', $entry) ? ((bool) $entry['disabled'] ? 'on' : '') : self::str($existing['disabled'] ?? ''),
					'location'             => self::str($entry['location'] ?? $existing['location'] ?? ''),
					'notes'                => self::str($entry['notes'] ?? $existing['notes'] ?? ''),
					'snmp_version'         => is_numeric($entry['snmpVersion'] ?? null) ? (int) $entry['snmpVersion'] : (is_numeric($existing['snmp_version'] ?? null) ? (int) $existing['snmp_version'] : 2),
					'snmp_community'       => $snmpCommunity,
					'snmp_username'        => self::str($entry['snmpUsername'] ?? $existing['snmp_username'] ?? ''),
					'snmp_password'        => $snmpPassword,
					'snmp_auth_protocol'   => self::str($entry['snmpAuthProtocol'] ?? $existing['snmp_auth_protocol'] ?? ''),
					'snmp_priv_passphrase' => $snmpPrivPassphrase,
					'snmp_priv_protocol'   => self::str($entry['snmpPrivProtocol'] ?? $existing['snmp_priv_protocol'] ?? ''),
					'snmp_context'         => self::str($entry['snmpContext'] ?? $existing['snmp_context'] ?? ''),
					'snmp_engine_id'       => self::str($entry['snmpEngineId'] ?? $existing['snmp_engine_id'] ?? ''),
					'snmp_port'            => is_numeric($entry['snmpPort'] ?? null) ? (int) $entry['snmpPort'] : (is_numeric($existing['snmp_port'] ?? null) ? (int) $existing['snmp_port'] : 161),
					'snmp_timeout'         => is_numeric($entry['snmpTimeout'] ?? null) ? (int) $entry['snmpTimeout'] : (is_numeric($existing['snmp_timeout'] ?? null) ? (int) $existing['snmp_timeout'] : 500),
					'max_oids'             => is_numeric($entry['maxOids'] ?? null) ? (int) $entry['maxOids'] : (is_numeric($existing['max_oids'] ?? null) ? (int) $existing['max_oids'] : 10),
					'device_threads'       => is_numeric($entry['deviceThreads'] ?? null) ? (int) $entry['deviceThreads'] : (is_numeric($existing['device_threads'] ?? null) ? (int) $existing['device_threads'] : 1),
					'data_queries'         => isset($entry['dataQueries']) && is_array($entry['dataQueries']) ? array_values($entry['dataQueries']) : ($existing['data_queries'] ?? []),
					'graph_templates'      => isset($entry['graphTemplates']) && is_array($entry['graphTemplates']) ? array_values($entry['graphTemplates']) : ($existing['graph_templates'] ?? []),
				]);

				$applied[] = $action;
			} elseif ($action->operation === PlanAction::DELETE) {
				$existing = $byKey[$action->naturalKey] ?? null;

				if ($existing !== null && is_numeric($existing['id'] ?? null)) {
					$repo->deleteDevice((int) $existing['id']);
					$applied[] = $action;
				}
			}
		}

		return [
			'applied'          => $applied,
			'needsReplication' => $applied !== [],
			'needsPollerCache' => $applied !== [],
		];
	}

	/**
	 * Derive the canonical natural key from a live database device row.
	 *
	 * @param array<string, mixed> $row Database device row.
	 * @return string External ID or hostname::description natural key.
	 */
	public static function naturalKeyFromRow(array $row): string {
		$ext = is_scalar($row['external_id'] ?? null) ? trim((string) $row['external_id']) : '';

		if ($ext !== '') {
			return $ext;
		}

		$host = is_scalar($row['hostname'] ?? null) ? trim((string) $row['hostname']) : '';
		$desc = is_scalar($row['description'] ?? null) ? trim((string) $row['description']) : '';

		return $host . '::' . $desc;
	}

	/**
	 * Derive the canonical natural key from a manifest device entry.
	 *
	 * @param array<array-key, mixed> $entry Manifest device entry.
	 * @return string Explicit key, hostname::description fallback, or empty string.
	 */
	public static function naturalKeyFromEntry(array $entry): string {
		if (is_string($entry['key'] ?? null) && trim($entry['key']) !== '') {
			return trim($entry['key']);
		}

		$host = is_string($entry['hostname'] ?? null) ? trim($entry['hostname']) : '';
		$desc = is_string($entry['description'] ?? null) ? trim($entry['description']) : '';

		return $host !== '' && $desc !== '' ? $host . '::' . $desc : '';
	}

	/**
	 * Build a lookup map of site name to numeric site ID.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @return array<string, int> Map of site name to ID.
	 */
	private function siteNameMap(StateRepository $repo): array {
		$map = [];

		foreach ($repo->getSites() as $row) {
			if (is_scalar($row['name'] ?? null) && is_numeric($row['id'] ?? null)) {
				$map[(string) $row['name']] = (int) $row['id'];
			}
		}

		return $map;
	}

	/**
	 * Build a lookup map of poller name to numeric poller ID.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @return array<string, int> Map of poller name to ID.
	 */
	private function pollerNameMap(StateRepository $repo): array {
		$map = [];

		foreach ($repo->getPollers() as $row) {
			if (is_scalar($row['name'] ?? null) && is_numeric($row['id'] ?? null)) {
				$map[(string) $row['name']] = (int) $row['id'];
			}
		}

		return $map;
	}

	/**
	 * Build a lookup map of host template name to numeric host template ID.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @return array<string, int> Map of host template name to ID.
	 */
	private function templateNameMap(StateRepository $repo): array {
		$map = [];

		foreach ($repo->getHostTemplates() as $row) {
			$map[$row['name']] = $row['id'];
		}

		return $map;
	}

	/**
	 * Compute masked field-level diffs between a live device row and a desired manifest entry.
	 *
	 * @param array<string, mixed> $existing Live database device row.
	 * @param array<array-key, mixed> $entry Desired device manifest entry.
	 * @param array<string, int> $sites Site name-to-ID map.
	 * @param array<string, int> $pollers Poller name-to-ID map.
	 * @param array<string, int> $tpls Host template name-to-ID map.
	 * @param SecretResolver $secrets Secret resolver for credential comparisons.
	 * @return array<string, array{from: mixed, to: mixed}> Map of changed fields.
	 */
	private function diffDevice(array $existing, array $entry, array $sites, array $pollers, array $tpls, SecretResolver $secrets): array {
		$diffs = [];

		foreach (['hostname' => 'hostname', 'description' => 'description', 'location' => 'location', 'notes' => 'notes', 'snmpUsername' => 'snmp_username', 'snmpAuthProtocol' => 'snmp_auth_protocol', 'snmpPrivProtocol' => 'snmp_priv_protocol'] as $yamlKey => $dbCol) {
			if (!array_key_exists($yamlKey, $entry)) {
				continue;
			}

			$cur = is_scalar($existing[$dbCol] ?? null) ? (string) $existing[$dbCol] : '';
			$des = is_scalar($entry[$yamlKey]) ? (string) $entry[$yamlKey] : '';

			if ($cur !== $des) {
				$diffs[$yamlKey] = ['from' => $cur, 'to' => $des];
			}
		}

		foreach (['snmpVersion' => 'snmp_version', 'snmpPort' => 'snmp_port', 'snmpTimeout' => 'snmp_timeout', 'maxOids' => 'max_oids', 'deviceThreads' => 'device_threads'] as $yamlKey => $dbCol) {
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

		if (isset($entry['site']) && is_string($entry['site'])) {
			$curId = is_numeric($existing['site_id'] ?? null) ? (int) $existing['site_id'] : 0;
			$desId = $sites[$entry['site']] ?? -1;

			if ($curId !== $desId) {
				$diffs['site'] = ['from' => $curId, 'to' => $entry['site']];
			}
		}

		if (isset($entry['poller']) && is_string($entry['poller'])) {
			$curId = is_numeric($existing['poller_id'] ?? null) ? (int) $existing['poller_id'] : 1;
			$desId = $pollers[$entry['poller']] ?? -1;

			if ($curId !== $desId) {
				$diffs['poller'] = ['from' => $curId, 'to' => $entry['poller']];
			}
		}

		if (isset($entry['hostTemplate']) && is_string($entry['hostTemplate'])) {
			$curId = is_numeric($existing['host_template_id'] ?? null) ? (int) $existing['host_template_id'] : 0;
			$desId = $tpls[$entry['hostTemplate']] ?? -1;

			if ($curId !== $desId) {
				$diffs['hostTemplate'] = ['from' => $curId, 'to' => $entry['hostTemplate']];
			}
		}

		foreach (['snmpCommunity' => 'snmp_community', 'snmpPassword' => 'snmp_password', 'snmpPrivPassphrase' => 'snmp_priv_passphrase'] as $yamlKey => $dbCol) {
			if (!array_key_exists($yamlKey, $entry) || !is_scalar($entry[$yamlKey])) {
				continue;
			}

			$cur      = is_scalar($existing[$dbCol] ?? null) ? (string) $existing[$dbCol] : '';
			$raw      = (string) $entry[$yamlKey];
			$resolved = $secrets->resolve($raw);

			if (!hash_equals($cur, $resolved)) {
				$diffs[$yamlKey] = [
					'from' => SecretPolicy::maskForDiff($cur),
					'to'   => SecretPolicy::maskForDiff($raw),
				];
			}
		}

		return $diffs;
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
