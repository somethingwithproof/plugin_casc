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

namespace Somethingwithproof\Casc\Extension;

/**
 * Validated descriptor registered by a sibling Cacti plugin via the
 * casc_register_providers hook.
 */
final class PluginProviderDescriptor {
	public const SLUG_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

	public const RESERVED_SLUGS = [
		'cacti',
		'casc',
		'settings',
		'sites',
		'pollers',
		'templates',
		'devices',
		'trees',
		'plugins',
	];

	/** @var callable(array<string, mixed>): array<string, mixed> */
	public readonly mixed $export;

	/** @var callable(array<string, mixed>): list<string> */
	public readonly mixed $validate;

	/** @var callable(array<string, mixed>, array<string, mixed>): list<array<string, mixed>> */
	public readonly mixed $plan;

	/** @var callable(array<string, mixed>, string, array<string, mixed>): array<string, mixed> */
	public readonly mixed $apply;

	/**
	 * @param callable(array<string, mixed>): array<string, mixed> $export
	 * @param callable(array<string, mixed>): list<string> $validate
	 * @param callable(array<string, mixed>, array<string, mixed>): list<array<string, mixed>> $plan
	 * @param callable(array<string, mixed>, string, array<string, mixed>): array<string, mixed> $apply
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $schemaVersion,
		callable $export,
		callable $validate,
		callable $plan,
		callable $apply,
	) {
		if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || in_array($slug, self::RESERVED_SLUGS, true)) {
			throw new \InvalidArgumentException(sprintf('Invalid or reserved plugin CasC provider slug "%s"', $slug));
		}

		if ($schemaVersion === '' || strlen($schemaVersion) > 32) {
			throw new \InvalidArgumentException('Provider schemaVersion must be a non-empty string up to 32 bytes');
		}

		$this->export   = $export;
		$this->validate = $validate;
		$this->plan     = $plan;
		$this->apply    = $apply;
	}
}
