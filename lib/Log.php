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
 * Thin Cacti logging adapter emitting sanitized messages under the CASC environment tag.
 */
final class Log {
	private const ENVIRON = 'CASC';

	/**
	 * Log an informational message to Cacti's log when cacti_log() is available.
	 *
	 * @param string $message Informational log message.
	 * @return void
	 */
	public static function info(string $message): void {
		if (function_exists('cacti_log')) {
			cacti_log('NOTE: ' . $message, false, self::ENVIRON);
		}
	}

	/**
	 * Log a warning message to Cacti's log when cacti_log() is available.
	 *
	 * @param string $message Warning log message.
	 * @return void
	 */
	public static function warning(string $message): void {
		if (function_exists('cacti_log')) {
			cacti_log('WARNING: ' . $message, false, self::ENVIRON);
		}
	}

	/**
	 * Log an error message to Cacti's log when cacti_log() is available.
	 *
	 * @param string $message Error log message.
	 * @return void
	 */
	public static function error(string $message): void {
		if (function_exists('cacti_log')) {
			cacti_log('ERROR: ' . $message, false, self::ENVIRON);
		}
	}
}
