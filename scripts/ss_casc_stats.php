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

/*
 * Cacti script-server entry point for self-graphing plugin_casc telemetry.
 * Kept PHP 8.0 parseable so Cacti script servers on unsupported PHP versions
 * receive RRDtool unknown ("U") values instead of a fatal parse error.
 */

if (!isset($called_by_script_server)) {
	if (PHP_SAPI !== 'cli') {
		exit(1);
	}

	$dir = __DIR__;
	chdir($dir . '/../../..');
	include_once('./include/cli_check.php');

	$arg = isset($argv[1]) ? (string) $argv[1] : '';
	print call_user_func('ss_casc_stats', $arg) . PHP_EOL;
}

/**
 * Emit secret-free Cacti field:value telemetry metrics for plugin_casc.
 *
 * Returns RRDtool unknown ("U") values on unsupported PHP (< 8.2.0) or any
 * runtime collection error so Cacti graphs record clean gaps instead of false
 * zeroes.
 *
 * @param string $metric Optional single metric field name, or empty string for all fields.
 * @return string Space-separated field:value telemetry line, single metric value, or "U".
 */
function ss_casc_stats($metric = '') {
	$metricName  = is_scalar($metric) ? trim((string) $metric) : '';
	$unknownLine = 'providers_registered:U drift_items:U last_plan_actions:U last_apply_ms:U validation_errors:U secret_dirs_configured:U';

	if (!version_compare(PHP_VERSION, '8.2.0', '>=')) {
		return $metricName === '' ? $unknownLine : 'U';
	}

	require_once(dirname(__DIR__) . '/setup.php');

	if (!function_exists('casc_boot') || !casc_boot()) {
		return $metricName === '' ? $unknownLine : 'U';
	}

	try {
		$collector = new \Somethingwithproof\Casc\Metrics\CascMetricsCollector(
			static function () {
				return casc_service();
			},
			static function () {
				return casc_settings();
			}
		);

		return $collector->collectFormatted($metricName);
	} catch (\Throwable $e) {
		return $metricName === '' ? $unknownLine : 'U';
	}
}
