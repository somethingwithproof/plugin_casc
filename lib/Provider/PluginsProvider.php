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

namespace Somethingwithproof\Casc\Provider;

use Somethingwithproof\Casc\Engine\PlanAction;
use Somethingwithproof\Casc\Extension\ProviderRegistry;
use Somethingwithproof\Casc\Repository\StateRepository;
use Somethingwithproof\Casc\Secret\SecretResolver;

/**
 * Manages Cacti plugin enablement state and delegates per-plugin config blocks
 * to registered casc_register_providers handlers with fault isolation.
 */
final class PluginsProvider implements ResourceProvider {
	public function __construct(
		private readonly ProviderRegistry $pluginRegistry,
	) {
	}

	public function domain(): string {
		return 'plugins';
	}

	public function export(StateRepository $repo, array $options = []): array {
		$rows      = $repo->getPlugins();
		$installed = [];
		$reference = [];

		usort($rows, static fn (array $a, array $b): int => strcmp($a['directory'], $b['directory']));

		foreach ($rows as $row) {
			$slug  = $row['directory'];
			$entry = [
				'slug'    => $slug,
				'enabled' => $row['status'] === 1,
			];

			if ($this->pluginRegistry->has($slug)) {
				$exported = $this->pluginRegistry->safeExport($slug, $options);

				if (is_array($exported)) {
					if (isset($exported['config']) && is_array($exported['config']) && $exported['config'] !== []) {
						$entry['config'] = $exported['config'];
					}

					if (isset($exported['reference']) && is_array($exported['reference']) && $exported['reference'] !== []) {
						$reference[$slug] = $exported['reference'];
					}
				}
			}

			$installed[] = $entry;
		}

		$spec = $installed !== [] ? ['installed' => $installed] : [];
		$ref  = $reference !== [] ? ['plugins' => $reference] : [];

		return ['spec' => $spec, 'reference' => $ref];
	}

	public function validate(array $desired, SecretResolver $secrets, array $options = []): array {
		$errors    = [];
		$installed = $desired['installed'] ?? [];

		if (!is_array($installed) || ($installed !== [] && !array_is_list($installed))) {
			return ['plugins.installed must be a list of plugin objects'];
		}

		$seen = [];

		foreach ($installed as $idx => $entry) {
			if (!is_array($entry)) {
				$errors[] = sprintf('plugins.installed[%d] must be a map', (int) $idx);

				continue;
			}

			$slug = is_string($entry['slug'] ?? null) ? trim($entry['slug']) : '';

			if ($slug === '' || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $slug) !== 1) {
				$errors[] = sprintf('plugins.installed[%d].slug must be a valid plugin directory slug', (int) $idx);

				continue;
			}

			if (isset($seen[$slug])) {
				$errors[] = sprintf('Duplicate plugin slug "%s"', $slug);
			}

			$seen[$slug] = true;

			if (isset($entry['config'])) {
				if (!is_array($entry['config']) || ($entry['config'] !== [] && array_is_list($entry['config']))) {
					$errors[] = sprintf('plugins.installed[%d].config must be a map', (int) $idx);

					continue;
				}

				if ($this->pluginRegistry->has($slug)) {
					$pluginErrors = $this->pluginRegistry->safeValidate($slug, $this->stringKeyMap($entry['config']));

					foreach ($pluginErrors as $pErr) {
						$errors[] = sprintf('plugins.%s: %s', $slug, $pErr);
					}
				}
			}
		}

		return $errors;
	}

	public function plan(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$actions = [];
		$errors  = $this->validate($desired, $secrets, $options);

		foreach ($errors as $error) {
			$actions[] = new PlanAction('plugins', PlanAction::ERROR, 'plugins', $error);
		}

		if ($errors !== []) {
			return $actions;
		}

		$installed = is_array($desired['installed'] ?? null) ? $desired['installed'] : [];
		$current   = [];

		foreach ($repo->getPlugins() as $row) {
			$current[$row['directory']] = $row;
		}

		foreach ($installed as $entry) {
			if (!is_array($entry) || !is_string($entry['slug'] ?? null)) {
				continue;
			}

			$slug       = trim($entry['slug']);
			$wantEnable = (bool) ($entry['enabled'] ?? true);
			$existing   = $current[$slug] ?? null;

			if ($existing === null) {
				$actions[] = new PlanAction(
					'plugins',
					PlanAction::CREATE,
					$slug,
					sprintf('Install and %s plugin "%s"', $wantEnable ? 'enable' : 'register', $slug),
					['enabled' => ['from' => false, 'to' => $wantEnable]]
				);
			} else {
				$isEnabled = $existing['status'] === 1;

				if ($isEnabled !== $wantEnable) {
					$actions[] = new PlanAction(
						'plugins',
						PlanAction::UPDATE,
						$slug,
						sprintf('%s plugin "%s"', $wantEnable ? 'Enable' : 'Disable', $slug),
						['enabled' => ['from' => $isEnabled, 'to' => $wantEnable]]
					);
				}
			}

			if (isset($entry['config']) && is_array($entry['config']) && $entry['config'] !== []) {
				if (!$this->pluginRegistry->has($slug)) {
					$actions[] = new PlanAction(
						'plugin:' . $slug,
						PlanAction::WARNING,
						$slug,
						sprintf('Plugin "%s" has declarative config in manifest, but no CasC provider is registered', $slug)
					);

					continue;
				}

				$pluginPlan = $this->pluginRegistry->safePlan($slug, $this->stringKeyMap($entry['config']), $options);

				foreach ($pluginPlan as $item) {
					$op      = is_string($item['operation'] ?? null) ? $item['operation'] : PlanAction::UPDATE;
					$itemKey = is_string($item['key'] ?? null) ? $item['key'] : $slug;
					$msg     = is_string($item['message'] ?? null) ? $item['message'] : sprintf('Reconcile %s/%s', $slug, $itemKey);
					$actions[] = new PlanAction('plugin:' . $slug, $op, $itemKey, $msg);
				}
			}
		}

		return $actions;
	}

	public function apply(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$plan      = $this->plan($repo, $desired, $secrets, $options);
		$planHash  = is_string($options['planHash'] ?? null) ? $options['planHash'] : '';
		$applied   = [];
		$installed = is_array($desired['installed'] ?? null) ? $desired['installed'] : [];

		$bySlug = [];

		foreach ($installed as $entry) {
			if (is_array($entry) && is_string($entry['slug'] ?? null)) {
				$bySlug[trim($entry['slug'])] = $entry;
			}
		}

		$appliedPlugins = [];

		foreach ($plan as $action) {
			if ($action->operation === PlanAction::ERROR) {
				throw new \RuntimeException($action->summary);
			}

			if ($action->domain === 'plugins' && $action->isMutation()) {
				$entry      = $bySlug[$action->naturalKey] ?? [];
				$wantEnable = (bool) ($entry['enabled'] ?? true);
				$repo->setPluginEnabled($action->naturalKey, $wantEnable);
				$applied[] = $action;

				continue;
			}

			if (str_starts_with($action->domain, 'plugin:') && $action->isMutation()) {
				$slug = substr($action->domain, 7);

				if (isset($appliedPlugins[$slug])) {
					$applied[] = $action;

					continue;
				}

				$entry  = $bySlug[$slug] ?? [];
				$config = isset($entry['config']) && is_array($entry['config']) ? $this->stringKeyMap($entry['config']) : [];
				$result = $this->pluginRegistry->safeApply($slug, $config, $planHash, $options);

				if (($result['ok'] ?? true) === false) {
					// Isolate plugin failure: record fault, do not corrupt core state.
					continue;
				}

				$appliedPlugins[$slug] = true;
				$applied[]             = $action;
			}
		}

		return [
			'applied'          => $applied,
			'needsReplication' => $applied !== [],
			'needsPollerCache' => false,
		];
	}

	/**
	 * @param array<array-key, mixed> $input
	 * @return array<string, mixed>
	 */
	private function stringKeyMap(array $input): array {
		$out = [];

		foreach ($input as $k => $v) {
			$out[(string) $k] = $v;
		}

		return $out;
	}
}
