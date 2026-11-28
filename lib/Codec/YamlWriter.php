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
	 * Serialize a non-empty PHP array tree into strict canonical block YAML 1.2.
	 *
	 * @param array<array-key, mixed> $data Non-empty map or list to serialize.
	 * @param int $maxDepth Maximum allowed nesting depth.
	 * @param bool $sortKeys Whether to sort map keys lexicographically.
	 * @return string Canonical block YAML text ending with a newline.
	 */
	public static function write(array $data, int $maxDepth = self::MAX_DEPTH, bool $sortKeys = true): string {
		if ($maxDepth < 1) {
			throw new CodecException('Maximum nesting depth must be at least 1');
		}

		if ($data === []) {
			throw new CodecException('An empty document cannot be written in strict block YAML');
		}

		$out = [];

		self::collection($data, 0, 1, $maxDepth, $sortKeys, $out, null);

		return implode("\n", $out) . "\n";
	}

	/**
	 * Recursively emit YAML lines for a map or list container.
	 *
	 * @param array<array-key, mixed> $data Container to emit.
	 * @param int $indent Current indentation column in spaces.
	 * @param int $depth Current nesting depth.
	 * @param int $maxDepth Maximum permitted nesting depth.
	 * @param bool $sortKeys Whether to sort map keys lexicographically.
	 * @param list<string> $out Output line accumulator.
	 * @param string|null $firstPrefix Optional prefix replacing indentation on the first emitted line (e.g. "- ").
	 * @return void
	 */
	private static function collection(array $data, int $indent, int $depth, int $maxDepth, bool $sortKeys, array &$out, ?string $firstPrefix): void {
		if ($depth > $maxDepth) {
			throw new CodecException(sprintf('Nesting is deeper than %d levels', $maxDepth));
		}

		$pad = str_repeat(' ', $indent);

		if (array_is_list($data)) {
			foreach ($data as $item) {
				if ($firstPrefix !== null) {
					$prefix      = $firstPrefix;
					$firstPrefix = null;
				} else {
					$prefix = $pad;
				}

				if (is_array($item)) {
					if ($item === [] || array_is_list($item)) {
						throw new CodecException('A list item must be a scalar or a non-empty map');
					}

					self::collection($item, $indent + 2, $depth + 1, $maxDepth, $sortKeys, $out, $prefix . '- ');

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
			if ($firstPrefix !== null) {
				$prefix      = $firstPrefix;
				$firstPrefix = null;
			} else {
				$prefix = $pad;
			}

			$name = self::scalar((string) $key);

			if (is_array($value)) {
				if ($value === []) {
					throw new CodecException(sprintf('"%s" is empty; omit empty collections in strict block YAML', (string) $key));
				}

				$out[] = $prefix . $name . ':';

				self::collection($value, $indent + 2, $depth + 1, $maxDepth, $sortKeys, $out, null);

				continue;
			}

			$out[] = $prefix . $name . ': ' . self::scalar($value);
		}
	}

	/**
	 * Format a PHP scalar or null into a safe YAML 1.2 scalar representation.
	 *
	 * @param mixed $value Scalar or null value to format.
	 * @return string Formatted YAML scalar token.
	 */
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

		if (YamlScalar::isPrintableAscii($value)) {
			if (YamlScalar::plainSafe($value)) {
				return $value;
			}

			return "'" . str_replace("'", "''", $value) . "'";
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

	/**
	 * Encode a string containing control or separator code points as a double-quoted YAML scalar.
	 *
	 * @param string $value UTF-8 string value.
	 * @return string Escaped double-quoted YAML scalar.
	 */
	private static function doubleQuoted(string $value): string {
		$escaped = preg_replace_callback(
			'/["\\\\\x00-\x1F\x7F\x{80}-\x{9F}\x{2028}\x{2029}\x{FEFF}\x{FFFE}\x{FFFF}]/u',
			static function (array $match): string {
				$char = $match[0];
				$cp   = self::codePoint($char);

				return match (true) {
					$char === '"'  => '\\"',
					$char === '\\' => '\\\\',
					$char === "\n" => '\\n',
					$char === "\t" => '\\t',
					$char === "\r" => '\\r',
					$cp === 0x2028 => '\\u2028',
					$cp === 0x2029 => '\\u2029',
					$cp === 0xFEFF => '\\uFEFF',
					$cp === 0xFFFE => '\\uFFFE',
					$cp === 0xFFFF => '\\uFFFF',
					default        => sprintf('\\u%04X', $cp),
				};
			},
			$value
		);

		return '"' . ($escaped ?? $value) . '"';
	}

	/**
	 * Decode a single UTF-8 character byte sequence into its Unicode code point.
	 *
	 * @param string $char Single UTF-8 character.
	 * @return int Unicode scalar code point.
	 */
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
