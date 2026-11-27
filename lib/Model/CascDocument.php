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

/**
 * Canonical intermediate representation (IR) for a Cacti CasC manifest.
 *
 * Both YAML and XML codecs decode into this immutable value object and encode
 * from it, keeping desired operational state (spec) separated from
 * installation-local metadata (reference).
 */
final class CascDocument {
	public const API_VERSION = 'cacti.net/v1alpha1';
	public const KIND        = 'CactiConfig';

	/**
	 * @param array<string, mixed> $metadata
	 * @param array<string, mixed> $spec
	 * @param array<string, mixed> $reference
	 */
	public function __construct(
		public readonly string $apiVersion,
		public readonly string $kind,
		public readonly array $metadata,
		public readonly array $spec,
		public readonly array $reference = [],
	) {
	}

	/**
	 * Produce a deterministic canonical array suitable for both YamlWriter and
	 * XmlCodec. Empty arrays are omitted so strict block YAML and XML stay
	 * canonically equivalent.
	 *
	 * @return array<string, mixed>
	 */
	public function toCanonicalArray(): array {
		$doc = [
			'apiVersion' => $this->apiVersion,
			'kind'       => $this->kind,
		];

		$metadata = self::canonicalizeMap($this->metadata);

		if ($metadata !== []) {
			$doc['metadata'] = $metadata;
		}

		$spec = self::canonicalizeMap($this->spec);

		if ($spec !== []) {
			$doc['spec'] = $spec;
		}

		$reference = self::canonicalizeMap($this->reference);

		if ($reference !== []) {
			$doc['reference'] = $reference;
		}

		return $doc;
	}

	/**
	 * @param array<array-key, mixed> $map
	 * @return array<string, mixed>
	 */
	public static function canonicalizeMap(array $map): array {
		$out = [];

		foreach ($map as $key => $value) {
			$k = (string) $key;

			if (is_array($value)) {
				$clean = array_is_list($value) ? self::canonicalizeList($value) : self::canonicalizeMap($value);

				if ($clean === []) {
					continue;
				}

				$out[$k] = $clean;

				continue;
			}

			$out[$k] = $value;
		}

		ksort($out, SORT_STRING);

		return $out;
	}

	/**
	 * @param list<mixed> $list
	 * @return list<mixed>
	 */
	private static function canonicalizeList(array $list): array {
		$out = [];

		foreach ($list as $item) {
			if (is_array($item)) {
				$clean = array_is_list($item) ? self::canonicalizeList($item) : self::canonicalizeMap($item);

				if ($clean !== []) {
					$out[] = $clean;
				}

				continue;
			}

			$out[] = $item;
		}

		return $out;
	}
}
