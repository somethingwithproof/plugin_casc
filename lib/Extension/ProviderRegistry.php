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

	/** @var list<PluginProviderDescriptor>|null */
	private ?array $sortedCache = null;

	/** @var list<array{slug: string, phase: string, reason: string}> */
	private array $faults = [];

	/**
	 * Register a validated plugin provider descriptor in this registry.
	 *
	 * @param PluginProviderDescriptor $descriptor Provider descriptor to add.
	 * @return void
	 */
	public function register(PluginProviderDescriptor $descriptor): void {
		if (isset($this->providers[$descriptor->slug])) {
			throw new \LogicException(sprintf('Duplicate CasC provider for plugin "%s"', $descriptor->slug));
		}

		if (count($this->providers) >= self::MAX_PROVIDERS) {
			throw new \LogicException('Maximum CasC plugin provider limit reached');
		}

		$this->providers[$descriptor->slug] = $descriptor;
		$this->sortedCache                  = null;
	}

	/**
	 * Adopt the validated provider map from an isolated scratch registry clone.
	 *
	 * @param self $scratch Scratch registry populated during hook dispatch.
	 * @return void
	 */
	public function adopt(self $scratch): void {
		$this->providers   = $scratch->providers;
		$this->sortedCache = null;
	}

	/**
	 * Check whether a plugin slug has a registered provider.
	 *
	 * @param string $slug Plugin directory slug.
	 * @return bool True when a provider is registered for the slug.
	 */
	public function has(string $slug): bool {
		return isset($this->providers[$slug]);
	}

	/**
	 * Retrieve the provider descriptor for a plugin slug, if registered.
	 *
	 * @param string $slug Plugin directory slug.
	 * @return PluginProviderDescriptor|null Registered descriptor or null.
	 */
	public function get(string $slug): ?PluginProviderDescriptor {
		return $this->providers[$slug] ?? null;
	}

	/**
	 * Return all registered provider descriptors sorted lexicographically by slug.
	 *
	 * @return list<PluginProviderDescriptor>
	 */
	public function all(): array {
		if ($this->sortedCache !== null) {
			return $this->sortedCache;
		}

		$providers = $this->providers;
		ksort($providers, SORT_STRING);

		return $this->sortedCache = array_values($providers);
	}

	/**
	 * Return isolated registration and runtime faults recorded by this registry.
	 *
	 * @return list<array{slug: string, phase: string, reason: string}>
	 */
	public function faults(): array {
		return $this->faults;
	}

	/**
	 * Dispatch registered casc_register_providers hooks with per-plugin fault
	 * isolation. Supports both imperative $registrar->register(...) calls and
	 * decoupled plain-array (or descriptor) return values.
	 *
	 * @param list<array{name: string, file: string, function: string}> $hooks Hook rows from plugin_hooks.
	 * @param (callable(string, string): (string|false))|null $fileResolver Optional plugin file path resolver.
	 * @return void
	 */
	public function dispatchHooks(array $hooks, ?callable $fileResolver = null): void {
		foreach ($hooks as $hook) {
			$slug     = $hook['name'];
			$file     = $hook['file'];
			$function = $hook['function'];

			if (preg_match(PluginProviderDescriptor::SLUG_PATTERN, $slug) !== 1 || PluginProviderDescriptor::isReservedSlug($slug)) {
				$this->recordFault($slug, 'register', 'Invalid or reserved plugin slug');

				continue;
			}

			if ($file === '' || str_contains($file, '..') || str_starts_with($file, '/') || str_contains($file, "\0")) {
				$this->recordFault($slug, 'register', 'Invalid hook file path');

				continue;
			}

			// Isolate each hook in an empty single-plugin scratch registry so
			// dispatching P hooks is O(P) instead of cloning O(P) providers per hook.
			$scratch = new self();

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

				$registrar = new ProviderRegistrar($scratch, $slug);
				$returned  = $function($registrar);

				if (!$scratch->has($slug) && ($returned instanceof PluginProviderDescriptor || is_array($returned))) {
					$registrar->register($returned);
				}

				foreach ($scratch->providers as $registeredDescriptor) {
					$this->register($registeredDescriptor);
				}
			} catch (\Throwable $error) {
				$this->recordFault($slug, 'register', $error::class . ': ' . $error->getMessage());
			}
		}
	}

	/**
	 * Fault-isolated export for a registered plugin provider.
	 *
	 * @param string $slug Plugin directory slug.
	 * @param array<string, mixed> $options Export options passed through to the provider.
	 * @return array<string, mixed>|null Exported config/reference map, or null on absence or fault.
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
	 * @param string $slug Plugin directory slug.
	 * @param array<string, mixed> $desired Desired plugin config block.
	 * @return list<string> Validation error messages.
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
	 * @param string $slug Plugin directory slug.
	 * @param array<string, mixed> $desired Desired plugin config block.
	 * @param array<string, mixed> $options Planner options passed through to the provider.
	 * @return list<array<string, mixed>> Planned operation descriptors.
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
	 * @param string $slug Plugin directory slug.
	 * @param array<string, mixed> $desired Desired plugin config block.
	 * @param string $planHash Confirmed SHA-256 plan hash.
	 * @param array<string, mixed> $options Apply options passed through to the provider.
	 * @return array<string, mixed> Provider apply status map.
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

	/**
	 * Fault-isolated JSON Schema retrieval for a registered plugin provider.
	 *
	 * @param string $slug Plugin directory slug.
	 * @return array<string, mixed>|null JSON Schema fragment, or null if none provided or on fault.
	 */
	public function safeDescribeSchema(string $slug): ?array {
		$provider = $this->providers[$slug] ?? null;

		if ($provider === null) {
			return null;
		}

		if ($provider->describeSchema !== null) {
			try {
				$described = ($provider->describeSchema)();

				if (is_array($described)) {
					return $described;
				}

				$this->recordFault($slug, 'schema', 'describeSchema callback did not return an array');

				return null;
			} catch (\Throwable $error) {
				$this->recordFault($slug, 'schema', $error::class);

				return null;
			}
		}

		return $provider->schema;
	}

	/**
	 * Record a sanitized provider fault and write a warning to the Cacti log.
	 *
	 * @param string $slug Raw plugin slug.
	 * @param string $phase Lifecycle phase where the fault occurred.
	 * @param string $reason Diagnostic reason string.
	 * @return void
	 */
	private function recordFault(string $slug, string $phase, string $reason): void {
		$safeSlug       = preg_replace('/[^A-Za-z0-9_.-]/', '_', $slug) ?? 'unknown';
		$this->faults[] = ['slug' => $safeSlug, 'phase' => $phase, 'reason' => $reason];
		Log::warning(sprintf('Plugin CasC provider "%s" fault during %s: %s', $safeSlug, $phase, $reason));
	}
}
