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
 * Ephemeral read-through StateRepository proxy scoped to a single Planner::plan()
 * invocation so providers and CrossParameterValidator share one DB snapshot per
 * table instead of issuing redundant full-table reads.
 */
final class ReadCachedStateRepository implements StateRepository {
	/** @var array<string, string>|null */
	private ?array $settings = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $sites = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $pollers = null;

	/** @var list<array{id: int, hash: string, name: string, class: string}>|null */
	private ?array $hostTemplates = null;

	/** @var list<array{id: int, hash: string, name: string}>|null */
	private ?array $dataQueries = null;

	/** @var list<array{id: int, hash: string, name: string}>|null */
	private ?array $graphTemplates = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $devices = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $trees = null;

	/** @var list<array{directory: string, name: string, status: int, version: string}>|null */
	private ?array $plugins = null;

	/**
	 * Wrap a StateRepository with per-request read caching.
	 *
	 * @param StateRepository $inner Backing state repository.
	 */
	public function __construct(private readonly StateRepository $inner) {
	}

	/**
	 * Delegate transaction start to the backing repository.
	 *
	 * @return void
	 */
	public function beginTransaction(): void {
		$this->inner->beginTransaction();
	}

	/**
	 * Delegate transaction commit to the backing repository.
	 *
	 * @return void
	 */
	public function commit(): void {
		$this->inner->commit();
	}

	/**
	 * Delegate transaction rollback and invalidate all cached snapshots.
	 *
	 * @return void
	 */
	public function rollback(): void {
		$this->invalidateAll();
		$this->inner->rollback();
	}

	/**
	 * Delegate remote poller replication trigger.
	 *
	 * @param string $class Cacti replication class.
	 * @return void
	 */
	public function triggerReplication(string $class = 'all'): void {
		$this->inner->triggerReplication($class);
	}

	/**
	 * Delegate poller cache update trigger.
	 *
	 * @param int|null $hostId Target host ID or null.
	 * @return void
	 */
	public function triggerPollerCacheUpdate(?int $hostId = null): void {
		$this->inner->triggerPollerCacheUpdate($hostId);
	}

	/**
	 * Return cached Cacti settings map.
	 *
	 * @return array<string, string>
	 */
	public function getSettings(): array {
		return $this->settings ??= $this->inner->getSettings();
	}

	/**
	 * Delegate setting write and invalidate settings cache.
	 *
	 * @param string $name Setting name.
	 * @param string $value Setting value.
	 * @return void
	 */
	public function setSetting(string $name, string $value): void {
		$this->settings = null;
		$this->inner->setSetting($name, $value);
	}

	/**
	 * Delegate setting deletion and invalidate settings cache.
	 *
	 * @param string $name Setting name.
	 * @return void
	 */
	public function deleteSetting(string $name): void {
		$this->settings = null;
		$this->inner->deleteSetting($name);
	}

	/**
	 * Return cached site rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getSites(): array {
		return $this->sites ??= $this->inner->getSites();
	}

	/**
	 * Delegate site save and invalidate site cache.
	 *
	 * @param array<string, mixed> $site Site payload.
	 * @return int Saved site ID.
	 */
	public function saveSite(array $site): int {
		$this->sites = null;

		return $this->inner->saveSite($site);
	}

	/**
	 * Delegate site deletion and invalidate site cache.
	 *
	 * @param int $id Site ID.
	 * @return void
	 */
	public function deleteSite(int $id): void {
		$this->sites = null;
		$this->inner->deleteSite($id);
	}

	/**
	 * Return cached poller rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getPollers(): array {
		return $this->pollers ??= $this->inner->getPollers();
	}

	/**
	 * Delegate poller save and invalidate poller cache.
	 *
	 * @param array<string, mixed> $poller Poller payload.
	 * @return int Saved poller ID.
	 */
	public function savePoller(array $poller): int {
		$this->pollers = null;

		return $this->inner->savePoller($poller);
	}

	/**
	 * Delegate poller deletion and invalidate poller cache.
	 *
	 * @param int $id Poller ID.
	 * @return void
	 */
	public function deletePoller(int $id): void {
		$this->pollers = null;
		$this->inner->deletePoller($id);
	}

	/**
	 * Return cached host templates.
	 *
	 * @return list<array{id: int, hash: string, name: string, class: string}>
	 */
	public function getHostTemplates(): array {
		return $this->hostTemplates ??= $this->inner->getHostTemplates();
	}

	/**
	 * Return cached data queries.
	 *
	 * @return list<array{id: int, hash: string, name: string}>
	 */
	public function getDataQueries(): array {
		return $this->dataQueries ??= $this->inner->getDataQueries();
	}

	/**
	 * Return cached graph templates.
	 *
	 * @return list<array{id: int, hash: string, name: string}>
	 */
	public function getGraphTemplates(): array {
		return $this->graphTemplates ??= $this->inner->getGraphTemplates();
	}

	/**
	 * Delegate XML template import and invalidate template caches.
	 *
	 * @param string $xml Canonical template XML.
	 * @param string $profileName Data source profile name.
	 * @param bool $replaceSuggestedValues Whether to overwrite suggested values.
	 * @return bool True on success.
	 */
	public function importTemplateXml(string $xml, string $profileName, bool $replaceSuggestedValues): bool {
		$this->hostTemplates  = null;
		$this->dataQueries    = null;
		$this->graphTemplates = null;

		return $this->inner->importTemplateXml($xml, $profileName, $replaceSuggestedValues);
	}

	/**
	 * Delegate template package import and invalidate template caches.
	 *
	 * @param string $filePath Verified package path.
	 * @param string $profileName Data source profile name.
	 * @param bool $replaceSuggestedValues Whether to overwrite suggested values.
	 * @return bool True on success.
	 */
	public function importTemplatePackage(string $filePath, string $profileName, bool $replaceSuggestedValues): bool {
		$this->hostTemplates  = null;
		$this->dataQueries    = null;
		$this->graphTemplates = null;

		return $this->inner->importTemplatePackage($filePath, $profileName, $replaceSuggestedValues);
	}

	/**
	 * Delegate template XML export.
	 *
	 * @param string $type Template type.
	 * @param int $id Template ID.
	 * @return string|null Exported XML or null.
	 */
	public function exportTemplateXml(string $type, int $id): ?string {
		return $this->inner->exportTemplateXml($type, $id);
	}

	/**
	 * Return cached device rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getDevices(): array {
		return $this->devices ??= $this->inner->getDevices();
	}

	/**
	 * Delegate device save and invalidate device cache.
	 *
	 * @param array<string, mixed> $device Device payload.
	 * @return int Saved host ID.
	 */
	public function saveDevice(array $device): int {
		$this->devices = null;

		return $this->inner->saveDevice($device);
	}

	/**
	 * Delegate device deletion and invalidate device cache.
	 *
	 * @param int $id Host ID.
	 * @return void
	 */
	public function deleteDevice(int $id): void {
		$this->devices = null;
		$this->inner->deleteDevice($id);
	}

	/**
	 * Return cached graph tree rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getTrees(): array {
		return $this->trees ??= $this->inner->getTrees();
	}

	/**
	 * Delegate tree save and invalidate tree cache.
	 *
	 * @param array<string, mixed> $tree Tree payload.
	 * @return int Saved tree ID.
	 */
	public function saveTree(array $tree): int {
		$this->trees = null;

		return $this->inner->saveTree($tree);
	}

	/**
	 * Delegate tree deletion and invalidate tree cache.
	 *
	 * @param int $id Tree ID.
	 * @return void
	 */
	public function deleteTree(int $id): void {
		$this->trees = null;
		$this->inner->deleteTree($id);
	}

	/**
	 * Return cached installed plugin rows.
	 *
	 * @return list<array{directory: string, name: string, status: int, version: string}>
	 */
	public function getPlugins(): array {
		return $this->plugins ??= $this->inner->getPlugins();
	}

	/**
	 * Delegate plugin enablement change and invalidate plugin cache.
	 *
	 * @param string $slug Plugin slug.
	 * @param bool $enabled Target enabled state.
	 * @return void
	 */
	public function setPluginEnabled(string $slug, bool $enabled): void {
		$this->plugins = null;
		$this->inner->setPluginEnabled($slug, $enabled);
	}

	/**
	 * Delegate audit row creation.
	 *
	 * @param array<string, mixed> $entry Audit entry map.
	 * @return int Inserted audit ID.
	 */
	public function recordAudit(array $entry): int {
		return $this->inner->recordAudit($entry);
	}

	/**
	 * Delegate recent audit history query.
	 *
	 * @param int $limit Maximum rows.
	 * @return list<array<string, mixed>>
	 */
	public function getRecentAudits(int $limit = 50): array {
		return $this->inner->getRecentAudits($limit);
	}

	/**
	 * Delegate plan hash persistence.
	 *
	 * @param string $planHash Canonical SHA-256 plan hash.
	 * @param string $actor Operator identity.
	 * @param string $manifestSha256 Canonical manifest SHA-256 hash.
	 * @param int $actionCount Number of planned actions.
	 * @param int $timestamp Unix epoch timestamp.
	 * @return void
	 */
	public function recordPlan(string $planHash, string $actor, string $manifestSha256, int $actionCount, int $timestamp): void {
		$this->inner->recordPlan($planHash, $actor, $manifestSha256, $actionCount, $timestamp);
	}

	/**
	 * Delegate latest persisted plan lookup.
	 *
	 * @return array{plan_hash: string, created_at: int, actor: string, manifest_sha256: string, action_count: int}|null
	 */
	public function getLatestPlan(): ?array {
		return $this->inner->getLatestPlan();
	}

	/**
	 * Clear all cached read snapshots.
	 *
	 * @return void
	 */
	private function invalidateAll(): void {
		$this->settings       = null;
		$this->sites          = null;
		$this->pollers        = null;
		$this->hostTemplates  = null;
		$this->dataQueries    = null;
		$this->graphTemplates = null;
		$this->devices        = null;
		$this->trees          = null;
		$this->plugins        = null;
	}
}
