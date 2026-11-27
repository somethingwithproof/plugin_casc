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

namespace Somethingwithproof\Casc;

/**
 * Plugin schema migrations, hook table, and uninstall table retention control.
 */
final class Schema {
	public const VERSION = 1;

	public const HOOKS = [
		'auth_alternate_realms'  => 'casc_auth_alternate_realms',
		'config_arrays'          => 'casc_config_arrays',
		'config_settings'        => 'casc_config_settings',
		'draw_navigation_text'   => 'casc_draw_navigation_text',
		'abilities_register'     => 'casc_abilities_register',
		'doctor_register_checks' => 'casc_doctor_register_checks',
	];

	private const PLUGIN  = 'casc';
	private const SETTING = 'casc_schema_version';

	public function __construct(private readonly \PDO|false $connection = false) {
	}

	public function storedVersion(): int {
		$value = db_fetch_cell_prepared(
			'SELECT value FROM settings WHERE name = ?',
			[self::SETTING],
			'',
			true,
			$this->connection
		);

		return is_numeric($value) ? (int) $value : 0;
	}

	public function isCurrent(): bool {
		return $this->storedVersion() === self::VERSION;
	}

	public function migrate(): bool {
		$from = $this->storedVersion();

		if ($from > self::VERSION) {
			Log::error(sprintf('Schema version %d is newer than plugin code (%d); refusing downgrade', $from, self::VERSION));

			return false;
		}

		foreach (self::tables() as $table => $definition) {
			api_plugin_db_table_create(self::PLUGIN, $table, $definition);

			if (!$this->tableExists($table)) {
				Log::error(sprintf('Unable to create table %s; schema left at version %d', $table, $from));

				return false;
			}
		}

		db_execute_prepared(
			'REPLACE INTO settings (name, value) VALUES (?, ?)',
			[self::SETTING, (string) self::VERSION],
			true,
			$this->connection
		);

		if ($from !== self::VERSION) {
			Log::info(sprintf('Schema migrated from version %d to %d', $from, self::VERSION));
		}

		return true;
	}

	public function recordedPluginVersion(): string {
		return (string) db_fetch_cell_prepared(
			'SELECT version FROM plugin_config WHERE directory = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);
	}

	public function recordPluginVersion(string $version, string $author, string $homepage): void {
		db_execute_prepared(
			'UPDATE plugin_config SET version = ?, author = ?, webpage = ? WHERE directory = ?',
			[$version, $author, $homepage, self::PLUGIN],
			true,
			$this->connection
		);
	}

	public function pluginEnabled(): bool {
		return (int) db_fetch_cell_prepared(
			'SELECT status FROM plugin_config WHERE directory = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		) === 1;
	}

	public function hooksRegistered(): bool {
		$placeholders = implode(', ', array_fill(0, count(self::HOOKS), '?'));
		$hooks        = (int) db_fetch_cell_prepared(
			"SELECT COUNT(DISTINCT hook) FROM plugin_hooks WHERE name = ? AND hook IN ($placeholders)",
			array_merge([self::PLUGIN], array_keys(self::HOOKS)),
			'',
			true,
			$this->connection
		);
		$realms = (int) db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);

		return $hooks === count(self::HOOKS) && $realms === count(Pages::realms());
	}

	public function untrackTables(): void {
		db_execute_prepared(
			"DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'",
			[self::PLUGIN],
			true,
			$this->connection
		);
	}

	public function forgetVersion(): void {
		db_execute_prepared(
			'DELETE FROM settings WHERE name = ?',
			[self::SETTING],
			true,
			$this->connection
		);
	}

	private function tableExists(string $table): bool {
		return db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
			[$table],
			'',
			true,
			$this->connection
		) > 0;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function tables(): array {
		return [
			'plugin_casc_audit' => [
				'columns' => [
					['name' => 'id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true],
					['name' => 'occurred_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'actor', 'type' => 'varchar(64)', 'NULL' => false, 'default' => ''],
					['name' => 'source', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'cli'],
					['name' => 'action', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'apply'],
					['name' => 'plan_hash', 'type' => 'char(64)', 'NULL' => false, 'default' => ''],
					['name' => 'status', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'succeeded'],
					['name' => 'created_count', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'updated_count', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'deleted_count', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'summary_json', 'type' => 'mediumtext', 'NULL' => false],
				],
				'primary' => 'id',
				'keys'    => [
					['name' => 'idx_occurred_at', 'columns' => 'occurred_at'],
					['name' => 'idx_plan_hash', 'columns' => 'plan_hash'],
				],
				'type'    => 'InnoDB',
				'comment' => 'CasC operator plan and apply audit history (zero secrets)',
			],
			'plugin_casc_plan' => [
				'columns' => [
					['name' => 'plan_hash', 'type' => 'char(64)', 'NULL' => false, 'default' => ''],
					['name' => 'created_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'actor', 'type' => 'varchar(64)', 'NULL' => false, 'default' => ''],
					['name' => 'manifest_sha256', 'type' => 'char(64)', 'NULL' => false, 'default' => ''],
					['name' => 'action_count', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary' => 'plan_hash',
				'keys'    => [
					['name' => 'idx_created_at', 'columns' => 'created_at'],
				],
				'type'    => 'InnoDB',
				'comment' => 'CasC recent plan hashes for confirmation traceability',
			],
		];
	}
}
