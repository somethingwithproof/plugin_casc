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

use Somethingwithproof\Casc\Engine\CascService;
use Somethingwithproof\Casc\Pages;
use Somethingwithproof\Casc\Settings;

/**
 * Plain-array API resource descriptors exposed to plugin_api via the
 * api_register_resources hook without any hard dependency on plugin_api.
 */
final class ApiResources {
	/**
	 * Relative path to plugin_casc's static Config/State YAML schema file.
	 */
	private const SCHEMA_PATH = 'schema/casc.schema.yaml';

	/**
	 * Return canonical plugin_api resource descriptors for plugin_casc.
	 *
	 * @param mixed                           $resources        Optional existing resources list passed by hook.
	 * @param (callable(): CascService)|null  $serviceFactory   Optional lazy CascService factory.
	 * @param (callable(): Settings)|null     $settingsProvider Optional lazy Settings factory.
	 * @return list<array<string, mixed>> Canonical plugin_api resource descriptors.
	 */
	public static function register(
		mixed $resources = [],
		?callable $serviceFactory = null,
		?callable $settingsProvider = null,
	): array {
		$out = [];

		if (is_array($resources)) {
			foreach ($resources as $existing) {
				if (is_array($existing) && is_string($existing['collection'] ?? null) && $existing['collection'] !== '') {
					$normalized = [];

					foreach ($existing as $k => $v) {
						$normalized[(string) $k] = $v;
					}

					$out[] = $normalized;
				}
			}
		}

		foreach (self::descriptors($serviceFactory, $settingsProvider) as $descriptor) {
			$out[] = $descriptor;
		}

		return $out;
	}

	/**
	 * Build canonical plain-array descriptors for plugin_api's api_register_resources hook.
	 *
	 * @param (callable(): CascService)|null $serviceFactory   Optional lazy CascService factory.
	 * @param (callable(): Settings)|null    $settingsProvider Optional lazy Settings factory.
	 * @return list<array{plugin: string, collection: string, singleton: string, description: string, realm_read: string, schema: string, filter_fields: list<string>, order_fields: list<string>, handlers: array{list: callable(array<string, mixed>=): list<array<string, mixed>>, get: callable(string, array<string, mixed>=): (array<string, mixed>|null)}}>
	 */
	public static function descriptors(?callable $serviceFactory = null, ?callable $settingsProvider = null): array {
		$buildSummary = static function () use ($serviceFactory, $settingsProvider): array {
			$baselineConfigured = false;
			$providers          = [];
			$faultCount         = 0;
			$recentAuditCount   = 0;
			$lastApplyStatus    = null;
			$lastApplyPlanHash  = null;

			if ($settingsProvider !== null) {
				$baseline           = trim($settingsProvider()->baselineFile);
				$baselineConfigured = $baseline !== '' && is_file($baseline);
			}

			if ($serviceFactory !== null) {
				$service          = $serviceFactory();
				$providers        = array_map(static fn ($d): string => $d->slug, $service->pluginRegistry()->all());
				$faultCount       = count($service->pluginRegistry()->faults());
				$audits           = $service->repository()->getRecentAudits(10);
				$recentAuditCount = count($audits);
				$last             = $audits[0] ?? null;

				if (is_array($last)) {
					$lastApplyStatus   = is_scalar($last['status'] ?? null) ? (string) $last['status'] : null;
					$lastApplyPlanHash = is_scalar($last['plan_hash'] ?? null) ? (string) $last['plan_hash'] : null;
				}
			}

			return [
				'id'                  => 'summary',
				'name'                => 'cascStatus/summary',
				'status'              => $faultCount > 0 ? 'degraded' : 'ok',
				'baselineConfigured'  => $baselineConfigured,
				'registeredProviders' => $providers,
				'providerFaultCount'  => $faultCount,
				'recentAuditCount'    => $recentAuditCount,
				'lastApplyStatus'     => $lastApplyStatus,
				'lastApplyPlanHash'   => $lastApplyPlanHash,
				'schemaPath'          => self::SCHEMA_PATH,
			];
		};

		return [
			[
				'plugin'        => 'casc',
				'collection'    => 'cascStatus',
				'singleton'     => 'cascStatus',
				'description'   => 'Configuration-as-Code status, registered plugin providers, and recent reconciliation audit summary.',
				'realm_read'    => Pages::VIEW,
				'schema'        => self::SCHEMA_PATH,
				'filter_fields' => ['id', 'name', 'status'],
				'order_fields'  => ['id', 'name'],
				'handlers'      => [
					'list' => static function (array $context = []) use ($buildSummary): array {
						return [$buildSummary()];
					},
					'get' => static function (string $id, array $context = []) use ($buildSummary, $serviceFactory): ?array {
						if ($id === 'summary') {
							return $buildSummary();
						}

						if ($id === 'schema' && $serviceFactory !== null) {
							return [
								'id'     => 'schema',
								'name'   => 'cascStatus/schema',
								'status' => 'ok',
								'schema' => $serviceFactory()->describeSchema(),
							];
						}

						return null;
					},
				],
			],
		];
	}
}
