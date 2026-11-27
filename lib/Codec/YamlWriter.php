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

namespace Somethingwithproof\Casc\Codec;

/**
 * Deterministic block-style YAML 1.2 writer for the strict subset YamlReader
 * accepts.
 *
 * Maps are emitted with sorted string keys (except optional top-level envelope
 * ordering preserved when requested) and 2-space indentation. C0, DEL, C1
 * controls (including NEL U+0085), line/paragraph separators, BOM, and Unicode
 * noncharacters U+FFFE/U+FFFF are escaped inside double-quoted scalars.
 */
final class YamlWriter {
	public const MAX_DEPTH = YamlReader::MAX_DEPTH;

	/**
	 * @param array<array-key, mixed> $data
	 */
	public static function write(array $data, int $maxDepth = self::MAX_DEPTH, bool $sortKeys = true): string {
		if ($maxDepth < 1) {
			throw new CodecException('Maximum nesting depth must be at least 1');
		}

		if ($data === []) {
			throw new CodecException('An empty document cannot be written in strict block YAML');
		}

		$out = [];

		self::collection($data, 0, 1, $maxDepth, $sortKeys, $out, false);

		return implode("\n", $out) . "\n";
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @param list<string> $out
	 */
	private static function collection(array $data, int $indent, int $depth, int $maxDepth, bool $sortKeys, array &$out, bool $firstInline): void {
		if ($depth > $maxDepth) {
			throw new CodecException(sprintf('Nesting is deeper than %d levels', $maxDepth));
		}

		$pad   = str_repeat(' ', $indent);
		$first = $firstInline;

		if (array_is_list($data)) {
			foreach ($data as $item) {
				$prefix = $first ? '' : $pad;
				$first  = false;

				if (is_array($item)) {
					if ($item === [] || array_is_list($item)) {
						throw new CodecException('A list item must be a scalar or a non-empty map');
					}

					$block = [];

					self::collection($item, $indent + 2, $depth + 1, $maxDepth, $sortKeys, $block, true);
					$block[0] = $prefix . '- ' . $block[0];

					array_push($out, ...$block);

					continue;
				}

				$out[] = $prefix . '- ' . self::scalar($item);
			}

			return;
		}

		if ($sortKeys) {
			ksort($data, SORT_STRING);
		}

		foreach ($data as $key => $value) {
			$prefix = $first ? '' : $pad;
			$first  = false;
			$name   = self::scalar((string) $key);

			if (is_array($value)) {
				if ($value === []) {
					throw new CodecException(sprintf('"%s" is empty; omit empty collections in strict block YAML', (string) $key));
				}

				$out[] = $prefix . $name . ':';

				self::collection($value, $indent + 2, $depth + 1, $maxDepth, $sortKeys, $out, false);

				continue;
			}

			$out[] = $prefix . $name . ': ' . self::scalar($value);
		}
	}

	private static function scalar(mixed $value): string {
		if ($value === null) {
			return 'null';
		}

		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		if (is_int($value)) {
			return (string) $value;
		}

		if (is_float($value)) {
			if (!is_finite($value)) {
				throw new CodecException('Non-finite floats (INF, NAN) cannot be written');
			}

			$formatted = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

			if (preg_match('/^-?(0|[1-9][0-9]{0,14})\.[0-9]{1,12}$/D', $formatted) !== 1) {
				$formatted = rtrim(sprintf('%.10F', $value), '0');

				if (str_ends_with($formatted, '.')) {
					$formatted .= '0';
				}
			}

			return $formatted;
		}

		if (!is_string($value)) {
			throw new CodecException('Unsupported value type ' . get_debug_type($value));
		}

		if (preg_match('//u', $value) !== 1) {
			throw new CodecException('A string value is not valid UTF-8');
		}

		if (YamlScalar::plainSafe($value)) {
			return $value;
		}

		if (preg_match('/[\x00-\x1F\x7F\x{80}-\x{9F}\x{2028}\x{2029}\x{FEFF}\x{FFFE}\x{FFFF}]/u', $value) === 1) {
			return self::doubleQuoted($value);
		}

		return "'" . str_replace("'", "''", $value) . "'";
	}

	private static function doubleQuoted(string $value): string {
		$out = '';

		foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
			$cp = self::codePoint($char);

			$out .= match (true) {
				$char === '"'  => '\\"',
				$char === '\\' => '\\\\',
				$char === "\n" => '\\n',
				$char === "\t" => '\\t',
				$char === "\r" => '\\r',
				$cp < 0x20 || $cp === 0x7F || ($cp >= 0x80 && $cp <= 0x9F) => sprintf('\\u%04X', $cp),
				$cp === 0x2028 => '\\u2028',
				$cp === 0x2029 => '\\u2029',
				$cp === 0xFEFF => '\\uFEFF',
				$cp === 0xFFFE => '\\uFFFE',
				$cp === 0xFFFF => '\\uFFFF',
				default        => $char,
			};
		}

		return '"' . $out . '"';
	}

	private static function codePoint(string $char): int {
		$b0 = ord($char[0]);

		if ($b0 < 0x80) {
			return $b0;
		}

		if (($b0 & 0xE0) === 0xC0 && strlen($char) >= 2) {
			return (($b0 & 0x1F) << 6) | (ord($char[1]) & 0x3F);
		}

		if (($b0 & 0xF0) === 0xE0 && strlen($char) >= 3) {
			return (($b0 & 0x0F) << 12) | ((ord($char[1]) & 0x3F) << 6) | (ord($char[2]) & 0x3F);
		}

		if (($b0 & 0xF8) === 0xF0 && strlen($char) >= 4) {
			return (($b0 & 0x07) << 18) | ((ord($char[1]) & 0x3F) << 12) | ((ord($char[2]) & 0x3F) << 6) | (ord($char[3]) & 0x3F);
		}

		return 0;
	}
}
