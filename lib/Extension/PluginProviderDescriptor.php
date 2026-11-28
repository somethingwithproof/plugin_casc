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

	private const RESERVED_SLUG_SET = [
		'cacti'     => true,
		'casc'      => true,
		'settings'  => true,
		'sites'     => true,
		'pollers'   => true,
		'templates' => true,
		'devices'   => true,
		'trees'     => true,
		'plugins'   => true,
	];

	/**
	 * Check whether a plugin slug is reserved by plugin_casc or Cacti core.
	 *
	 * @param string $slug Candidate plugin directory slug.
	 * @return bool True when the slug is reserved.
	 */
	public static function isReservedSlug(string $slug): bool {
		return isset(self::RESERVED_SLUG_SET[$slug]);
	}

	/** @var callable(array<string, mixed>): array<string, mixed> */
	public readonly mixed $export;

	/** @var callable(array<string, mixed>): list<string> */
	public readonly mixed $validate;

	/** @var callable(array<string, mixed>, array<string, mixed>): list<array<string, mixed>> */
	public readonly mixed $plan;

	/** @var callable(array<string, mixed>, string, array<string, mixed>): array<string, mixed> */
	public readonly mixed $apply;

	/** @var (callable(): array<string, mixed>)|null */
	public readonly mixed $describeSchema;

	/** @var array<string, mixed>|null */
	public readonly ?array $schema;

	/**
	 * Construct a validated plugin provider descriptor with optional JSON Schema metadata.
	 *
	 * @param string $slug Lowercase plugin directory slug.
	 * @param string $schemaVersion Provider schema version identifier (1 to 32 bytes).
	 * @param callable(array<string, mixed>): array<string, mixed> $export Export callback returning config/reference arrays.
	 * @param callable(array<string, mixed>): list<string> $validate Validation callback returning error messages.
	 * @param callable(array<string, mixed>, array<string, mixed>): list<array<string, mixed>> $plan Plan callback returning diff items.
	 * @param callable(array<string, mixed>, string, array<string, mixed>): array<string, mixed> $apply Apply callback returning status map.
	 * @param (callable(): array<string, mixed>)|null $describeSchema Optional dynamic JSON Schema callback.
	 * @param array<string, mixed>|null $schema Optional static JSON Schema fragment for plugin config.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $schemaVersion,
		callable $export,
		callable $validate,
		callable $plan,
		callable $apply,
		?callable $describeSchema = null,
		?array $schema = null,
	) {
		if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || self::isReservedSlug($slug)) {
			throw new \InvalidArgumentException(sprintf('Invalid or reserved plugin CasC provider slug "%s"', $slug));
		}

		if ($schemaVersion === '' || strlen($schemaVersion) > 32) {
			throw new \InvalidArgumentException('Provider schemaVersion must be a non-empty string up to 32 bytes');
		}

		$this->export         = $export;
		$this->validate       = $validate;
		$this->plan           = $plan;
		$this->apply          = $apply;
		$this->describeSchema = $describeSchema;
		$this->schema         = $schema;
	}

	/**
	 * Build a validated descriptor from a decoupled plain PHP array so sibling
	 * plugins do not need to import plugin_casc classes.
	 *
	 * @param array<array-key, mixed> $data Plain-array descriptor with slug, schemaVersion, export, validate, plan, apply, and optional schema or describeSchema.
	 * @return self Validated provider descriptor instance.
	 */
	public static function fromArray(array $data): self {
		$slug = is_string($data['slug'] ?? null) ? trim($data['slug']) : '';

		$rawVersion    = $data['schemaVersion'] ?? $data['schema_version'] ?? null;
		$schemaVersion = is_scalar($rawVersion) ? trim((string) $rawVersion) : '';

		foreach (['export', 'validate', 'plan', 'apply'] as $callbackKey) {
			if (!isset($data[$callbackKey]) || !is_callable($data[$callbackKey])) {
				throw new \InvalidArgumentException(sprintf('Plugin CasC provider descriptor requires callable "%s"', $callbackKey));
			}
		}

		$describeSchema = null;

		if (array_key_exists('describeSchema', $data) && $data['describeSchema'] !== null) {
			if (!is_callable($data['describeSchema'])) {
				throw new \InvalidArgumentException('Plugin CasC provider "describeSchema" must be callable when provided');
			}

			/** @var callable(): array<string, mixed> $describeSchema */
			$describeSchema = $data['describeSchema'];
		}

		$schema = null;

		if (array_key_exists('schema', $data) && $data['schema'] !== null) {
			if (!is_array($data['schema'])) {
				throw new \InvalidArgumentException('Plugin CasC provider "schema" must be an array when provided');
			}

			$normalized = [];

			foreach ($data['schema'] as $k => $v) {
				$normalized[(string) $k] = $v;
			}

			$schema = $normalized;
		}

		/** @var callable(array<string, mixed>): array<string, mixed> $export */
		$export = $data['export'];
		/** @var callable(array<string, mixed>): list<string> $validate */
		$validate = $data['validate'];
		/** @var callable(array<string, mixed>, array<string, mixed>): list<array<string, mixed>> $plan */
		$plan = $data['plan'];
		/** @var callable(array<string, mixed>, string, array<string, mixed>): array<string, mixed> $apply */
		$apply = $data['apply'];

		return new self(
			$slug,
			$schemaVersion,
			$export,
			$validate,
			$plan,
			$apply,
			$describeSchema,
			$schema
		);
	}
}
