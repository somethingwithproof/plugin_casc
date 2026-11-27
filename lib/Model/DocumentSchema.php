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

namespace Somethingwithproof\Casc\Model;

use Somethingwithproof\Casc\Codec\CodecException;
use Somethingwithproof\Casc\Codec\ParsedTree;

/**
 * Validates a decoded YAML or XML ParsedTree and builds a CascDocument.
 */
final class DocumentSchema {
	private const TOP_KEYS  = ['apiVersion', 'kind', 'metadata', 'spec', 'reference'];
	private const SPEC_KEYS = ['settings', 'sites', 'pollers', 'templates', 'devices', 'trees', 'plugins'];

	/**
	 * Validate a parsed YAML or XML tree and construct a CascDocument.
	 *
	 * @param ParsedTree $tree Decoded document tree with line number metadata.
	 * @return CascDocument Validated immutable manifest document.
	 */
	public static function build(ParsedTree $tree): CascDocument {
		$errors = self::validateTree($tree);

		if ($errors !== []) {
			throw new CodecException(
				$errors[0]->format(),
				$errors[0]->line,
				array_map(static fn (ValidationError $e): string => $e->format(), $errors)
			);
		}

		$data      = $tree->data;
		$metadata  = isset($data['metadata']) && is_array($data['metadata']) ? self::stringKeyMap($data['metadata']) : [];
		$spec      = isset($data['spec']) && is_array($data['spec']) ? self::stringKeyMap($data['spec']) : [];
		$reference = isset($data['reference']) && is_array($data['reference']) ? self::stringKeyMap($data['reference']) : [];

		$apiVersion = is_string($data['apiVersion'] ?? null) ? $data['apiVersion'] : CascDocument::API_VERSION;
		$kind       = is_string($data['kind'] ?? null) ? $data['kind'] : CascDocument::KIND;

		return new CascDocument($apiVersion, $kind, $metadata, $spec, $reference);
	}

	/**
	 * Validate top-level keys, apiVersion, kind, metadata scalars, spec domains,
	 * and forbid auto-increment id fields.
	 *
	 * @param ParsedTree $tree Decoded document tree to validate.
	 * @return list<ValidationError> List of schema validation errors.
	 */
	public static function validateTree(ParsedTree $tree): array {
		$data   = $tree->data;
		$errors = [];

		if ($data === [] || array_is_list($data)) {
			return [new ValidationError('/', 'Manifest root must be a non-empty map', 1)];
		}

		foreach (array_keys($data) as $key) {
			$k = (string) $key;

			if (!in_array($k, self::TOP_KEYS, true)) {
				$errors[] = new ValidationError($k, sprintf('Unknown top-level key "%s"', $k), $tree->lineFor($k));
			}
		}

		if (($data['apiVersion'] ?? null) !== CascDocument::API_VERSION) {
			$errors[] = new ValidationError(
				'apiVersion',
				sprintf('apiVersion must be "%s"', CascDocument::API_VERSION),
				$tree->lineFor('apiVersion')
			);
		}

		if (($data['kind'] ?? null) !== CascDocument::KIND) {
			$errors[] = new ValidationError(
				'kind',
				sprintf('kind must be "%s"', CascDocument::KIND),
				$tree->lineFor('kind')
			);
		}

		if (isset($data['metadata'])) {
			if (!is_array($data['metadata']) || ($data['metadata'] !== [] && array_is_list($data['metadata']))) {
				$errors[] = new ValidationError('metadata', 'metadata must be a map', $tree->lineFor('metadata'));
			} else {
				foreach ($data['metadata'] as $mKey => $mVal) {
					if (!is_scalar($mVal) && $mVal !== null) {
						$path     = 'metadata/' . (string) $mKey;
						$errors[] = new ValidationError($path, 'metadata values must be scalars', $tree->lineFor($path));
					}
				}
			}
		}

		if (isset($data['spec'])) {
			if (!is_array($data['spec']) || ($data['spec'] !== [] && array_is_list($data['spec']))) {
				$errors[] = new ValidationError('spec', 'spec must be a map', $tree->lineFor('spec'));
			} else {
				foreach (array_keys($data['spec']) as $sKey) {
					$sk = (string) $sKey;

					if (!in_array($sk, self::SPEC_KEYS, true)) {
						$path     = 'spec/' . $sk;
						$errors[] = new ValidationError($path, sprintf('Unknown spec domain "%s"', $sk), $tree->lineFor($path));
					}
				}

				self::rejectAutoIncrementIds($data['spec'], 'spec', $tree, $errors);
			}
		}

		if (isset($data['reference']) && (!is_array($data['reference']) || ($data['reference'] !== [] && array_is_list($data['reference'])))) {
			$errors[] = new ValidationError('reference', 'reference must be a map', $tree->lineFor('reference'));
		}

		return $errors;
	}

	/**
	 * Natural keys are required across all declarative resources; forbid raw
	 * auto-increment "id" fields on core resources so manifests stay portable.
	 *
	 * @param array<array-key, mixed> $spec Manifest spec section.
	 * @param string $basePath Path prefix for error reporting.
	 * @param ParsedTree $tree Source parsed tree for line lookup.
	 * @param list<ValidationError> $errors Validation error accumulator.
	 * @return void
	 */
	private static function rejectAutoIncrementIds(array $spec, string $basePath, ParsedTree $tree, array &$errors): void {
		foreach (['sites', 'pollers', 'devices', 'trees'] as $domain) {
			if (!isset($spec[$domain]) || !is_array($spec[$domain])) {
				continue;
			}

			foreach ($spec[$domain] as $idx => $entry) {
				if (is_array($entry) && array_key_exists('id', $entry)) {
					$path     = $basePath . '/' . $domain . '/' . (string) $idx . '/id';
					$errors[] = new ValidationError(
						$path,
						'Auto-increment "id" is forbidden in CasC manifests; use natural keys instead',
						$tree->lineFor($path) ?? $tree->lineFor($basePath . '/' . $domain . '/' . (string) $idx)
					);
				}
			}
		}
	}

	/**
	 * @param array<array-key, mixed> $input
	 * @return array<string, mixed>
	 */
	private static function stringKeyMap(array $input): array {
		$out = [];

		foreach ($input as $key => $value) {
			$out[(string) $key] = $value;
		}

		return $out;
	}
}
