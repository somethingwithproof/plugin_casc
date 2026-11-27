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
use Somethingwithproof\Casc\Repository\StateRepository;
use Somethingwithproof\Casc\Secret\SecretException;
use Somethingwithproof\Casc\Secret\SecretPolicy;
use Somethingwithproof\Casc\Secret\SecretResolver;

/**
 * Reconciles allowlisted declarative Cacti settings while blocking
 * installation-local cryptographic keys and exposing local binary paths under
 * reference.paths.
 */
final class SettingsProvider implements ResourceProvider {
	/**
	 * Return the settings domain name.
	 *
	 * @return string Domain identifier ('settings').
	 */
	public function domain(): string {
		return 'settings';
	}

	/**
	 * Return the JSON Schema Draft 2020-12 fragment for spec.settings.
	 *
	 * @return array<string, mixed> JSON Schema describing declarative settings and allowlistMode.
	 */
	public function describeSchema(): array {
		$valueProperties = [];

		foreach (SecretPolicy::DECLARATIVE_SETTINGS as $settingName) {
			$valueProperties[$settingName] = [
				'type' => ['string', 'integer', 'number', 'boolean', 'null'],
			];
		}

		return [
			'type'                 => 'object',
			'description'          => 'Allowlisted Cacti global settings reconciliation block.',
			'additionalProperties' => false,
			'properties'           => [
				'allowlistMode' => [
					'type'    => 'string',
					'enum'    => ['merge', 'strict'],
					'default' => 'merge',
				],
				'values' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => $valueProperties,
				],
			],
		];
	}

	/**
	 * Export allowlisted settings (with secrets redacted) and reference paths.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<string, mixed> $options Export options supporting omitSecrets.
	 * @return array{spec: array<string, mixed>, reference: array<string, mixed>}
	 */
	public function export(StateRepository $repo, array $options = []): array {
		$omitSecrets = (bool) ($options['omitSecrets'] ?? false);
		$current     = $repo->getSettings();
		$values      = [];
		$paths       = [];

		ksort($current, SORT_STRING);

		foreach ($current as $name => $raw) {
			if (SecretPolicy::isBlockedSetting($name)) {
				continue;
			}

			if (SecretPolicy::isReferencePathSetting($name)) {
				if ($raw !== '') {
					$paths[$name] = $raw;
				}

				continue;
			}

			if (!SecretPolicy::isDeclarativeSetting($name)) {
				continue;
			}

			if (SecretPolicy::isSecretSetting($name)) {
				$redacted = SecretPolicy::exportSecret('settings_' . $name, $raw, $omitSecrets);

				if ($redacted !== null) {
					$values[$name] = $redacted;
				}

				continue;
			}

			$values[$name] = self::exportTypedScalar($raw);
		}

		$spec = $values !== [] ? ['allowlistMode' => 'merge', 'values' => $values] : [];
		$ref  = $paths !== [] ? ['paths' => $paths] : [];

		return ['spec' => $spec, 'reference' => $ref];
	}

	/**
	 * Validate settings allowlist compliance, scalar types, and secret references.
	 *
	 * @param array<array-key, mixed> $desired Desired settings map.
	 * @param SecretResolver $secrets Secret reference validator.
	 * @param array<string, mixed> $options Validation options.
	 * @return list<string> Validation error messages.
	 */
	public function validate(array $desired, SecretResolver $secrets, array $options = []): array {
		$errors = [];
		$mode   = $desired['allowlistMode'] ?? 'merge';

		if (!is_string($mode) || !in_array($mode, ['merge', 'strict'], true)) {
			$errors[] = 'settings.allowlistMode must be "merge" or "strict"';
		}

		$values = $desired['values'] ?? [];

		if (!is_array($values) || ($values !== [] && array_is_list($values))) {
			$errors[] = 'settings.values must be a map of setting names to scalar values';

			return $errors;
		}

		foreach ($values as $name => $value) {
			$key = (string) $name;

			if (SecretPolicy::isBlockedSetting($key)) {
				$errors[] = sprintf('settings.values.%s is hard-blocked from CasC management', $key);

				continue;
			}

			if (SecretPolicy::isReferencePathSetting($key)) {
				$errors[] = sprintf('settings.values.%s is an installation-local path; place it under reference.paths instead', $key);

				continue;
			}

			if (!SecretPolicy::isDeclarativeSetting($key)) {
				$errors[] = sprintf('settings.values.%s is not in the declarative settings allowlist', $key);

				continue;
			}

			if (!is_scalar($value) && $value !== null) {
				$errors[] = sprintf('settings.values.%s must be a scalar value', $key);

				continue;
			}

			if (SecretPolicy::isSecretSetting($key) && is_scalar($value)) {
				try {
					$secrets->validateReference((string) $value);
				} catch (SecretException $e) {
					$errors[] = sprintf('settings.values.%s: %s', $key, $e->getMessage());
				}
			}
		}

		return $errors;
	}

	/**
	 * Plan setting creates, updates, and optional strict-mode prunes with masked secrets.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<array-key, mixed> $desired Desired settings map.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param array<string, mixed> $options Planning options supporting prune.
	 * @return list<PlanAction> Planned settings actions.
	 */
	public function plan(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$actions = [];
		$errors  = $this->validate($desired, $secrets, $options);

		foreach ($errors as $error) {
			$actions[] = new PlanAction('settings', PlanAction::ERROR, 'settings', $error);
		}

		if ($errors !== []) {
			return $actions;
		}

		$current = $repo->getSettings();
		$values  = is_array($desired['values'] ?? null) ? $desired['values'] : [];
		$mode    = is_string($desired['allowlistMode'] ?? null) ? $desired['allowlistMode'] : 'merge';
		$prune   = is_array($options['prune'] ?? null) && in_array('settings', $options['prune'], true);

		ksort($values, SORT_STRING);

		foreach ($values as $name => $desiredVal) {
			$key        = (string) $name;
			$isSecret   = SecretPolicy::isSecretSetting($key);
			$desiredStr = self::scalarToDbString($desiredVal);
			$currentStr = $current[$key] ?? null;

			if ($isSecret) {
				$resolvedDesired = $desiredStr;

				if (SecretPolicy::isSecretReference($desiredStr)) {
					try {
						$resolvedDesired = $secrets->resolve($desiredStr);
					} catch (SecretException $e) {
						$actions[] = new PlanAction('settings', PlanAction::ERROR, $key, $e->getMessage());

						continue;
					}
				}

				if ($currentStr === null) {
					$actions[] = new PlanAction(
						'settings',
						PlanAction::CREATE,
						$key,
						sprintf('Create secret setting "%s"', $key),
						['value' => ['from' => null, 'to' => SecretPolicy::maskForDiff($desiredStr)]]
					);
				} elseif (!hash_equals($currentStr, $resolvedDesired)) {
					$actions[] = new PlanAction(
						'settings',
						PlanAction::UPDATE,
						$key,
						sprintf('Update secret setting "%s"', $key),
						['value' => ['from' => SecretPolicy::maskForDiff($currentStr), 'to' => SecretPolicy::maskForDiff($desiredStr)]]
					);
				}

				continue;
			}

			if ($currentStr === null) {
				$actions[] = new PlanAction(
					'settings',
					PlanAction::CREATE,
					$key,
					sprintf('Create setting "%s"', $key),
					['value' => ['from' => null, 'to' => $desiredStr]]
				);
			} elseif ($currentStr !== $desiredStr) {
				$actions[] = new PlanAction(
					'settings',
					PlanAction::UPDATE,
					$key,
					sprintf('Update setting "%s"', $key),
					['value' => ['from' => $currentStr, 'to' => $desiredStr]]
				);
			}
		}

		if ($mode === 'strict' && $prune) {
			foreach (SecretPolicy::DECLARATIVE_SETTINGS as $allowKey) {
				if (isset($current[$allowKey]) && !array_key_exists($allowKey, $values)) {
					$actions[] = new PlanAction(
						'settings',
						PlanAction::DELETE,
						$allowKey,
						sprintf('Prune unmanaged declarative setting "%s"', $allowKey)
					);
				}
			}
		}

		return $actions;
	}

	/**
	 * Apply planned setting writes and deletes inside the active transaction.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<array-key, mixed> $desired Desired settings map.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param array<string, mixed> $options Apply options.
	 * @return array{applied: list<PlanAction>, needsReplication: bool, needsPollerCache: bool}
	 */
	public function apply(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$plan    = $this->plan($repo, $desired, $secrets, $options);
		$applied = [];
		$values  = is_array($desired['values'] ?? null) ? $desired['values'] : [];

		foreach ($plan as $action) {
			if ($action->operation === PlanAction::ERROR) {
				throw new \RuntimeException($action->summary);
			}

			if ($action->operation === PlanAction::CREATE || $action->operation === PlanAction::UPDATE) {
				$raw      = self::scalarToDbString($values[$action->naturalKey] ?? '');
				$resolved = SecretPolicy::isSecretSetting($action->naturalKey) ? $secrets->resolve($raw) : $raw;
				$repo->setSetting($action->naturalKey, $resolved);
				$applied[] = $action;
			} elseif ($action->operation === PlanAction::DELETE) {
				$repo->deleteSetting($action->naturalKey);
				$applied[] = $action;
			}
		}

		return [
			'applied'          => $applied,
			'needsReplication' => $applied !== [],
			'needsPollerCache' => false,
		];
	}

	/**
	 * Convert canonical integer strings to PHP int for typed YAML/XML export.
	 *
	 * @param string $raw Raw database setting value.
	 * @return int|string Typed integer or string scalar.
	 */
	private static function exportTypedScalar(string $raw): int|string {
		if (preg_match('/^(0|-?[1-9][0-9]{0,17})$/D', $raw) === 1) {
			return (int) $raw;
		}

		return $raw;
	}

	/**
	 * Normalize a manifest scalar into Cacti's settings table string convention.
	 *
	 * @param mixed $value Manifest scalar value.
	 * @return string Database string representation ('on'/'' for booleans).
	 */
	private static function scalarToDbString(mixed $value): string {
		if (is_bool($value)) {
			return $value ? 'on' : '';
		}

		return is_scalar($value) ? (string) $value : '';
	}
}
