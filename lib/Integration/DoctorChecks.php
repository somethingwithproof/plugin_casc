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
use Somethingwithproof\Casc\Settings;

/**
 * Diagnostic checks exposed to plugin_doctor via the doctor_register_checks hook.
 */
final class DoctorChecks {
	/**
	 * Build structured diagnostic check descriptors compatible with
	 * plugin_doctor's doctor_register_checks hook.
	 *
	 * @param callable(): CascService $serviceFactory
	 * @param callable(): Settings $settingsProvider
	 * @return list<array{id: string, name: string, category: string, run: callable(): array{status: string, message: string, details: array<string, mixed>}}>
	 */
	public static function build(callable $serviceFactory, callable $settingsProvider): array {
		return [
			[
				'id'       => 'casc_secret_dirs',
				'name'     => 'CasC Secret Directories Permissions',
				'category' => 'casc',
				'run'      => static function () use ($settingsProvider): array {
					$settings = $settingsProvider();

					if ($settings->secretDirs === []) {
						return [
							'status'  => 'ok',
							'message' => 'No casc_secret_dirs configured (file: secret references disabled).',
							'details' => ['dirs' => []],
						];
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
						return [
							'status'  => 'warning',
							'message' => implode('; ', $problems),
							'details' => ['problems' => $problems],
						];
					}

					return [
						'status'  => 'ok',
						'message' => sprintf('%d allowlisted secret directories verified.', count($settings->secretDirs)),
						'details' => ['dirs' => $settings->secretDirs],
					];
				},
			],
			[
				'id'       => 'casc_baseline_drift',
				'name'     => 'CasC Baseline Configuration Drift',
				'category' => 'casc',
				'run'      => static function () use ($serviceFactory, $settingsProvider): array {
					$settings = $settingsProvider();
					$baseline = $settings->baselineFile;

					if ($baseline === '') {
						return [
							'status'  => 'ok',
							'message' => 'No CasC baseline manifest configured.',
							'details' => ['configured' => false],
						];
					}

					if (!is_file($baseline) || !is_readable($baseline)) {
						return [
							'status'  => 'warning',
							'message' => sprintf('Configured CasC baseline manifest "%s" is unreadable.', $baseline),
							'details' => ['configured' => true, 'readable' => false],
						];
					}

					try {
						$service = $serviceFactory();
						$raw     = CascCodec::readFile($baseline);
						$doc     = CascCodec::parse(CascCodec::detectFormatFromPath($baseline), $raw);
						$plan    = $service->plan($doc, ['baseDir' => dirname($baseline)], 'doctor', false);
						$counts  = $plan->counts();

						if ($plan->hasErrors()) {
							return [
								'status'  => 'critical',
								'message' => sprintf('CasC baseline plan has %d error(s).', $counts['error']),
								'details' => $counts + ['planHash' => $plan->planHash],
							];
						}

						if ($plan->hasChanges()) {
							return [
								'status'  => 'warning',
								'message' => sprintf('CasC drift detected: %d create, %d update, %d delete.', $counts['create'], $counts['update'], $counts['delete']),
								'details' => $counts + ['planHash' => $plan->planHash],
							];
						}

						return [
							'status'  => 'ok',
							'message' => 'Live Cacti state matches CasC baseline.',
							'details' => $counts + ['planHash' => $plan->planHash],
						];
					} catch (\Throwable $e) {
						return [
							'status'  => 'critical',
							'message' => 'CasC drift check failed: ' . $e->getMessage(),
							'details' => ['exception' => $e::class],
						];
					}
				},
			],
			[
				'id'       => 'casc_plugin_providers',
				'name'     => 'CasC Plugin Provider Health',
				'category' => 'casc',
				'run'      => static function () use ($serviceFactory): array {
					$registry = $serviceFactory()->pluginRegistry();
					$faults   = $registry->faults();

					if ($faults !== []) {
						return [
							'status'  => 'warning',
							'message' => sprintf('%d plugin CasC provider fault(s) isolated.', count($faults)),
							'details' => ['faults' => $faults],
						];
					}

					return [
						'status'  => 'ok',
						'message' => sprintf('%d plugin CasC provider(s) active with zero faults.', count($registry->all())),
						'details' => ['providers' => array_map(static fn ($p): string => $p->slug, $registry->all())],
					];
				},
			],
		];
	}
}
