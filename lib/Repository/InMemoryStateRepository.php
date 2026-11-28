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

/**
 * Deterministic in-memory StateRepository for unit and security tests.
 * Supports snapshot-based rollback and records replication/poller-cache hooks.
 */
final class InMemoryStateRepository implements StateRepository {
	/** @var array<string, string> */
	public array $settings = [];

	/** @var array<int, array<string, mixed>> */
	public array $sites = [];

	/** @var array<int, array<string, mixed>> */
	public array $pollers = [];

	/** @var list<array{id: int, hash: string, name: string, class: string}> */
	public array $hostTemplates = [];

	/** @var list<array{id: int, hash: string, name: string}> */
	public array $dataQueries = [];

	/** @var list<array{id: int, hash: string, name: string}> */
	public array $graphTemplates = [];

	/** @var array<int, array<string, mixed>> */
	public array $devices = [];

	/** @var array<int, array<string, mixed>> */
	public array $trees = [];

	/** @var array<string, array{directory: string, name: string, status: int, version: string}> */
	public array $plugins = [];

	/** @var list<array<string, mixed>> */
	public array $audits = [];

	/** @var array<string, array<string, mixed>> */
	public array $plans = [];

	/** @var list<string> */
	public array $replications = [];

	/** @var list<int|null> */
	public array $pollerCacheUpdates = [];

	/** @var list<array{kind: string, payload: string, profile: string, replaceSuggestedValues: bool}> */
	public array $importedTemplates = [];

	/** @var array<string, string> Map of "type:id" -> XML */
	public array $templateExports = [];

	public ?string $failOnOperation = null;

	private int $nextSiteId   = 1;
	private int $nextPollerId = 2;
	private int $nextDeviceId = 1;
	private int $nextTreeId   = 1;
	private int $nextAuditId  = 1;

	/** @var array<string, mixed>|null */
	private ?array $snapshot = null;

	/**
	 * Initialize the in-memory repository seeded with Cacti's primary poller id=1.
	 */
	public function __construct() {
		// Seed primary poller id=1 as Cacti installs always have one.
		$this->pollers[1] = [
			'id'            => 1,
			'name'          => 'Main Poller',
			'hostname'      => 'localhost',
			'disabled'      => '',
			'notes'         => '',
			'timezone'      => '',
			'dbdefault'     => 'cacti',
			'dbhost'        => 'localhost',
			'dbuser'        => 'cactiuser',
			'dbpass'        => '',
			'dbport'        => 3306,
			'dbretries'     => 2,
			'dbssl'         => '',
			'processes'     => 1,
			'threads'       => 1,
			'sync_interval' => 7200,
			'requires_sync' => '',
		];
	}

	/**
	 * Snapshot current in-memory collections to support rollback.
	 *
	 * @return void
	 */
	public function beginTransaction(): void {
		$this->snapshot = [
			'settings'     => $this->settings,
			'sites'        => $this->sites,
			'pollers'      => $this->pollers,
			'devices'      => $this->devices,
			'trees'        => $this->trees,
			'plugins'      => $this->plugins,
			'imported'     => $this->importedTemplates,
			'nextSiteId'   => $this->nextSiteId,
			'nextPollerId' => $this->nextPollerId,
			'nextDeviceId' => $this->nextDeviceId,
			'nextTreeId'   => $this->nextTreeId,
		];
	}

	/**
	 * Discard the transaction snapshot, committing mutations.
	 *
	 * @return void
	 */
	public function commit(): void {
		$this->snapshot = null;
	}

	/**
	 * Restore all in-memory collections from the active transaction snapshot.
	 *
	 * @return void
	 */
	public function rollback(): void {
		if ($this->snapshot === null) {
			return;
		}

		/** @var array<string, string> $settings */
		$settings = $this->snapshot['settings'];
		/** @var array<int, array<string, mixed>> $sites */
		$sites = $this->snapshot['sites'];
		/** @var array<int, array<string, mixed>> $pollers */
		$pollers = $this->snapshot['pollers'];
		/** @var array<int, array<string, mixed>> $devices */
		$devices = $this->snapshot['devices'];
		/** @var array<int, array<string, mixed>> $trees */
		$trees = $this->snapshot['trees'];
		/** @var array<string, array{directory: string, name: string, status: int, version: string}> $plugins */
		$plugins = $this->snapshot['plugins'];
		/** @var list<array{kind: string, payload: string, profile: string, replaceSuggestedValues: bool}> $imported */
		$imported = $this->snapshot['imported'];

		$this->settings          = $settings;
		$this->sites             = $sites;
		$this->pollers           = $pollers;
		$this->devices           = $devices;
		$this->trees             = $trees;
		$this->plugins           = $plugins;
		$this->importedTemplates = $imported;
		$this->nextSiteId        = is_int($this->snapshot['nextSiteId']) ? $this->snapshot['nextSiteId'] : 1;
		$this->nextPollerId      = is_int($this->snapshot['nextPollerId']) ? $this->snapshot['nextPollerId'] : 2;
		$this->nextDeviceId      = is_int($this->snapshot['nextDeviceId']) ? $this->snapshot['nextDeviceId'] : 1;
		$this->nextTreeId        = is_int($this->snapshot['nextTreeId']) ? $this->snapshot['nextTreeId'] : 1;
		$this->snapshot          = null;
	}

	/**
	 * Record a replication trigger and mark remote pollers as requiring sync.
	 *
	 * @param string $class Cacti replication class.
	 * @return void
	 */
	public function triggerReplication(string $class = 'all'): void {
		$this->replications[] = $class;

		foreach ($this->pollers as $id => $poller) {
			if ($id > 1) {
				$this->pollers[$id]['requires_sync'] = 'on';
			}
		}
	}

	/**
	 * Record a poller cache update trigger for assertions.
	 *
	 * @param int|null $hostId Target host ID or null for global rebuild.
	 * @return void
	 */
	public function triggerPollerCacheUpdate(?int $hostId = null): void {
		$this->pollerCacheUpdates[] = $hostId;
	}

	/**
	 * Return all in-memory settings.
	 *
	 * @return array<string, string>
	 */
	public function getSettings(): array {
		return $this->settings;
	}

	/**
	 * Store a setting value in memory.
	 *
	 * @param string $name Setting name.
	 * @param string $value Setting value.
	 * @return void
	 */
	public function setSetting(string $name, string $value): void {
		$this->maybeFail('setSetting');
		$this->settings[$name] = $value;
	}

	/**
	 * Delete a setting from memory.
	 *
	 * @param string $name Setting name.
	 * @return void
	 */
	public function deleteSetting(string $name): void {
		$this->maybeFail('deleteSetting');
		unset($this->settings[$name]);
	}

	/**
	 * Return all stored sites as a list.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getSites(): array {
		return array_values($this->sites);
	}

	/**
	 * Insert or update a site in memory and return its ID.
	 *
	 * @param array<string, mixed> $site Site payload.
	 * @return int Saved site ID.
	 */
	public function saveSite(array $site): int {
		$this->maybeFail('saveSite');
		$id = isset($site['id']) && is_int($site['id']) && $site['id'] > 0 ? $site['id'] : $this->nextSiteId++;

		if ($id >= $this->nextSiteId) {
			$this->nextSiteId = $id + 1;
		}

		$site['id']       = $id;
		$this->sites[$id] = $site;

		return $id;
	}

	/**
	 * Delete a site from memory by ID.
	 *
	 * @param int $id Site ID.
	 * @return void
	 */
	public function deleteSite(int $id): void {
		$this->maybeFail('deleteSite');
		unset($this->sites[$id]);
	}

	/**
	 * Return all stored pollers as a list.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getPollers(): array {
		return array_values($this->pollers);
	}

	/**
	 * Insert or update a poller in memory and return its ID.
	 *
	 * @param array<string, mixed> $poller Poller payload.
	 * @return int Saved poller ID.
	 */
	public function savePoller(array $poller): int {
		$this->maybeFail('savePoller');
		$id = isset($poller['id']) && is_int($poller['id']) && $poller['id'] > 0 ? $poller['id'] : $this->nextPollerId++;

		if ($id >= $this->nextPollerId) {
			$this->nextPollerId = $id + 1;
		}

		$poller['id']       = $id;
		$this->pollers[$id] = $poller;

		return $id;
	}

	/**
	 * Delete a remote poller from memory while protecting primary poller id=1.
	 *
	 * @param int $id Poller ID.
	 * @return void
	 */
	public function deletePoller(int $id): void {
		$this->maybeFail('deletePoller');

		if ($id === 1) {
			throw new \RuntimeException('Primary poller (id=1) cannot be deleted');
		}

		unset($this->pollers[$id]);
	}

	/**
	 * Return seeded host templates.
	 *
	 * @return list<array{id: int, hash: string, name: string, class: string}>
	 */
	public function getHostTemplates(): array {
		return $this->hostTemplates;
	}

	/**
	 * Return seeded SNMP data queries.
	 *
	 * @return list<array{id: int, hash: string, name: string}>
	 */
	public function getDataQueries(): array {
		return $this->dataQueries;
	}

	/**
	 * Return seeded graph templates.
	 *
	 * @return list<array{id: int, hash: string, name: string}>
	 */
	public function getGraphTemplates(): array {
		return $this->graphTemplates;
	}

	/**
	 * Record an imported template XML payload in memory.
	 *
	 * @param string $xml Canonical <cacti> template XML.
	 * @param string $profileName Data source profile name.
	 * @param bool $replaceSuggestedValues Whether to overwrite suggested values.
	 * @return bool Always returns true unless failOnOperation triggers.
	 */
	public function importTemplateXml(string $xml, string $profileName, bool $replaceSuggestedValues): bool {
		$this->maybeFail('importTemplateXml');
		$this->importedTemplates[] = [
			'kind'                   => 'xml',
			'payload'                => $xml,
			'profile'                => $profileName,
			'replaceSuggestedValues' => $replaceSuggestedValues,
		];

		return true;
	}

	/**
	 * Record an imported template package path in memory.
	 *
	 * @param string $filePath Verified package file path.
	 * @param string $profileName Data source profile name.
	 * @param bool $replaceSuggestedValues Whether to overwrite suggested values.
	 * @return bool Always returns true unless failOnOperation triggers.
	 */
	public function importTemplatePackage(string $filePath, string $profileName, bool $replaceSuggestedValues): bool {
		$this->maybeFail('importTemplatePackage');
		$this->importedTemplates[] = [
			'kind'                   => 'package',
			'payload'                => $filePath,
			'profile'                => $profileName,
			'replaceSuggestedValues' => $replaceSuggestedValues,
		];

		return true;
	}

	/**
	 * Look up a seeded template export XML string by type and ID.
	 *
	 * @param string $type Template type.
	 * @param int $id Template numeric ID.
	 * @return string|null Exported XML string or null.
	 */
	public function exportTemplateXml(string $type, int $id): ?string {
		return $this->templateExports[$type . ':' . $id] ?? null;
	}

	/**
	 * Return all stored devices as a list.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getDevices(): array {
		return array_values($this->devices);
	}

	/**
	 * Insert or update a device in memory and return its host ID.
	 *
	 * @param array<string, mixed> $device Device payload.
	 * @return int Saved device ID.
	 */
	public function saveDevice(array $device): int {
		$this->maybeFail('saveDevice');
		$id = isset($device['id']) && is_int($device['id']) && $device['id'] > 0 ? $device['id'] : $this->nextDeviceId++;

		if ($id >= $this->nextDeviceId) {
			$this->nextDeviceId = $id + 1;
		}

		$device['id']       = $id;
		$this->devices[$id] = $device;

		return $id;
	}

	/**
	 * Delete a device from memory by ID.
	 *
	 * @param int $id Device host ID.
	 * @return void
	 */
	public function deleteDevice(int $id): void {
		$this->maybeFail('deleteDevice');
		unset($this->devices[$id]);
	}

	/**
	 * Return all stored graph trees as a list.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getTrees(): array {
		return array_values($this->trees);
	}

	/**
	 * Insert or update a graph tree in memory and return its ID.
	 *
	 * @param array<string, mixed> $tree Graph tree payload.
	 * @return int Saved tree ID.
	 */
	public function saveTree(array $tree): int {
		$this->maybeFail('saveTree');
		$id = isset($tree['id']) && is_int($tree['id']) && $tree['id'] > 0 ? $tree['id'] : $this->nextTreeId++;

		if ($id >= $this->nextTreeId) {
			$this->nextTreeId = $id + 1;
		}

		$tree['id']       = $id;
		$this->trees[$id] = $tree;

		return $id;
	}

	/**
	 * Delete a graph tree from memory by ID.
	 *
	 * @param int $id Graph tree ID.
	 * @return void
	 */
	public function deleteTree(int $id): void {
		$this->maybeFail('deleteTree');
		unset($this->trees[$id]);
	}

	/**
	 * Return all stored plugins as a list.
	 *
	 * @return list<array{directory: string, name: string, status: int, version: string}>
	 */
	public function getPlugins(): array {
		return array_values($this->plugins);
	}

	/**
	 * Set the enabled flag for a plugin slug in memory.
	 *
	 * @param string $slug Plugin directory slug.
	 * @param bool $enabled Target enabled state.
	 * @return void
	 */
	public function setPluginEnabled(string $slug, bool $enabled): void {
		$this->maybeFail('setPluginEnabled');

		if (!isset($this->plugins[$slug])) {
			$this->plugins[$slug] = [
				'directory' => $slug,
				'name'      => ucfirst($slug),
				'status'    => $enabled ? 1 : 0,
				'version'   => '0.0.0',
			];

			return;
		}

		$this->plugins[$slug]['status'] = $enabled ? 1 : 0;
	}

	/**
	 * Prepend an audit entry in memory and return its assigned ID.
	 *
	 * @param array<string, mixed> $entry Audit column map.
	 * @return int Inserted audit ID.
	 */
	public function recordAudit(array $entry): int {
		$id          = $this->nextAuditId++;
		$entry['id'] = $id;
		array_unshift($this->audits, $entry);

		return $id;
	}

	/**
	 * Return the most recent recorded audit entries up to $limit.
	 *
	 * @param int $limit Maximum entries to return.
	 * @return list<array<string, mixed>>
	 */
	public function getRecentAudits(int $limit = 50): array {
		return array_slice($this->audits, 0, max(1, $limit));
	}

	/**
	 * Store a computed plan hash record in memory.
	 *
	 * @param string $planHash Canonical SHA-256 plan hash.
	 * @param string $actor Operator identity.
	 * @param string $manifestSha256 Canonical manifest SHA-256 hash.
	 * @param int $actionCount Number of actions in the plan.
	 * @param int $timestamp Unix epoch timestamp.
	 * @return void
	 */
	public function recordPlan(string $planHash, string $actor, string $manifestSha256, int $actionCount, int $timestamp): void {
		$this->plans[$planHash] = [
			'plan_hash'       => $planHash,
			'created_at'      => $timestamp,
			'actor'           => $actor,
			'manifest_sha256' => $manifestSha256,
			'action_count'    => $actionCount,
		];
	}

	/**
	 * Return the most recently persisted plan summary, if any exists.
	 *
	 * @return array{plan_hash: string, created_at: int, actor: string, manifest_sha256: string, action_count: int}|null
	 */
	public function getLatestPlan(): ?array {
		$latest = null;

		foreach ($this->plans as $plan) {
			$createdAt = is_numeric($plan['created_at'] ?? null) ? (int) $plan['created_at'] : 0;

			if ($latest === null || $createdAt >= $latest['created_at']) {
				$latest = [
					'plan_hash'       => is_scalar($plan['plan_hash'] ?? null) ? (string) $plan['plan_hash'] : '',
					'created_at'      => $createdAt,
					'actor'           => is_scalar($plan['actor'] ?? null) ? (string) $plan['actor'] : '',
					'manifest_sha256' => is_scalar($plan['manifest_sha256'] ?? null) ? (string) $plan['manifest_sha256'] : '',
					'action_count'    => is_numeric($plan['action_count'] ?? null) ? (int) $plan['action_count'] : 0,
				];
			}
		}

		return $latest;
	}

	/**
	 * Throw a simulated RuntimeException when $failOnOperation matches $op.
	 *
	 * @param string $op Repository operation name.
	 * @return void
	 */
	private function maybeFail(string $op): void {
		if ($this->failOnOperation === $op) {
			throw new \RuntimeException('Simulated failure in ' . $op);
		}
	}
}
