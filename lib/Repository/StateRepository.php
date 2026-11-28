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
 * Storage boundary for CasC providers and reconciliation engine.
 *
 * Production uses CactiStateRepository backed by Cacti's prepared DB and core
 * API helpers. Unit tests use InMemoryStateRepository with full transaction
 * rollback and replication/cache callback assertions.
 */
interface StateRepository {
	/**
	 * Start a database or snapshot transaction.
	 *
	 * @return void
	 */
	public function beginTransaction(): void;

	/**
	 * Commit the active transaction.
	 *
	 * @return void
	 */
	public function commit(): void;

	/**
	 * Roll back the active transaction on failure or drift.
	 *
	 * @return void
	 */
	public function rollback(): void;

	/**
	 * Trigger remote data collector replication after a successful apply.
	 *
	 * @param string $class Cacti replication class (default 'all').
	 * @return void
	 */
	public function triggerReplication(string $class = 'all'): void;

	/**
	 * Rebuild Cacti's poller cache for a single host or globally.
	 *
	 * @param int|null $hostId Target host ID or null for full rebuild.
	 * @return void
	 */
	public function triggerPollerCacheUpdate(?int $hostId = null): void;

	/**
	 * Fetch all Cacti settings as a name => value map.
	 *
	 * @return array<string, string>
	 */
	public function getSettings(): array;

	/**
	 * Insert or update a single Cacti setting.
	 *
	 * @param string $name Setting key name.
	 * @param string $value Setting string value.
	 * @return void
	 */
	public function setSetting(string $name, string $value): void;

	/**
	 * Delete a single Cacti setting by name.
	 *
	 * @param string $name Setting key name.
	 * @return void
	 */
	public function deleteSetting(string $name): void;

	/**
	 * Fetch all site rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getSites(): array;

	/**
	 * Insert or update a site row and return its numeric ID.
	 *
	 * @param array<string, mixed> $site Site column map.
	 * @return int Saved site ID.
	 */
	public function saveSite(array $site): int;

	/**
	 * Delete a site row by ID.
	 *
	 * @param int $id Site ID.
	 * @return void
	 */
	public function deleteSite(int $id): void;

	/**
	 * Fetch all poller rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getPollers(): array;

	/**
	 * Insert or update a poller row and return its numeric ID.
	 *
	 * @param array<string, mixed> $poller Poller column map.
	 * @return int Saved poller ID.
	 */
	public function savePoller(array $poller): int;

	/**
	 * Delete a remote poller row by ID (must reject primary poller id=1).
	 *
	 * @param int $id Poller ID.
	 * @return void
	 */
	public function deletePoller(int $id): void;

	/**
	 * Fetch all host templates.
	 *
	 * @return list<array{id: int, hash: string, name: string, class: string}>
	 */
	public function getHostTemplates(): array;

	/**
	 * Fetch all SNMP data queries.
	 *
	 * @return list<array{id: int, hash: string, name: string}>
	 */
	public function getDataQueries(): array;

	/**
	 * Fetch all graph templates.
	 *
	 * @return list<array{id: int, hash: string, name: string}>
	 */
	public function getGraphTemplates(): array;

	/**
	 * Import a validated native Cacti template XML string.
	 *
	 * @param string $xml Canonical <cacti> template XML.
	 * @param string $profileName Data source profile name.
	 * @param bool $replaceSuggestedValues Whether to overwrite suggested values.
	 * @return bool True on successful import.
	 */
	public function importTemplateXml(string $xml, string $profileName, bool $replaceSuggestedValues): bool;

	/**
	 * Import a signed Cacti .xml.gz template package file.
	 *
	 * @param string $filePath Verified path to the .xml.gz package file.
	 * @param string $profileName Data source profile name.
	 * @param bool $replaceSuggestedValues Whether to overwrite suggested values.
	 * @return bool True on successful import.
	 */
	public function importTemplatePackage(string $filePath, string $profileName, bool $replaceSuggestedValues): bool;

	/**
	 * Export a Cacti template item as XML.
	 *
	 * @param string $type Cacti template type (e.g. 'host_template').
	 * @param int $id Template numeric ID.
	 * @return string|null Exported XML string or null if unavailable.
	 */
	public function exportTemplateXml(string $type, int $id): ?string;

	/**
	 * Fetch all non-deleted device rows with associated data queries and graph templates.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getDevices(): array;

	/**
	 * Insert or update a device row and return its host ID.
	 *
	 * @param array<string, mixed> $device Device column map.
	 * @return int Saved host ID.
	 */
	public function saveDevice(array $device): int;

	/**
	 * Delete a device row by ID.
	 *
	 * @param int $id Device host ID.
	 * @return void
	 */
	public function deleteDevice(int $id): void;

	/**
	 * Fetch all graph trees with nested hierarchical item branches.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getTrees(): array;

	/**
	 * Insert or update a graph tree and its item hierarchy.
	 *
	 * @param array<string, mixed> $tree Graph tree payload.
	 * @return int Saved tree ID.
	 */
	public function saveTree(array $tree): int;

	/**
	 * Delete a graph tree and its items by ID.
	 *
	 * @param int $id Graph tree ID.
	 * @return void
	 */
	public function deleteTree(int $id): void;

	/**
	 * Fetch all installed Cacti plugins from plugin_config.
	 *
	 * @return list<array{directory: string, name: string, status: int, version: string}>
	 */
	public function getPlugins(): array;

	/**
	 * Enable (installing if needed) or disable a Cacti plugin by directory slug.
	 *
	 * @param string $slug Plugin directory slug.
	 * @param bool $enabled Target enabled state.
	 * @return void
	 */
	public function setPluginEnabled(string $slug, bool $enabled): void;

	/**
	 * Persist a zero-secret audit entry and return its numeric ID.
	 *
	 * @param array<string, mixed> $entry Audit column map.
	 * @return int Inserted audit ID.
	 */
	public function recordAudit(array $entry): int;

	/**
	 * Fetch recent CasC audit entries ordered newest first.
	 *
	 * @param int $limit Maximum number of rows to return.
	 * @return list<array<string, mixed>>
	 */
	public function getRecentAudits(int $limit = 50): array;

	/**
	 * Record a computed plan hash for confirmation traceability.
	 *
	 * @param string $planHash Canonical SHA-256 plan hash.
	 * @param string $actor Operator identity.
	 * @param string $manifestSha256 Canonical manifest SHA-256 hash.
	 * @param int $actionCount Number of actions in the plan.
	 * @param int $timestamp Unix epoch timestamp.
	 * @return void
	 */
	public function recordPlan(string $planHash, string $actor, string $manifestSha256, int $actionCount, int $timestamp): void;

	/**
	 * Fetch the most recently persisted plan summary, if any exists.
	 *
	 * @return array{plan_hash: string, created_at: int, actor: string, manifest_sha256: string, action_count: int}|null
	 */
	public function getLatestPlan(): ?array;
}
