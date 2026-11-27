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

use Somethingwithproof\Casc\Log;

/**
 * Fault-isolated registry for plugin CasC providers (casc_register_providers).
 *
 * Mirrors plugin_mcp's scratch-clone-and-adopt isolation: each plugin hook
 * registers against a cloned scratch registry. If a plugin throws, registers
 * an invalid descriptor, or tries to claim a foreign slug, the scratch copy is
 * discarded and core state plus every other provider stay intact.
 */
final class ProviderRegistry {
	private const MAX_PROVIDERS = 128;

	/** @var array<string, PluginProviderDescriptor> */
	private array $providers = [];

	/** @var list<array{slug: string, phase: string, reason: string}> */
	private array $faults = [];

	public function register(PluginProviderDescriptor $descriptor): void {
		if (isset($this->providers[$descriptor->slug])) {
			throw new \LogicException(sprintf('Duplicate CasC provider for plugin "%s"', $descriptor->slug));
		}

		if (count($this->providers) >= self::MAX_PROVIDERS) {
			throw new \LogicException('Maximum CasC plugin provider limit reached');
		}

		$this->providers[$descriptor->slug] = $descriptor;
	}

	public function adopt(self $scratch): void {
		$this->providers = $scratch->providers;
	}

	public function has(string $slug): bool {
		return isset($this->providers[$slug]);
	}

	public function get(string $slug): ?PluginProviderDescriptor {
		return $this->providers[$slug] ?? null;
	}

	/**
	 * @return list<PluginProviderDescriptor>
	 */
	public function all(): array {
		$providers = $this->providers;
		ksort($providers, SORT_STRING);

		return array_values($providers);
	}

	/**
	 * @return list<array{slug: string, phase: string, reason: string}>
	 */
	public function faults(): array {
		return $this->faults;
	}

	/**
	 * Dispatch registered casc_register_providers hooks with per-plugin fault
	 * isolation.
	 *
	 * @param list<array{name: string, file: string, function: string}> $hooks
	 * @param (callable(string, string): (string|false))|null $fileResolver
	 */
	public function dispatchHooks(array $hooks, ?callable $fileResolver = null): void {
		foreach ($hooks as $hook) {
			$slug     = $hook['name'];
			$file     = $hook['file'];
			$function = $hook['function'];

			if (preg_match(PluginProviderDescriptor::SLUG_PATTERN, $slug) !== 1 || in_array($slug, PluginProviderDescriptor::RESERVED_SLUGS, true)) {
				$this->recordFault($slug, 'register', 'Invalid or reserved plugin slug');

				continue;
			}

			if ($file === '' || str_contains($file, '..') || str_starts_with($file, '/') || str_contains($file, "\0")) {
				$this->recordFault($slug, 'register', 'Invalid hook file path');

				continue;
			}

			$scratch = clone $this;

			try {
				if ($fileResolver !== null) {
					$resolved = $fileResolver($slug, $file);

					if (is_string($resolved) && is_file($resolved)) {
						include_once($resolved);
					}
				}

				if (!is_callable($function)) {
					$this->recordFault($slug, 'register', 'Hook callback is not callable');

					continue;
				}

				$function(new ProviderRegistrar($scratch, $slug));
				$this->adopt($scratch);
			} catch (\Throwable $error) {
				$this->recordFault($slug, 'register', $error::class . ': ' . $error->getMessage());
			}
		}
	}

	/**
	 * Fault-isolated export for a registered plugin provider.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>|null
	 */
	public function safeExport(string $slug, array $options = []): ?array {
		$provider = $this->providers[$slug] ?? null;

		if ($provider === null) {
			return null;
		}

		try {
			$exported = ($provider->export)($options);

			return $exported;
		} catch (\Throwable $error) {
			$this->recordFault($slug, 'export', $error::class);

			return null;
		}
	}

	/**
	 * Fault-isolated validate for a registered plugin provider.
	 *
	 * @param array<string, mixed> $desired
	 * @return list<string>
	 */
	public function safeValidate(string $slug, array $desired): array {
		$provider = $this->providers[$slug] ?? null;

		if ($provider === null) {
			return [sprintf('No CasC provider registered for plugin "%s"', $slug)];
		}

		try {
			return ($provider->validate)($desired);
		} catch (\Throwable $error) {
			$this->recordFault($slug, 'validate', $error::class);

			return [sprintf('Plugin "%s" CasC validator failed (%s)', $slug, $error::class)];
		}
	}

	/**
	 * Fault-isolated plan for a registered plugin provider.
	 *
	 * @param array<string, mixed> $desired
	 * @param array<string, mixed> $options
	 * @return list<array<string, mixed>>
	 */
	public function safePlan(string $slug, array $desired, array $options = []): array {
		$provider = $this->providers[$slug] ?? null;

		if ($provider === null) {
			return [[
				'operation' => 'error',
				'key'       => $slug,
				'message'   => sprintf('No CasC provider registered for plugin "%s"', $slug),
			]];
		}

		try {
			return ($provider->plan)($desired, $options);
		} catch (\Throwable $error) {
			$this->recordFault($slug, 'plan', $error::class);

			return [[
				'operation' => 'error',
				'key'       => $slug,
				'message'   => sprintf('Plugin "%s" CasC planner failed (%s)', $slug, $error::class),
			]];
		}
	}

	/**
	 * Fault-isolated apply for a registered plugin provider.
	 *
	 * @param array<string, mixed> $desired
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	public function safeApply(string $slug, array $desired, string $planHash, array $options = []): array {
		$provider = $this->providers[$slug] ?? null;

		if ($provider === null) {
			return ['ok' => false, 'error' => sprintf('No CasC provider registered for plugin "%s"', $slug)];
		}

		try {
			return ($provider->apply)($desired, $planHash, $options);
		} catch (\Throwable $error) {
			$this->recordFault($slug, 'apply', $error::class);

			return ['ok' => false, 'error' => sprintf('Plugin "%s" CasC apply failed (%s)', $slug, $error::class)];
		}
	}

	private function recordFault(string $slug, string $phase, string $reason): void {
		$safeSlug       = preg_replace('/[^A-Za-z0-9_.-]/', '_', $slug) ?? 'unknown';
		$this->faults[] = ['slug' => $safeSlug, 'phase' => $phase, 'reason' => $reason];
		Log::warning(sprintf('Plugin CasC provider "%s" fault during %s: %s', $safeSlug, $phase, $reason));
	}
}
