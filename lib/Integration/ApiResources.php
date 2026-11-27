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

namespace Somethingwithproof\Casc\Integration;

use Somethingwithproof\Casc\Pages;

/**
 * Plain-array API resource descriptors exposed to plugin_api via the
 * api_register_resources hook without any hard dependency on plugin_api.
 */
final class ApiResources {
	private const SCHEMA_PATH = 'schema/casc.schema.yaml';

	/**
	 * Append CasC API resource descriptors to an existing resource registry list.
	 *
	 * @param mixed $resources Existing resources list passed by api_register_resources hook.
	 * @return list<array<string, mixed>> Combined list of API resource descriptors.
	 */
	public static function register(mixed $resources = []): array {
		$out = [];

		if (is_array($resources)) {
			foreach ($resources as $existing) {
				if (is_array($existing)) {
					$normalized = [];

					foreach ($existing as $k => $v) {
						$normalized[(string) $k] = $v;
					}

					$out[] = $normalized;
				}
			}
		}

		foreach (self::descriptors() as $descriptor) {
			$out[] = $descriptor;
		}

		return $out;
	}

	/**
	 * Build plain-array descriptors for CasC read and plan API endpoints.
	 *
	 * @return list<array{resource: string, plugin: string, methods: list<string>, readOnly: bool, realm: string, schema: string, description: string}>
	 */
	public static function descriptors(): array {
		return [
			[
				'resource'    => 'casc/export',
				'plugin'      => 'casc',
				'methods'     => ['GET'],
				'readOnly'    => true,
				'realm'       => Pages::OPERATOR,
				'schema'      => self::SCHEMA_PATH,
				'description' => 'Export redacted live Cacti configuration as canonical YAML or XML.',
			],
			[
				'resource'    => 'casc/plan',
				'plugin'      => 'casc',
				'methods'     => ['POST'],
				'readOnly'    => true,
				'realm'       => Pages::OPERATOR,
				'schema'      => self::SCHEMA_PATH,
				'description' => 'Validate a submitted manifest and compute a deterministic reconciliation plan hash.',
			],
			[
				'resource'    => 'casc/drift',
				'plugin'      => 'casc',
				'methods'     => ['GET'],
				'readOnly'    => true,
				'realm'       => Pages::VIEW,
				'schema'      => self::SCHEMA_PATH,
				'description' => 'Evaluate configuration drift between live Cacti state and the configured baseline manifest.',
			],
			[
				'resource'    => 'casc/schema',
				'plugin'      => 'casc',
				'methods'     => ['GET'],
				'readOnly'    => true,
				'realm'       => Pages::VIEW,
				'schema'      => self::SCHEMA_PATH,
				'description' => 'Retrieve live JSON Schema Draft 2020-12 metadata across core and registered plugin CasC providers.',
			],
		];
	}
}
