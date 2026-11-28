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

namespace Somethingwithproof\Casc\Metrics;

use Somethingwithproof\Casc\Codec\CascCodec;
use Somethingwithproof\Casc\Codec\CodecException;
use Somethingwithproof\Casc\Engine\CascService;
use Somethingwithproof\Casc\Log;
use Somethingwithproof\Casc\Settings;

/**
 * Collects secret-free CasC runtime telemetry formatted for Cacti script-server
 * self-graphing (field:value pairs with RRDtool "U" fallback on error or
 * unsupported PHP).
 */
final class CascMetricsCollector {
	public const MIN_PHP_VERSION = '8.2.0';

	public const FIELDS = [
		'providers_registered',
		'drift_items',
		'last_plan_actions',
		'last_apply_ms',
		'validation_errors',
		'secret_dirs_configured',
	];

	/** @var callable(): CascService */
	private $serviceFactory;

	/** @var callable(): Settings */
	private $settingsProvider;

	/**
	 * Construct a CasC telemetry collector.
	 *
	 * @param callable(): CascService $serviceFactory Factory returning the active CasC service.
	 * @param callable(): Settings $settingsProvider Provider returning current plugin settings.
	 * @param string $phpVersion Runtime PHP version checked against the 8.2.0 floor.
	 */
	public function __construct(
		callable $serviceFactory,
		callable $settingsProvider,
		private readonly string $phpVersion = PHP_VERSION,
	) {
		$this->serviceFactory   = $serviceFactory;
		$this->settingsProvider = $settingsProvider;
	}

	/**
	 * Return all telemetry fields set to RRDtool unknown ("U").
	 *
	 * @return array<string, string>
	 */
	public static function unknownMetrics(): array {
		$unknown = [];

		foreach (self::FIELDS as $field) {
			$unknown[$field] = 'U';
		}

		return $unknown;
	}

	/**
	 * Collect secret-free telemetry metrics for Cacti script-server graphing.
	 *
	 * Never emits secret values, file paths, hostnames, or raw exception
	 * messages. Returns "U" for all fields when PHP is unsupported or when
	 * storage access fails.
	 *
	 * @return array<string, int|string> Metric values keyed by canonical Cacti field name.
	 */
	public function collect(): array {
		if (!version_compare($this->phpVersion, self::MIN_PHP_VERSION, '>=')) {
			return self::unknownMetrics();
		}

		try {
			$settings = ($this->settingsProvider)();
			$service  = ($this->serviceFactory)();
			$repo     = $service->repository();

			$providersRegistered  = count($service->pluginRegistry()->all());
			$secretDirsConfigured = count($settings->secretDirs);

			$latestPlan      = $repo->getLatestPlan();
			$lastPlanActions = $latestPlan !== null ? max(0, $latestPlan['action_count']) : 0;

			$lastApplyMs = self::extractLastApplyMs($repo->getRecentAudits(1));

			[$driftItems, $validationErrors] = self::evaluateBaseline($service, $settings->baselineFile);

			return [
				'providers_registered'   => $providersRegistered,
				'drift_items'            => $driftItems,
				'last_plan_actions'      => $lastPlanActions,
				'last_apply_ms'          => $lastApplyMs,
				'validation_errors'      => $validationErrors,
				'secret_dirs_configured' => $secretDirsConfigured,
			];
		} catch (\Throwable $e) {
			Log::warning('CasC metrics collection failed: ' . $e::class);

			return self::unknownMetrics();
		}
	}

	/**
	 * Format a collected metric map into Cacti's space-separated field:value syntax.
	 *
	 * Any non-integer or negative value is normalized to "U" so malformed or
	 * sensitive strings can never leak into poller output.
	 *
	 * @param array<string, int|string> $metrics Collected metric map.
	 * @return string Canonical Cacti script-server output line.
	 */
	public static function format(array $metrics): string {
		$parts = [];

		foreach (self::FIELDS as $field) {
			$parts[] = $field . ':' . self::sanitizeValue($metrics[$field] ?? 'U');
		}

		return implode(' ', $parts);
	}

	/**
	 * Collect and format either all metrics as field:value pairs or a single field value.
	 *
	 * @param string $metric Optional single metric name, or empty string for all fields.
	 * @return string Formatted Cacti script-server output string.
	 */
	public function collectFormatted(string $metric = ''): string {
		$metrics = $this->collect();
		$trimmed = trim($metric);

		if ($trimmed === '') {
			return self::format($metrics);
		}

		if (!array_key_exists($trimmed, $metrics)) {
			return 'U';
		}

		return self::sanitizeValue($metrics[$trimmed]);
	}

	/**
	 * Evaluate baseline manifest validation error and drift counts without mutating state.
	 *
	 * @param CascService $service Active CasC orchestration service.
	 * @param string $baseline Configured baseline manifest path.
	 * @return array{0: int|string, 1: int|string} Tuple of [driftItems, validationErrors].
	 */
	private static function evaluateBaseline(CascService $service, string $baseline): array {
		if ($baseline === '') {
			return [0, 0];
		}

		if (!is_file($baseline) || !is_readable($baseline)) {
			return ['U', 'U'];
		}

		try {
			$raw = CascCodec::readFile($baseline);
			$doc = CascCodec::parse(CascCodec::detectFormatFromPath($baseline), $raw);
		} catch (CodecException $e) {
			return ['U', max(1, count($e->lines()))];
		}

		$options          = ['baseDir' => dirname($baseline)];
		$validationErrors = count($service->validate($doc, $options));
		$plan             = $service->plan($doc, $options, 'metrics', false);
		$counts           = $plan->counts();
		$totalErrors      = max($validationErrors, $counts['error']);

		if ($totalErrors > 0 || $plan->hasErrors()) {
			return ['U', max(1, $totalErrors)];
		}

		$driftItems = $counts['create'] + $counts['update'] + $counts['delete'];

		return [$driftItems, 0];
	}

	/**
	 * Extract non-negative apply duration in milliseconds from the most recent audit row.
	 *
	 * @param list<array<string, mixed>> $recentAudits Recent audit rows ordered newest first.
	 * @return int Non-negative duration in milliseconds, or 0 when absent.
	 */
	private static function extractLastApplyMs(array $recentAudits): int {
		if ($recentAudits === []) {
			return 0;
		}

		$rawSummary = $recentAudits[0]['summary_json'] ?? '';

		if (!is_string($rawSummary) || $rawSummary === '') {
			return 0;
		}

		$decoded = json_decode($rawSummary, true);

		if (!is_array($decoded) || !is_numeric($decoded['durationMs'] ?? null)) {
			return 0;
		}

		return max(0, (int) $decoded['durationMs']);
	}

	/**
	 * Normalize a metric value to a non-negative base-10 integer string or "U".
	 *
	 * @param mixed $value Candidate metric value.
	 * @return string Non-negative integer string or "U".
	 */
	private static function sanitizeValue(mixed $value): string {
		if (is_int($value) && $value >= 0) {
			return (string) $value;
		}

		return 'U';
	}
}
