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

use Somethingwithproof\Casc\Codec\CascCodec;
use Somethingwithproof\Casc\Engine\CascService;
use Somethingwithproof\Casc\Pages;

/**
 * Registers read-only CasC status and drift abilities with plugin_mcp's
 * abilities_register hook.
 */
final class McpAbilities {
	private const ANNOTATIONS = [
		'readOnly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	];

	/**
	 * Register casc-get-status and casc-check-drift on an AbilitiesRegistrar
	 * instance without coupling to plugin_mcp's class autoloading.
	 *
	 * @param object $registrar plugin_mcp AbilitiesRegistrar instance
	 * @param callable(): CascService $serviceFactory
	 * @param callable(): string $baselinePathProvider
	 * @param callable(int): bool $permissionCheck
	 */
	public static function register(
		object $registrar,
		callable $serviceFactory,
		callable $baselinePathProvider,
		callable $permissionCheck,
	): void {
		if (!method_exists($registrar, 'register')) {
			return;
		}

		$registrar->register('casc-get-status', [
			'label'        => 'Get CasC status',
			'description'  => 'Read plugin_casc version, registered plugin providers, and recent apply audit summary without exposing secrets.',
			'category'     => 'system',
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'baselineConfigured'  => ['type' => 'boolean'],
					'registeredProviders' => [
						'type'     => 'array',
						'items'    => ['type' => 'string', 'maxLength' => 64],
						'maxItems' => 128,
					],
					'providerFaultCount'  => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000],
					'recentAuditCount'    => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000],
					'lastApplyStatus'     => ['type' => ['string', 'null'], 'maxLength' => 32],
					'lastApplyPlanHash'   => ['type' => ['string', 'null'], 'maxLength' => 64],
				],
				'required'             => ['baselineConfigured', 'registeredProviders', 'providerFaultCount', 'recentAuditCount', 'lastApplyStatus', 'lastApplyPlanHash'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permissionCheck,
			'execute'     => static function (int $userId, \stdClass $args) use ($serviceFactory, $baselinePathProvider): array {
				$service   = $serviceFactory();
				$baseline  = trim($baselinePathProvider());
				$providers = array_map(static fn ($d): string => $d->slug, $service->pluginRegistry()->all());
				$audits    = $service->repository()->getRecentAudits(10);
				$last      = $audits[0] ?? null;

				return [
					'baselineConfigured'  => $baseline !== '' && is_file($baseline),
					'registeredProviders' => $providers,
					'providerFaultCount'  => count($service->pluginRegistry()->faults()),
					'recentAuditCount'    => count($audits),
					'lastApplyStatus'     => is_array($last) && is_scalar($last['status'] ?? null) ? (string) $last['status'] : null,
					'lastApplyPlanHash'   => is_array($last) && is_scalar($last['plan_hash'] ?? null) ? (string) $last['plan_hash'] : null,
				];
			},
		]);

		$registrar->register('casc-check-drift', [
			'label'        => 'Check CasC configuration drift',
			'description'  => 'Compare live Cacti state against the configured CasC baseline manifest and return masked drift counts and plan hash.',
			'category'     => 'system',
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'configured' => ['type' => 'boolean'],
					'drifted'    => ['type' => 'boolean'],
					'hasErrors'  => ['type' => 'boolean'],
					'planHash'   => ['type' => ['string', 'null'], 'maxLength' => 64],
					'create'     => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
					'update'     => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
					'delete'     => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
					'warning'    => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
					'error'      => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
				],
				'required'             => ['configured', 'drifted', 'hasErrors', 'planHash', 'create', 'update', 'delete', 'warning', 'error'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permissionCheck,
			'execute'     => static function (int $userId, \stdClass $args) use ($serviceFactory, $baselinePathProvider): array {
				$baseline = trim($baselinePathProvider());

				if ($baseline === '' || !is_file($baseline) || !is_readable($baseline)) {
					return [
						'configured' => false,
						'drifted'    => false,
						'hasErrors'  => false,
						'planHash'   => null,
						'create'     => 0,
						'update'     => 0,
						'delete'     => 0,
						'warning'    => 0,
						'error'      => 0,
					];
				}

				$service = $serviceFactory();
				$raw     = CascCodec::readFile($baseline);
				$format  = CascCodec::detectFormatFromPath($baseline);
				$doc     = CascCodec::parse($format, $raw);
				$plan    = $service->plan($doc, ['baseDir' => dirname($baseline)], 'mcp', false);
				$counts  = $plan->counts();

				return [
					'configured' => true,
					'drifted'    => $plan->hasChanges(),
					'hasErrors'  => $plan->hasErrors(),
					'planHash'   => $plan->planHash,
					'create'     => $counts['create'],
					'update'     => $counts['update'],
					'delete'     => $counts['delete'],
					'warning'    => $counts['warning'],
					'error'      => $counts['error'],
				];
			},
		]);
	}

	/**
	 * Default Cacti realm permission check for MCP abilities (CasC View or higher).
	 *
	 * @param int $userId Cacti user ID to authorize.
	 * @return bool True when the user holds View, Operator, or Admin realm access.
	 */
	public static function userHasViewRealm(int $userId): bool {
		if ($userId <= 0 || !function_exists('db_fetch_cell_prepared') || !function_exists('is_realm_allowed')) {
			return false;
		}

		foreach ([Pages::VIEW, Pages::OPERATOR, Pages::ADMIN] as $file) {
			/** @var mixed $realmId */
			$realmId = db_fetch_cell_prepared(
				'SELECT id FROM plugin_realms WHERE plugin = ? AND file = ?',
				['casc', $file]
			);

			if (is_numeric($realmId) && (int) $realmId > 0 && is_realm_allowed((int) $realmId + 100, $userId)) {
				return true;
			}
		}

		return false;
	}
}
