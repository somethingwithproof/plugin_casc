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
 * Namespace-scoped registrar passed to a plugin's casc_register_providers hook
 * so a plugin can register only its own slug.
 */
final class ProviderRegistrar {
	public function __construct(
		private readonly ProviderRegistry $registry,
		private readonly string $expectedSlug,
	) {
	}

	public function register(PluginProviderDescriptor $descriptor): void {
		if ($descriptor->slug !== $this->expectedSlug) {
			throw new \LogicException(sprintf('Plugin "%s" cannot register provider for foreign slug "%s"', $this->expectedSlug, $descriptor->slug));
		}

		$this->registry->register($descriptor);
	}
}
