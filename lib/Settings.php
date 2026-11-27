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
 * Plugin configuration settings stored in Cacti's settings table.
 */
final class Settings {
	public const NAMES = [
		'casc_secret_dirs',
		'casc_baseline_file',
		'casc_uninstall_keep_tables',
	];

	/**
	 * @param list<string> $secretDirs
	 */
	public function __construct(
		public readonly array $secretDirs,
		public readonly string $baselineFile,
		public readonly bool $uninstallKeepTables,
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self {
		$rawDirs = is_scalar($row['casc_secret_dirs'] ?? null) ? (string) $row['casc_secret_dirs'] : '';
		$dirs    = [];

		foreach (preg_split('/[\r\n,:]+/', $rawDirs) ?: [] as $entry) {
			$trimmed = trim($entry);

			if ($trimmed !== '') {
				$dirs[] = $trimmed;
			}
		}

		$baseline = is_scalar($row['casc_baseline_file'] ?? null) ? trim((string) $row['casc_baseline_file']) : '';
		$keep     = ($row['casc_uninstall_keep_tables'] ?? '') === 'on';

		return new self($dirs, $baseline, $keep);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function form(): array {
		return [
			'casc_header' => [
				'friendly_name' => __('Configuration as Code (CasC)', 'casc'),
				'method'        => 'spacer',
			],
			'casc_secret_dirs' => [
				'friendly_name' => __('Allowlisted Secret Directories', 'casc'),
				'description'   => __('Colon-separated list of absolute directories permitted for file: secret references (files must be mode 0400 or 0600 with no symlinks).', 'casc'),
				'method'        => 'textbox',
				'default'       => '',
				'max_length'    => '512',
				'size'          => '80',
			],
			'casc_baseline_file' => [
				'friendly_name' => __('Baseline Manifest Path', 'casc'),
				'description'   => __('Optional path to a declarative YAML or XML baseline manifest used by drift checks, Doctor checks, and MCP status abilities.', 'casc'),
				'method'        => 'textbox',
				'default'       => '',
				'max_length'    => '512',
				'size'          => '80',
			],
			'casc_uninstall_keep_tables' => [
				'friendly_name' => __('Keep Audit Tables on Uninstall', 'casc'),
				'description'   => __('Preserve plugin_casc_audit and plugin_casc_plan tables when uninstalling the plugin.', 'casc'),
				'method'        => 'checkbox',
				'default'       => '',
			],
		];
	}
}
