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
 * 3-tier RBAC realms, Console menus, and breadcrumbs for plugin_casc.
 */
final class Pages {
	public const VIEW     = 'casc_view.php';
	public const OPERATOR = 'casc_operator.php';
	public const ADMIN    = 'casc_admin.php';

	/**
	 * @return list<array{name: string, files: list<string>, default: bool}>
	 */
	public static function realms(): array {
		return [
			['name' => __('CasC View', 'casc'), 'files' => [self::VIEW], 'default' => false],
			['name' => __('CasC Operator', 'casc'), 'files' => [self::OPERATOR], 'default' => false],
			['name' => __('CasC Admin', 'casc'), 'files' => [self::ADMIN], 'default' => true],
		];
	}

	/**
	 * @return list<string>
	 */
	public static function files(): array {
		return [self::VIEW, self::OPERATOR, self::ADMIN];
	}

	/**
	 * @return array<string, string>
	 */
	public static function menu(): array {
		return [
			self::VIEW     => __('CasC Status & Audit', 'casc'),
			self::OPERATOR => __('CasC Export & Drift', 'casc'),
			self::ADMIN    => __('CasC Plan & Apply', 'casc'),
		];
	}

	/**
	 * @return list<string>
	 */
	public static function upgradeTriggers(): array {
		return array_merge(['plugins.php'], self::files());
	}

	/**
	 * @return array<string, array{title: string, mapping: string, url: string, level: string}>
	 */
	public static function navigation(): array {
		$nav = [];

		foreach (self::menu() as $file => $title) {
			$nav[$file . ':'] = [
				'title'   => $title,
				'mapping' => 'index.php:',
				'url'     => $file,
				'level'   => '1',
			];
		}

		return $nav;
	}
}
