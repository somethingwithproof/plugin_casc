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

namespace Somethingwithproof\Casc\Repository;

use Somethingwithproof\Casc\Log;

/**
 * Production Cacti 1.2.x database and core API adapter.
 */
final class CactiStateRepository implements StateRepository {
	/**
	 * Construct a Cacti database repository adapter.
	 *
	 * @param string $libraryPath Absolute path to Cacti's lib/ directory.
	 * @param \PDO|false $connection Explicit PDO connection or false for Cacti's default connection.
	 */
	public function __construct(
		private readonly string $libraryPath,
		private readonly \PDO|false $connection = false,
	) {
	}

	/**
	 * Begin a Cacti database transaction.
	 *
	 * @return void
	 */
	public function beginTransaction(): void {
		db_begin_transaction($this->connection);
	}

	/**
	 * Commit the active Cacti database transaction.
	 *
	 * @return void
	 */
	public function commit(): void {
		db_commit_transaction($this->connection);
	}

	/**
	 * Roll back the active Cacti database transaction.
	 *
	 * @return void
	 */
	public function rollback(): void {
		db_rollback_transaction($this->connection);
	}

	/**
	 * Mark remote pollers for sync and invoke replicate_out() for each enabled remote collector.
	 *
	 * @param string $class Cacti replication class (default 'all').
	 * @return void
	 */
	public function triggerReplication(string $class = 'all'): void {
		db_execute_prepared("UPDATE poller SET requires_sync = 'on' WHERE id > 1", [], true, $this->connection);

		if (!function_exists('replicate_out') && is_file($this->libraryPath . '/poller.php')) {
			include_once($this->libraryPath . '/poller.php');
		}

		if (!function_exists('replicate_out')) {
			return;
		}

		$remotePollers = db_fetch_assoc_prepared('SELECT id FROM poller WHERE id > 1 AND disabled = ""', [], true, $this->connection);

		if (!is_array($remotePollers)) {
			return;
		}

		foreach ($remotePollers as $row) {
			if (is_array($row) && is_numeric($row['id'] ?? null)) {
				replicate_out((int) $row['id'], $class);
			}
		}
	}

	/**
	 * Update Cacti's poller cache for a single host or rebuild the entire poller cache.
	 *
	 * @param int|null $hostId Target host ID or null for full repopulation.
	 * @return void
	 */
	public function triggerPollerCacheUpdate(?int $hostId = null): void {
		if (!function_exists('update_poller_cache') && is_file($this->libraryPath . '/utility.php')) {
			include_once($this->libraryPath . '/utility.php');
		}

		if (!function_exists('update_poller_cache')) {
			return;
		}

		if ($hostId !== null && $hostId > 0) {
			$dataSources = db_fetch_assoc_prepared(
				'SELECT id FROM data_local WHERE host_id = ?',
				[$hostId],
				true,
				$this->connection
			);

			if (is_array($dataSources)) {
				foreach ($dataSources as $row) {
					if (is_array($row) && is_numeric($row['id'] ?? null)) {
						update_poller_cache((int) $row['id'], true);
					}
				}
			}

			return;
		}

		if (function_exists('repopulate_poller_cache')) {
			repopulate_poller_cache();
		}
	}

	/**
	 * Fetch all Cacti settings ordered by name.
	 *
	 * @return array<string, string>
	 */
	public function getSettings(): array {
		$rows = db_fetch_assoc_prepared('SELECT name, value FROM settings ORDER BY name', [], true, $this->connection);

		if (!is_array($rows)) {
			return [];
		}

		$out = [];

		foreach ($rows as $row) {
			if (is_array($row) && is_scalar($row['name'] ?? null)) {
				$out[(string) $row['name']] = is_scalar($row['value'] ?? null) ? (string) $row['value'] : '';
			}
		}

		return $out;
	}

	/**
	 * Upsert a Cacti setting using a prepared statement.
	 *
	 * @param string $name Setting name.
	 * @param string $value Setting value.
	 * @return void
	 */
	public function setSetting(string $name, string $value): void {
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', [$name, $value], true, $this->connection);
	}

	/**
	 * Delete a Cacti setting by name.
	 *
	 * @param string $name Setting name.
	 * @return void
	 */
	public function deleteSetting(string $name): void {
		db_execute_prepared('DELETE FROM settings WHERE name = ?', [$name], true, $this->connection);
	}

	/**
	 * Fetch all site rows ordered by name and ID.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getSites(): array {
		$rows = db_fetch_assoc_prepared(
			'SELECT id, name, address1, address2, city, state, postal_code, country, timezone, latitude, longitude, zoom, alternate_id, notes FROM sites ORDER BY name, id',
			[],
			true,
			$this->connection
		);

		return $this->normalizeRowList($rows);
	}

	/**
	 * Insert or update a site row in MariaDB and return its numeric ID.
	 *
	 * @param array<string, mixed> $site Site column map.
	 * @return int Saved site ID.
	 */
	public function saveSite(array $site): int {
		$id = isset($site['id']) && is_numeric($site['id']) ? (int) $site['id'] : 0;

		if ($id > 0) {
			db_execute_prepared(
				'UPDATE sites SET name = ?, address1 = ?, address2 = ?, city = ?, state = ?, postal_code = ?, country = ?, timezone = ?, latitude = ?, longitude = ?, zoom = ?, alternate_id = ?, notes = ? WHERE id = ?',
				[
					$this->str($site, 'name'),
					$this->str($site, 'address1'),
					$this->str($site, 'address2'),
					$this->str($site, 'city'),
					$this->nullableStr($site, 'state'),
					$this->str($site, 'postal_code'),
					$this->str($site, 'country'),
					$this->str($site, 'timezone'),
					$this->float($site, 'latitude', 0.0),
					$this->float($site, 'longitude', 0.0),
					$this->nullableInt($site, 'zoom'),
					$this->str($site, 'alternate_id'),
					$this->nullableStr($site, 'notes'),
					$id,
				],
				true,
				$this->connection
			);

			return $id;
		}

		db_execute_prepared(
			'INSERT INTO sites (name, address1, address2, city, state, postal_code, country, timezone, latitude, longitude, zoom, alternate_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$this->str($site, 'name'),
				$this->str($site, 'address1'),
				$this->str($site, 'address2'),
				$this->str($site, 'city'),
				$this->nullableStr($site, 'state'),
				$this->str($site, 'postal_code'),
				$this->str($site, 'country'),
				$this->str($site, 'timezone'),
				$this->float($site, 'latitude', 0.0),
				$this->float($site, 'longitude', 0.0),
				$this->nullableInt($site, 'zoom'),
				$this->str($site, 'alternate_id'),
				$this->nullableStr($site, 'notes'),
			],
			true,
			$this->connection
		);

		return (int) db_fetch_insert_id($this->connection);
	}

	/**
	 * Detach devices from a site and delete the site row.
	 *
	 * @param int $id Site ID.
	 * @return void
	 */
	public function deleteSite(int $id): void {
		db_execute_prepared('UPDATE host SET site_id = 0 WHERE site_id = ?', [$id], true, $this->connection);
		db_execute_prepared('DELETE FROM sites WHERE id = ?', [$id], true, $this->connection);
	}

	/**
	 * Fetch all poller rows ordered by ID.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getPollers(): array {
		$rows = db_fetch_assoc_prepared(
			'SELECT id, disabled, name, notes, timezone, hostname, dbdefault, dbhost, dbuser, dbpass, dbport, dbretries, dbssl, dbsslkey, dbsslcert, dbsslca, processes, threads, sync_interval FROM poller ORDER BY id',
			[],
			true,
			$this->connection
		);

		return $this->normalizeRowList($rows);
	}

	/**
	 * Insert or update a poller row and return its numeric ID.
	 *
	 * @param array<string, mixed> $poller Poller column map.
	 * @return int Saved poller ID.
	 */
	public function savePoller(array $poller): int {
		$id = isset($poller['id']) && is_numeric($poller['id']) ? (int) $poller['id'] : 0;

		if ($id > 0) {
			db_execute_prepared(
				'UPDATE poller SET disabled = ?, name = ?, notes = ?, timezone = ?, hostname = ?, dbdefault = ?, dbhost = ?, dbuser = ?, dbpass = ?, dbport = ?, dbretries = ?, dbssl = ?, processes = ?, threads = ?, sync_interval = ? WHERE id = ?',
				[
					$this->str($poller, 'disabled'),
					$this->str($poller, 'name'),
					$this->str($poller, 'notes'),
					$this->str($poller, 'timezone'),
					$this->str($poller, 'hostname'),
					$this->str($poller, 'dbdefault'),
					$this->str($poller, 'dbhost'),
					$this->str($poller, 'dbuser'),
					$this->str($poller, 'dbpass'),
					$this->int($poller, 'dbport', 3306),
					$this->int($poller, 'dbretries', 2),
					$this->str($poller, 'dbssl'),
					$this->int($poller, 'processes', 1),
					$this->int($poller, 'threads', 1),
					$this->int($poller, 'sync_interval', 7200),
					$id,
				],
				true,
				$this->connection
			);

			return $id;
		}

		db_execute_prepared(
			'INSERT INTO poller (disabled, name, notes, timezone, hostname, dbdefault, dbhost, dbuser, dbpass, dbport, dbretries, dbssl, processes, threads, sync_interval) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$this->str($poller, 'disabled'),
				$this->str($poller, 'name'),
				$this->str($poller, 'notes'),
				$this->str($poller, 'timezone'),
				$this->str($poller, 'hostname'),
				$this->str($poller, 'dbdefault'),
				$this->str($poller, 'dbhost'),
				$this->str($poller, 'dbuser'),
				$this->str($poller, 'dbpass'),
				$this->int($poller, 'dbport', 3306),
				$this->int($poller, 'dbretries', 2),
				$this->str($poller, 'dbssl'),
				$this->int($poller, 'processes', 1),
				$this->int($poller, 'threads', 1),
				$this->int($poller, 'sync_interval', 7200),
			],
			true,
			$this->connection
		);

		return (int) db_fetch_insert_id($this->connection);
	}

	/**
	 * Delete a remote poller while forbidding deletion of primary poller id=1.
	 *
	 * @param int $id Poller ID.
	 * @return void
	 */
	public function deletePoller(int $id): void {
		if ($id === 1) {
			throw new \RuntimeException('Primary poller (id=1) cannot be deleted');
		}

		db_execute_prepared('DELETE FROM poller WHERE id = ?', [$id], true, $this->connection);
	}

	/**
	 * Fetch all host templates ordered by name.
	 *
	 * @return list<array{id: int, hash: string, name: string, class: string}>
	 */
	public function getHostTemplates(): array {
		$rows = db_fetch_assoc_prepared('SELECT id, hash, name, class FROM host_template ORDER BY name', [], true, $this->connection);
		$out  = [];

		foreach ($this->normalizeRowList($rows) as $row) {
			$out[] = [
				'id'    => $this->int($row, 'id', 0),
				'hash'  => $this->str($row, 'hash'),
				'name'  => $this->str($row, 'name'),
				'class' => $this->str($row, 'class'),
			];
		}

		return $out;
	}

	/**
	 * Fetch all SNMP data queries ordered by name.
	 *
	 * @return list<array{id: int, hash: string, name: string}>
	 */
	public function getDataQueries(): array {
		$rows = db_fetch_assoc_prepared('SELECT id, hash, name FROM snmp_query ORDER BY name', [], true, $this->connection);
		$out  = [];

		foreach ($this->normalizeRowList($rows) as $row) {
			$out[] = [
				'id'   => $this->int($row, 'id', 0),
				'hash' => $this->str($row, 'hash'),
				'name' => $this->str($row, 'name'),
			];
		}

		return $out;
	}

	/**
	 * Fetch all graph templates ordered by name.
	 *
	 * @return list<array{id: int, hash: string, name: string}>
	 */
	public function getGraphTemplates(): array {
		$rows = db_fetch_assoc_prepared('SELECT id, hash, name FROM graph_templates ORDER BY name', [], true, $this->connection);
		$out  = [];

		foreach ($this->normalizeRowList($rows) as $row) {
			$out[] = [
				'id'   => $this->int($row, 'id', 0),
				'hash' => $this->str($row, 'hash'),
				'name' => $this->str($row, 'name'),
			];
		}

		return $out;
	}

	/**
	 * Import canonical Cacti template XML via core import_xml_data().
	 *
	 * @param string $xml Validated <cacti> template XML string.
	 * @param string $profileName Data source profile name.
	 * @param bool $replaceSuggestedValues Whether to overwrite suggested values.
	 * @return bool True when import succeeds.
	 */
	public function importTemplateXml(string $xml, string $profileName, bool $replaceSuggestedValues): bool {
		if (!function_exists('import_xml_data') && is_file($this->libraryPath . '/import.php')) {
			include_once($this->libraryPath . '/import.php');
		}

		if (!function_exists('import_xml_data')) {
			return false;
		}

		$profileId = $this->resolveProfileId($profileName);
		$payload   = $xml;
		$result    = import_xml_data($payload, false, $profileId, false, $replaceSuggestedValues);

		return $result !== false;
	}

	/**
	 * Import a signed Cacti .xml.gz package via core import_package().
	 *
	 * @param string $filePath Verified package file path.
	 * @param string $profileName Data source profile name.
	 * @param bool $replaceSuggestedValues Whether to overwrite suggested values.
	 * @return bool True when import succeeds.
	 */
	public function importTemplatePackage(string $filePath, string $profileName, bool $replaceSuggestedValues): bool {
		if (!function_exists('import_package') && is_file($this->libraryPath . '/import.php')) {
			include_once($this->libraryPath . '/import.php');
		}

		if (!function_exists('import_package')) {
			return false;
		}

		$profileId = $this->resolveProfileId($profileName);
		$result    = import_package($filePath, $profileId, false, $replaceSuggestedValues);

		return $result !== false;
	}

	/**
	 * Export a template as XML via core get_item_xml().
	 *
	 * @param string $type Cacti template type.
	 * @param int $id Template numeric ID.
	 * @return string|null Exported XML string or null.
	 */
	public function exportTemplateXml(string $type, int $id): ?string {
		if (!function_exists('get_item_xml') && is_file($this->libraryPath . '/export.php')) {
			include_once($this->libraryPath . '/export.php');
		}

		if (!function_exists('get_item_xml')) {
			return null;
		}

		$xml = get_item_xml($type, $id, true);

		return is_string($xml) && $xml !== '' ? $xml : null;
	}

	/**
	 * Fetch all non-deleted devices with associated data query and graph template names
	 * in O(1) SQL round-trips (3 bulk queries instead of 2N+1 per-host queries).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getDevices(): array {
		$rows = db_fetch_assoc_prepared(
			'SELECT id, poller_id, site_id, host_template_id, description, hostname, location, notes, external_id, snmp_community, snmp_version, snmp_username, snmp_password, snmp_auth_protocol, snmp_priv_passphrase, snmp_priv_protocol, snmp_context, snmp_engine_id, snmp_port, snmp_timeout, availability_method, ping_method, ping_port, ping_timeout, ping_retries, max_oids, bulk_walk_size, device_threads, disabled FROM host WHERE deleted = "" ORDER BY id',
			[],
			true,
			$this->connection
		);

		$devices = $this->normalizeRowList($rows);

		if ($devices === []) {
			return [];
		}

		$dqRows = db_fetch_assoc_prepared(
			'SELECT hsq.host_id, sq.name FROM host_snmp_query AS hsq INNER JOIN snmp_query AS sq ON sq.id = hsq.snmp_query_id ORDER BY hsq.host_id, sq.name',
			[],
			true,
			$this->connection
		);

		$gtRows = db_fetch_assoc_prepared(
			'SELECT hg.host_id, gt.name FROM host_graph AS hg INNER JOIN graph_templates AS gt ON gt.id = hg.graph_template_id ORDER BY hg.host_id, gt.name',
			[],
			true,
			$this->connection
		);

		$dqByHost = $this->groupColumnByIntKey($dqRows, 'host_id', 'name');
		$gtByHost = $this->groupColumnByIntKey($gtRows, 'host_id', 'name');

		foreach ($devices as $index => $device) {
			$hostId                            = $this->int($device, 'id', 0);
			$devices[$index]['data_queries']    = $dqByHost[$hostId] ?? [];
			$devices[$index]['graph_templates'] = $gtByHost[$hostId] ?? [];
		}

		return $devices;
	}

	/**
	 * Persist a device through Cacti's api_device_save() and sync query/template links.
	 *
	 * @param array<string, mixed> $device Device column map.
	 * @return int Saved host ID.
	 */
	public function saveDevice(array $device): int {
		if (!function_exists('api_device_save') && is_file($this->libraryPath . '/api_device.php')) {
			include_once($this->libraryPath . '/api_device.php');
		}

		$id = $this->int($device, 'id', 0);

		if (function_exists('api_device_save')) {
			$savedId = api_device_save(
				$id,
				$this->int($device, 'host_template_id', 0),
				$this->str($device, 'description'),
				$this->str($device, 'hostname'),
				$this->str($device, 'snmp_community'),
				$this->int($device, 'snmp_version', 2),
				$this->str($device, 'snmp_username'),
				$this->str($device, 'snmp_password'),
				$this->int($device, 'snmp_port', 161),
				$this->int($device, 'snmp_timeout', 500),
				$this->str($device, 'disabled'),
				$this->int($device, 'availability_method', 2),
				$this->int($device, 'ping_method', 2),
				$this->int($device, 'ping_port', 23),
				$this->int($device, 'ping_timeout', 400),
				$this->int($device, 'ping_retries', 1),
				$this->str($device, 'notes'),
				$this->str($device, 'snmp_auth_protocol'),
				$this->str($device, 'snmp_priv_passphrase'),
				$this->str($device, 'snmp_priv_protocol'),
				$this->str($device, 'snmp_context'),
				$this->str($device, 'snmp_engine_id'),
				$this->int($device, 'max_oids', 10),
				$this->int($device, 'device_threads', 1),
				$this->int($device, 'poller_id', 1),
				$this->int($device, 'site_id', 0),
				$this->str($device, 'external_id'),
				$this->str($device, 'location'),
				$this->int($device, 'bulk_walk_size', -1)
			);

			$hostId = is_numeric($savedId) ? (int) $savedId : $id;
		} else {
			throw new \RuntimeException('Cacti api_device_save() is unavailable');
		}

		if ($hostId > 0) {
			$this->syncDeviceAssociations($hostId, $device);
		}

		return $hostId;
	}

	/**
	 * Remove a device via Cacti's api_device_remove().
	 *
	 * @param int $id Device host ID.
	 * @return void
	 */
	public function deleteDevice(int $id): void {
		if (!function_exists('api_device_remove') && is_file($this->libraryPath . '/api_device.php')) {
			include_once($this->libraryPath . '/api_device.php');
		}

		if (function_exists('api_device_remove')) {
			api_device_remove($id);
		}
	}

	/**
	 * Fetch all graph trees and reconstruct their nested item hierarchies in
	 * O(1) SQL round-trips (2 bulk queries instead of T+1 per-tree queries).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getTrees(): array {
		$rows = db_fetch_assoc_prepared(
			'SELECT id, name, enabled, sort_type FROM graph_tree ORDER BY name, id',
			[],
			true,
			$this->connection
		);

		$trees = $this->normalizeRowList($rows);

		if ($trees === []) {
			return [];
		}

		$itemRows = db_fetch_assoc_prepared(
			'SELECT gti.graph_tree_id, gti.id, gti.parent, gti.position, gti.title, gti.host_id, gti.site_id, gti.local_graph_id, h.external_id AS host_external_id, h.hostname AS host_hostname, h.description AS host_description, s.name AS site_name FROM graph_tree_items AS gti LEFT JOIN host AS h ON h.id = gti.host_id LEFT JOIN sites AS s ON s.id = gti.site_id ORDER BY gti.graph_tree_id, gti.parent, gti.position, gti.id',
			[],
			true,
			$this->connection
		);

		/** @var array<int, array<int, list<array<string, mixed>>>> $byTreeParent */
		$byTreeParent = [];

		foreach ($this->normalizeRowList($itemRows) as $row) {
			$treeId                            = $this->int($row, 'graph_tree_id', 0);
			$parent                            = $this->int($row, 'parent', 0);
			$byTreeParent[$treeId][$parent][] = $row;
		}

		foreach ($trees as $idx => $tree) {
			$treeId               = $this->int($tree, 'id', 0);
			$trees[$idx]['items'] = isset($byTreeParent[$treeId]) ? $this->buildTreeBranch($byTreeParent[$treeId], 0) : [];
		}

		return $trees;
	}

	/**
	 * Save a graph tree and replace its tree item hierarchy.
	 *
	 * @param array<string, mixed> $tree Graph tree payload.
	 * @return int Saved tree ID.
	 */
	public function saveTree(array $tree): int {
		$id = $this->int($tree, 'id', 0);

		if ($id > 0) {
			db_execute_prepared(
				'UPDATE graph_tree SET name = ?, enabled = ?, sort_type = ? WHERE id = ?',
				[
					$this->str($tree, 'name'),
					$this->str($tree, 'enabled', 'on'),
					$this->int($tree, 'sort_type', 1),
					$id,
				],
				true,
				$this->connection
			);
		} else {
			db_execute_prepared(
				'INSERT INTO graph_tree (name, enabled, sort_type) VALUES (?, ?, ?)',
				[
					$this->str($tree, 'name'),
					$this->str($tree, 'enabled', 'on'),
					$this->int($tree, 'sort_type', 1),
				],
				true,
				$this->connection
			);

			$id = (int) db_fetch_insert_id($this->connection);
		}

		if ($id > 0 && isset($tree['items']) && is_array($tree['items'])) {
			db_execute_prepared('DELETE FROM graph_tree_items WHERE graph_tree_id = ?', [$id], true, $this->connection);
			$this->insertTreeItems($id, 0, array_values($tree['items']));
		}

		return $id;
	}

	/**
	 * Delete a graph tree and all of its items.
	 *
	 * @param int $id Graph tree ID.
	 * @return void
	 */
	public function deleteTree(int $id): void {
		db_execute_prepared('DELETE FROM graph_tree_items WHERE graph_tree_id = ?', [$id], true, $this->connection);
		db_execute_prepared('DELETE FROM graph_tree WHERE id = ?', [$id], true, $this->connection);
	}

	/**
	 * Fetch all installed plugins from plugin_config.
	 *
	 * @return list<array{directory: string, name: string, status: int, version: string}>
	 */
	public function getPlugins(): array {
		$rows = db_fetch_assoc_prepared(
			'SELECT directory, name, status, version FROM plugin_config ORDER BY directory',
			[],
			true,
			$this->connection
		);

		$out = [];

		foreach ($this->normalizeRowList($rows) as $row) {
			$out[] = [
				'directory' => $this->str($row, 'directory'),
				'name'      => $this->str($row, 'name'),
				'status'    => $this->int($row, 'status', 0),
				'version'   => $this->str($row, 'version'),
			];
		}

		return $out;
	}

	/**
	 * Install/enable or disable a Cacti plugin using core plugin APIs.
	 *
	 * @param string $slug Plugin directory slug.
	 * @param bool $enabled Target enabled state.
	 * @return void
	 */
	public function setPluginEnabled(string $slug, bool $enabled): void {
		if (!function_exists('api_plugin_enable') && is_file($this->libraryPath . '/plugins.php')) {
			include_once($this->libraryPath . '/plugins.php');
		}

		if ($enabled) {
			if (function_exists('api_plugin_installed') && !api_plugin_installed($slug) && function_exists('api_plugin_install')) {
				api_plugin_install($slug);
			}

			if (function_exists('api_plugin_enable')) {
				api_plugin_enable($slug);
			}

			return;
		}

		if (function_exists('api_plugin_disable')) {
			api_plugin_disable($slug);
		}
	}

	/**
	 * Insert a zero-secret audit row into plugin_casc_audit.
	 *
	 * @param array<string, mixed> $entry Audit column map.
	 * @return int Inserted audit row ID.
	 */
	public function recordAudit(array $entry): int {
		db_execute_prepared(
			'INSERT INTO plugin_casc_audit (occurred_at, actor, source, action, plan_hash, status, created_count, updated_count, deleted_count, summary_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$this->int($entry, 'occurred_at', time()),
				substr($this->str($entry, 'actor', 'system'), 0, 64),
				substr($this->str($entry, 'source', 'cli'), 0, 16),
				substr($this->str($entry, 'action', 'apply'), 0, 16),
				substr($this->str($entry, 'plan_hash'), 0, 64),
				substr($this->str($entry, 'status', 'succeeded'), 0, 16),
				$this->int($entry, 'created_count', 0),
				$this->int($entry, 'updated_count', 0),
				$this->int($entry, 'deleted_count', 0),
				$this->str($entry, 'summary_json', '{}'),
			],
			true,
			$this->connection
		);

		return (int) db_fetch_insert_id($this->connection);
	}

	/**
	 * Fetch recent rows from plugin_casc_audit ordered newest first.
	 *
	 * @param int $limit Maximum rows to return (clamped to 1..500).
	 * @return list<array<string, mixed>>
	 */
	public function getRecentAudits(int $limit = 50): array {
		$limit = max(1, min(500, $limit));
		$rows  = db_fetch_assoc_prepared(
			'SELECT id, occurred_at, actor, source, action, plan_hash, status, created_count, updated_count, deleted_count, summary_json FROM plugin_casc_audit ORDER BY occurred_at DESC, id DESC LIMIT ' . $limit,
			[],
			true,
			$this->connection
		);

		return $this->normalizeRowList($rows);
	}

	/**
	 * Record a computed plan hash in plugin_casc_plan.
	 *
	 * @param string $planHash Canonical SHA-256 plan hash.
	 * @param string $actor Operator identity.
	 * @param string $manifestSha256 Canonical manifest SHA-256 hash.
	 * @param int $actionCount Number of planned actions.
	 * @param int $timestamp Unix epoch timestamp.
	 * @return void
	 */
	public function recordPlan(string $planHash, string $actor, string $manifestSha256, int $actionCount, int $timestamp): void {
		db_execute_prepared(
			'REPLACE INTO plugin_casc_plan (plan_hash, created_at, actor, manifest_sha256, action_count) VALUES (?, ?, ?, ?, ?)',
			[
				substr($planHash, 0, 64),
				$timestamp,
				substr($actor, 0, 64),
				substr($manifestSha256, 0, 64),
				$actionCount,
			],
			true,
			$this->connection
		);
	}

	/**
	 * Fetch the most recently persisted plan summary from plugin_casc_plan.
	 *
	 * @return array{plan_hash: string, created_at: int, actor: string, manifest_sha256: string, action_count: int}|null
	 */
	public function getLatestPlan(): ?array {
		$rows = db_fetch_assoc_prepared(
			'SELECT plan_hash, created_at, actor, manifest_sha256, action_count FROM plugin_casc_plan ORDER BY created_at DESC LIMIT 1',
			[],
			true,
			$this->connection
		);

		$normalized = $this->normalizeRowList($rows);

		if ($normalized === []) {
			return null;
		}

		$row = $normalized[0];

		return [
			'plan_hash'       => $this->str($row, 'plan_hash'),
			'created_at'      => $this->int($row, 'created_at', 0),
			'actor'           => $this->str($row, 'actor'),
			'manifest_sha256' => $this->str($row, 'manifest_sha256'),
			'action_count'    => $this->int($row, 'action_count', 0),
		];
	}

	/**
	 * Resolve a data source profile name to its numeric ID, falling back to the default profile.
	 *
	 * @param string $profileName Data source profile name.
	 * @return int Resolved profile ID.
	 */
	private function resolveProfileId(string $profileName): int {
		if ($profileName !== '') {
			/** @var mixed $id */
			$id = db_fetch_cell_prepared(
				'SELECT id FROM data_source_profiles WHERE name = ?',
				[$profileName],
				'',
				true,
				$this->connection
			);

			if (is_numeric($id) && (int) $id > 0) {
				return (int) $id;
			}
		}

		/** @var mixed $defaultId */
		$defaultId = db_fetch_cell_prepared(
			"SELECT id FROM data_source_profiles WHERE `default` = 'on' ORDER BY id LIMIT 1",
			[],
			'',
			true,
			$this->connection
		);

		return is_numeric($defaultId) && (int) $defaultId > 0 ? (int) $defaultId : 1;
	}

	/**
	 * Synchronize host_snmp_query and host_graph associations for a saved device.
	 *
	 * @param int $hostId Saved host ID.
	 * @param array<string, mixed> $device Device payload containing data_queries and graph_templates.
	 * @return void
	 */
	private function syncDeviceAssociations(int $hostId, array $device): void {
		if (isset($device['data_queries']) && is_array($device['data_queries']) && $device['data_queries'] !== []) {
			$dqMap = [];

			foreach ($this->getDataQueries() as $dq) {
				$dqMap[$dq['name']] = $dq['id'];
			}

			foreach ($device['data_queries'] as $name) {
				if (is_string($name) && isset($dqMap[$name])) {
					db_execute_prepared(
						'REPLACE INTO host_snmp_query (host_id, snmp_query_id, sort_field, title_format, reindex_method) VALUES (?, ?, "", "", 1)',
						[$hostId, $dqMap[$name]],
						true,
						$this->connection
					);
				}
			}
		}

		if (isset($device['graph_templates']) && is_array($device['graph_templates']) && $device['graph_templates'] !== []) {
			$gtMap = [];

			foreach ($this->getGraphTemplates() as $gt) {
				$gtMap[$gt['name']] = $gt['id'];
			}

			foreach ($device['graph_templates'] as $name) {
				if (is_string($name) && isset($gtMap[$name])) {
					db_execute_prepared(
						'REPLACE INTO host_graph (host_id, graph_template_id) VALUES (?, ?)',
						[$hostId, $gtMap[$name]],
						true,
						$this->connection
					);
				}
			}
		}
	}

	/**
	 * Group string values from a column by an integer key column across fetched rows.
	 *
	 * @param mixed $rows Raw result from db_fetch_assoc_prepared().
	 * @param string $keyCol Integer foreign-key column name.
	 * @param string $valCol String value column name.
	 * @return array<int, list<string>>
	 */
	private function groupColumnByIntKey(mixed $rows, string $keyCol, string $valCol): array {
		$grouped = [];

		foreach ($this->normalizeRowList($rows) as $row) {
			$id = $this->int($row, $keyCol, 0);

			if ($id > 0 && is_scalar($row[$valCol] ?? null)) {
				$grouped[$id][] = (string) $row[$valCol];
			}
		}

		return $grouped;
	}

	/**
	 * Recursively assemble nested tree items for a given parent item ID.
	 *
	 * @param array<int, list<array<string, mixed>>> $byParent Tree item rows grouped by parent ID.
	 * @param int $parentId Parent item ID (0 for tree root).
	 * @return list<array<string, mixed>>
	 */
	private function buildTreeBranch(array $byParent, int $parentId): array {
		$out = [];

		foreach ($byParent[$parentId] ?? [] as $row) {
			$hostId  = $this->int($row, 'host_id', 0);
			$siteId  = $this->int($row, 'site_id', 0);
			$graphId = $this->int($row, 'local_graph_id', 0);
			$itemId  = $this->int($row, 'id', 0);

			if ($hostId > 0) {
				$ext = $this->str($row, 'host_external_id');
				$key = $ext !== '' ? $ext : $this->str($row, 'host_hostname') . '::' . $this->str($row, 'host_description');
				$out[] = ['device' => $key];

				continue;
			}

			if ($siteId > 0) {
				$out[] = ['site' => $this->str($row, 'site_name')];

				continue;
			}

			if ($graphId > 0) {
				$out[] = ['graph' => $graphId];

				continue;
			}

			$entry    = ['header' => $this->str($row, 'title')];
			$children = $this->buildTreeBranch($byParent, $itemId);

			if ($children !== []) {
				$entry['children'] = $children;
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Recursively insert declarative tree item branches into graph_tree_items.
	 *
	 * @param int $treeId Owning graph tree ID.
	 * @param int $parentId Parent item ID (0 for root level).
	 * @param list<mixed> $items Declarative tree items to insert.
	 * @return void
	 */
	private function insertTreeItems(int $treeId, int $parentId, array $items): void {
		$position = 1;

		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}

			if (isset($item['header']) && is_string($item['header'])) {
				db_execute_prepared(
					'INSERT INTO graph_tree_items (parent, position, graph_tree_id, title, host_id, site_id, local_graph_id) VALUES (?, ?, ?, ?, 0, 0, 0)',
					[$parentId, $position++, $treeId, $item['header']],
					true,
					$this->connection
				);

				$newId = (int) db_fetch_insert_id($this->connection);

				if ($newId > 0 && isset($item['children']) && is_array($item['children'])) {
					$this->insertTreeItems($treeId, $newId, array_values($item['children']));
				}

				continue;
			}

			if (isset($item['device']) && is_string($item['device'])) {
				$hostId = $this->resolveDeviceIdByNaturalKey($item['device']);

				if ($hostId > 0) {
					db_execute_prepared(
						'INSERT INTO graph_tree_items (parent, position, graph_tree_id, title, host_id, site_id, local_graph_id) VALUES (?, ?, ?, "", ?, 0, 0)',
						[$parentId, $position++, $treeId, $hostId],
						true,
						$this->connection
					);
				} else {
					Log::warning(sprintf('Tree item skipped: unresolved device "%s"', $item['device']));
				}

				continue;
			}

			if (isset($item['site']) && is_string($item['site'])) {
				$siteId = (int) db_fetch_cell_prepared('SELECT id FROM sites WHERE name = ? LIMIT 1', [$item['site']], '', true, $this->connection);

				if ($siteId > 0) {
					db_execute_prepared(
						'INSERT INTO graph_tree_items (parent, position, graph_tree_id, title, host_id, site_id, local_graph_id) VALUES (?, ?, ?, "", 0, ?, 0)',
						[$parentId, $position++, $treeId, $siteId],
						true,
						$this->connection
					);
				}
			}
		}
	}

	/**
	 * Resolve a device natural key (external_id or hostname::description) to its host ID.
	 *
	 * @param string $key Device natural key.
	 * @return int Matching host ID or 0 if not found.
	 */
	private function resolveDeviceIdByNaturalKey(string $key): int {
		/** @var mixed $id */
		$id = db_fetch_cell_prepared(
			'SELECT id FROM host WHERE external_id = ? AND deleted = "" LIMIT 1',
			[$key],
			'',
			true,
			$this->connection
		);

		if (is_numeric($id) && (int) $id > 0) {
			return (int) $id;
		}

		if (str_contains($key, '::')) {
			[$hostname, $description] = explode('::', $key, 2);
			/** @var mixed $id */
			$id = db_fetch_cell_prepared(
				'SELECT id FROM host WHERE hostname = ? AND description = ? AND deleted = "" LIMIT 1',
				[$hostname, $description],
				'',
				true,
				$this->connection
			);

			if (is_numeric($id) && (int) $id > 0) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * Normalize a database fetch result into a list of string-keyed row maps.
	 *
	 * @param mixed $rows Raw result from db_fetch_assoc_prepared().
	 * @return list<array<string, mixed>>
	 */
	private function normalizeRowList(mixed $rows): array {
		if (!is_array($rows)) {
			return [];
		}

		$out = [];

		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$clean = [];

			foreach ($row as $k => $v) {
				$clean[(string) $k] = $v;
			}

			$out[] = $clean;
		}

		return $out;
	}

	/**
	 * Read a scalar string column from a row with a fallback default.
	 *
	 * @param array<string, mixed> $row Database or input row.
	 * @param string $key Column key.
	 * @param string $default Fallback value when absent or non-scalar.
	 * @return string String column value.
	 */
	private function str(array $row, string $key, string $default = ''): string {
		return is_scalar($row[$key] ?? null) ? (string) $row[$key] : $default;
	}

	/**
	 * Read a nullable string column from a row.
	 *
	 * @param array<string, mixed> $row Database or input row.
	 * @param string $key Column key.
	 * @return string|null String value or null.
	 */
	private function nullableStr(array $row, string $key): ?string {
		return is_scalar($row[$key] ?? null) ? (string) $row[$key] : null;
	}

	/**
	 * Read an integer column from a row with a fallback default.
	 *
	 * @param array<string, mixed> $row Database or input row.
	 * @param string $key Column key.
	 * @param int $default Fallback value when absent or non-numeric.
	 * @return int Integer column value.
	 */
	private function int(array $row, string $key, int $default): int {
		return is_numeric($row[$key] ?? null) ? (int) $row[$key] : $default;
	}

	/**
	 * Read a nullable integer column from a row.
	 *
	 * @param array<string, mixed> $row Database or input row.
	 * @param string $key Column key.
	 * @return int|null Integer value or null.
	 */
	private function nullableInt(array $row, string $key): ?int {
		return is_numeric($row[$key] ?? null) ? (int) $row[$key] : null;
	}

	/**
	 * Read a floating-point column from a row with a fallback default.
	 *
	 * @param array<string, mixed> $row Database or input row.
	 * @param string $key Column key.
	 * @param float $default Fallback value when absent or non-numeric.
	 * @return float Float column value.
	 */
	private function float(array $row, string $key, float $default): float {
		return is_numeric($row[$key] ?? null) ? (float) $row[$key] : $default;
	}
}
