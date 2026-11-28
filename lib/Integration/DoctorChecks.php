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
use Somethingwithproof\Casc\Schema;
use Somethingwithproof\Casc\Settings;

/**
 * Diagnostic checks and runtime debug metadata exposed to plugin_doctor via
 * the doctor_register_checks and doctor_register_debug_info hooks.
 */
final class DoctorChecks {
	private const PLUGIN   = 'casc';
	private const CATEGORY = 'CasC';

	/**
	 * Evaluate or list CasC diagnostic checks matching the plugin_doctor
	 * doctor_register_checks contract.
	 *
	 * @param callable(): CascService $serviceFactory Factory returning the active CasC service.
	 * @param callable(): Settings $settingsProvider Provider returning current plugin settings.
	 * @param array<string, mixed> $context Hook context containing optional 'mode' ('run'|'list') and 'plugin'.
	 * @return list<array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}>
	 */
	public static function build(callable $serviceFactory, callable $settingsProvider, array $context = []): array {
		$mode = is_string($context['mode'] ?? null) ? strtolower(trim($context['mode'])) : 'run';

		if ($mode === 'list') {
			return [
				self::descriptor(
					'casc.secret_dirs',
					'CasC Secret Directories Permissions',
					'info',
					'Verifies that allowlisted casc_secret_dirs exist, are not symlinks, and are not world-writable.',
					['mode' => 'list'],
					''
				),
				self::descriptor(
					'casc.baseline_drift',
					'CasC Baseline Configuration Drift',
					'info',
					'Compares live Cacti state against the configured CasC baseline manifest.',
					['mode' => 'list'],
					''
				),
				self::descriptor(
					'casc.plugin_providers',
					'CasC Plugin Provider Health',
					'info',
					'Reports registered casc_register_providers extensions and any isolated registration or runtime faults.',
					['mode' => 'list'],
					''
				),
			];
		}

		return [
			self::checkSecretDirs($settingsProvider),
			self::checkBaselineDrift($serviceFactory, $settingsProvider),
			self::checkPluginProviders($serviceFactory),
		];
	}

	/**
	 * Build a Site Health style runtime debug info section for
	 * doctor_register_debug_info with zero secret values.
	 *
	 * @param callable(): CascService $serviceFactory Factory returning the active CasC service.
	 * @param callable(): Settings $settingsProvider Provider returning current plugin settings.
	 * @param string $version Plugin version from INFO metadata.
	 * @return list<array{id: string, plugin: string, label: string, description: string, private: bool, fields: array<string, array{label: string, value: int|string}>}>
	 */
	public static function debugInfo(callable $serviceFactory, callable $settingsProvider, string $version): array {
		$settings           = $settingsProvider();
		$registry           = $serviceFactory()->pluginRegistry();
		$baselineConfigured = $settings->baselineFile !== '' && is_file($settings->baselineFile);

		return [
			[
				'id'          => 'casc.runtime',
				'plugin'      => self::PLUGIN,
				'label'       => 'CasC Runtime',
				'description' => 'Declarative Configuration-as-Code runtime settings summary and registered provider counts (zero secrets).',
				'private'     => false,
				'fields'      => [
					'version' => [
						'label' => 'Plugin Version',
						'value' => $version !== '' ? $version : '0.1.0',
					],
					'schema_version' => [
						'label' => 'Schema Version',
						'value' => (string) Schema::VERSION,
					],
					'baseline_configured' => [
						'label' => 'Baseline Manifest Configured',
						'value' => $baselineConfigured ? 'yes' : 'no',
					],
					'secret_dirs_count' => [
						'label' => 'Allowlisted Secret Directories',
						'value' => count($settings->secretDirs),
					],
					'registered_providers_count' => [
						'label' => 'Registered Plugin Providers',
						'value' => count($registry->all()),
					],
				],
			],
		];
	}

	/**
	 * Validate allowlisted casc_secret_dirs existence, symlink absence, and permissions.
	 *
	 * @param callable(): Settings $settingsProvider Provider returning current plugin settings.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function checkSecretDirs(callable $settingsProvider): array {
		$settings = $settingsProvider();

		if ($settings->secretDirs === []) {
			return self::descriptor(
				'casc.secret_dirs',
				'CasC Secret Directories Permissions',
				'ok',
				'No casc_secret_dirs configured (file: secret references disabled).',
				['dirs' => []],
				''
			);
		}

		$problems = [];

		foreach ($settings->secretDirs as $dir) {
			if (!is_dir($dir)) {
				$problems[] = sprintf('Directory missing: %s', $dir);

				continue;
			}

			if (is_link($dir)) {
				$problems[] = sprintf('Directory is a symlink: %s', $dir);

				continue;
			}

			$stat = lstat($dir);

			if ($stat !== false && (($stat['mode'] & 0002) !== 0)) {
				$problems[] = sprintf('Directory is world-writable: %s', $dir);
			}
		}

		if ($problems !== []) {
			return self::descriptor(
				'casc.secret_dirs',
				'CasC Secret Directories Permissions',
				'warn',
				implode('; ', $problems),
				['problems' => $problems],
				'Ensure every directory in casc_secret_dirs exists, is not a symlink, and is not world-writable (chmod 0700 or 0750).'
			);
		}

		return self::descriptor(
			'casc.secret_dirs',
			'CasC Secret Directories Permissions',
			'ok',
			sprintf('%d allowlisted secret directories verified.', count($settings->secretDirs)),
			['dirs' => $settings->secretDirs],
			''
		);
	}

	/**
	 * Check live Cacti state for configuration drift against the configured baseline manifest.
	 *
	 * @param callable(): CascService $serviceFactory Factory returning the active CasC service.
	 * @param callable(): Settings $settingsProvider Provider returning current plugin settings.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function checkBaselineDrift(callable $serviceFactory, callable $settingsProvider): array {
		$settings = $settingsProvider();
		$baseline = $settings->baselineFile;

		if ($baseline === '') {
			return self::descriptor(
				'casc.baseline_drift',
				'CasC Baseline Configuration Drift',
				'ok',
				'No CasC baseline manifest configured.',
				['configured' => false],
				''
			);
		}

		if (!is_file($baseline) || !is_readable($baseline)) {
			return self::descriptor(
				'casc.baseline_drift',
				'CasC Baseline Configuration Drift',
				'warn',
				sprintf('Configured CasC baseline manifest "%s" is unreadable.', $baseline),
				['configured' => true, 'readable' => false],
				'Verify casc_baseline_file points to an existing readable YAML or XML manifest file.'
			);
		}

		try {
			$service = $serviceFactory();
			$raw     = CascCodec::readFile($baseline);
			$doc     = CascCodec::parse(CascCodec::detectFormatFromPath($baseline), $raw);
			$plan    = $service->plan($doc, ['baseDir' => dirname($baseline)], 'doctor', false);
			$counts  = $plan->counts();

			if ($plan->hasErrors()) {
				return self::descriptor(
					'casc.baseline_drift',
					'CasC Baseline Configuration Drift',
					'fail',
					sprintf('CasC baseline plan has %d error(s).', $counts['error']),
					$counts + ['planHash' => $plan->planHash],
					'Run cli/casc.php validate on the baseline manifest and resolve all reported validation errors.'
				);
			}

			if ($plan->hasChanges()) {
				return self::descriptor(
					'casc.baseline_drift',
					'CasC Baseline Configuration Drift',
					'warn',
					sprintf('CasC drift detected: %d create, %d update, %d delete.', $counts['create'], $counts['update'], $counts['delete']),
					$counts + ['planHash' => $plan->planHash],
					'Inspect pending changes with cli/casc.php plan and reconcile via cli/casc.php apply --confirm=<PLAN_HASH>.'
				);
			}

			return self::descriptor(
				'casc.baseline_drift',
				'CasC Baseline Configuration Drift',
				'ok',
				'Live Cacti state matches CasC baseline.',
				$counts + ['planHash' => $plan->planHash],
				''
			);
		} catch (\Throwable $e) {
			return self::descriptor(
				'casc.baseline_drift',
				'CasC Baseline Configuration Drift',
				'fail',
				'CasC drift check failed: ' . $e->getMessage(),
				['exception' => $e::class],
				'Check baseline manifest syntax and permissions, then re-run cli/casc.php validate.'
			);
		}
	}

	/**
	 * Report health and isolated fault counts for registered plugin CasC providers.
	 *
	 * @param callable(): CascService $serviceFactory Factory returning the active CasC service.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function checkPluginProviders(callable $serviceFactory): array {
		$registry = $serviceFactory()->pluginRegistry();
		$faults   = $registry->faults();

		if ($faults !== []) {
			return self::descriptor(
				'casc.plugin_providers',
				'CasC Plugin Provider Health',
				'warn',
				sprintf('%d plugin CasC provider fault(s) isolated.', count($faults)),
				['faults' => $faults],
				'Inspect Cacti logs under CASC for the failing plugin provider and update or disable the offending plugin.'
			);
		}

		return self::descriptor(
			'casc.plugin_providers',
			'CasC Plugin Provider Health',
			'ok',
			sprintf('%d plugin CasC provider(s) active with zero faults.', count($registry->all())),
			['providers' => array_map(static fn ($p): string => $p->slug, $registry->all())],
			''
		);
	}

	/**
	 * Construct a contract-compliant plugin_doctor check descriptor with bounded string lengths.
	 *
	 * @param string $id Dot-prefixed check identifier (casc.*).
	 * @param string $title Human-readable check title (max 256 bytes).
	 * @param string $status Allowed status enum (ok|warn|fail|info).
	 * @param string $message Summary message (max 2048 bytes).
	 * @param array<string, mixed> $details Structured scalar/nested details array.
	 * @param string $remediation Actionable remediation text (max 2048 bytes).
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function descriptor(
		string $id,
		string $title,
		string $status,
		string $message,
		array $details,
		string $remediation,
	): array {
		return [
			'id'          => $id,
			'plugin'      => self::PLUGIN,
			'category'    => self::CATEGORY,
			'title'       => substr($title, 0, 256),
			'status'      => $status,
			'message'     => substr($message !== '' ? $message : $title, 0, 2048),
			'details'     => $details,
			'remediation' => substr($remediation, 0, 2048),
		];
	}
}
