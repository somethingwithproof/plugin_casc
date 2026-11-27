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
	public function beginTransaction(): void;

	public function commit(): void;

	public function rollback(): void;

	public function triggerReplication(string $class = 'all'): void;

	public function triggerPollerCacheUpdate(?int $hostId = null): void;

	/** @return array<string, string> */
	public function getSettings(): array;

	public function setSetting(string $name, string $value): void;

	public function deleteSetting(string $name): void;

	/** @return list<array<string, mixed>> */
	public function getSites(): array;

	/** @param array<string, mixed> $site */
	public function saveSite(array $site): int;

	public function deleteSite(int $id): void;

	/** @return list<array<string, mixed>> */
	public function getPollers(): array;

	/** @param array<string, mixed> $poller */
	public function savePoller(array $poller): int;

	public function deletePoller(int $id): void;

	/** @return list<array{id: int, hash: string, name: string, class: string}> */
	public function getHostTemplates(): array;

	/** @return list<array{id: int, hash: string, name: string}> */
	public function getDataQueries(): array;

	/** @return list<array{id: int, hash: string, name: string}> */
	public function getGraphTemplates(): array;

	public function importTemplateXml(string $xml, string $profileName, bool $replaceSuggestedValues): bool;

	public function importTemplatePackage(string $filePath, string $profileName, bool $replaceSuggestedValues): bool;

	public function exportTemplateXml(string $type, int $id): ?string;

	/** @return list<array<string, mixed>> */
	public function getDevices(): array;

	/** @param array<string, mixed> $device */
	public function saveDevice(array $device): int;

	public function deleteDevice(int $id): void;

	/** @return list<array<string, mixed>> */
	public function getTrees(): array;

	/** @param array<string, mixed> $tree */
	public function saveTree(array $tree): int;

	public function deleteTree(int $id): void;

	/** @return list<array{directory: string, name: string, status: int, version: string}> */
	public function getPlugins(): array;

	public function setPluginEnabled(string $slug, bool $enabled): void;

	/** @param array<string, mixed> $entry */
	public function recordAudit(array $entry): int;

	/** @return list<array<string, mixed>> */
	public function getRecentAudits(int $limit = 50): array;

	public function recordPlan(string $planHash, string $actor, string $manifestSha256, int $actionCount, int $timestamp): void;
}
