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
 * Strict YAML 1.2 plain scalar classifier shared by YamlReader and YamlWriter.
 *
 * Only true, false, null, ~, empty values, canonical decimal integers, and
 * canonical decimal floats are typed from plain scalars. Any plain scalar that
 * YAML 1.1 parsers interpret ambiguously (yes/no/on/off, leading-zero octal,
 * sexagesimal, unquoted ISO dates, .inf/.nan, scientific notation, =, <<) is
 * flagged as ambiguous so the reader refuses it and the writer quotes it.
 */
final class YamlScalar {
	public const STRING    = 'string';
	public const NIL       = 'null';
	public const BOOL      = 'bool';
	public const INT       = 'int';
	public const FLOAT     = 'float';
	public const AMBIGUOUS = 'ambiguous';

	/**
	 * Printable ASCII characters (0x20..0x7E) for fast C-level strspn() checks.
	 */
	private const PRINTABLE_ASCII = " !\"#$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~";

	/**
	 * ASCII characters forbidden as the first byte of a plain YAML scalar.
	 */
	private const FORBIDDEN_PLAIN_START = " \t\n\r\x0B\x0C-?:,[]{}#&*!|>'\"%@`~=<";

	/**
	 * Exact-case YAML 1.2 null tokens.
	 */
	private const NULL_TOKENS = [
		''     => true,
		'~'    => true,
		'null' => true,
		'Null' => true,
		'NULL' => true,
	];

	/**
	 * Exact-case YAML 1.2 true tokens.
	 */
	private const TRUE_TOKENS = [
		'true' => true,
		'True' => true,
		'TRUE' => true,
	];

	/**
	 * Exact-case YAML 1.2 false tokens.
	 */
	private const FALSE_TOKENS = [
		'false' => true,
		'False' => true,
		'FALSE' => true,
	];

	/**
	 * Lowercase YAML 1.1 boolean tokens flagged as ambiguous.
	 */
	private const AMBIGUOUS_WORDS = [
		'y'   => true,
		'n'   => true,
		'yes' => true,
		'no'  => true,
		'on'  => true,
		'off' => true,
	];

	/**
	 * Check whether every byte in $text is printable ASCII (0x20..0x7E).
	 *
	 * @param string $text Candidate string.
	 * @return bool True when $text contains only printable 7-bit ASCII bytes.
	 */
	public static function isPrintableAscii(string $text): bool {
		return strspn($text, self::PRINTABLE_ASCII) === strlen($text);
	}

	/**
	 * Classify a plain YAML 1.2 scalar into its canonical type and value.
	 *
	 * @param string $text Unquoted scalar text.
	 * @return array{0: string, 1: null|bool|int|float|string}
	 */
	public static function classify(string $text): array {
		if (isset(self::NULL_TOKENS[$text])) {
			return [self::NIL, null];
		}

		if (isset(self::TRUE_TOKENS[$text])) {
			return [self::BOOL, true];
		}

		if (isset(self::FALSE_TOKENS[$text])) {
			return [self::BOOL, false];
		}

		if ($text === '=' || $text === '<<') {
			return [self::AMBIGUOUS, $text];
		}

		$len = strlen($text);

		if ($len <= 3 && isset(self::AMBIGUOUS_WORDS[strtolower($text)])) {
			return [self::AMBIGUOUS, $text];
		}

		$c0 = $text[0];

		// Only scalars beginning with a digit, sign, or dot can match numeric,
		// float, octal/hex/bin, sexagesimal, ISO date, or .inf/.nan patterns.
		if (($c0 >= '0' && $c0 <= '9') || $c0 === '-' || $c0 === '+' || $c0 === '.') {
			if (preg_match('/^(0|-?[1-9][0-9]{0,17})$/D', $text) === 1) {
				return [self::INT, (int) $text];
			}

			if (preg_match('/^-?(0|[1-9][0-9]{0,14})\.[0-9]{1,12}$/D', $text) === 1) {
				return [self::FLOAT, (float) $text];
			}

			if (
				preg_match('/^[-+]?(\.[0-9]+|[0-9]+(\.[0-9]*)?)([eE][-+]?[0-9]+)?$/D', $text) === 1
				|| preg_match('/^[-+]?0[xXoObB]/', $text) === 1
				|| preg_match('/^[-+]?\.(inf|nan)$/iD', $text) === 1
				|| preg_match('/^[-+]?[0-9][0-9_]*(:[0-5]?[0-9])+(\.[0-9]*)?$/D', $text) === 1
				|| preg_match('/^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}/', $text) === 1
			) {
				return [self::AMBIGUOUS, $text];
			}
		}

		return [self::STRING, $text];
	}

	/**
	 * Whether the writer may emit the string unquoted without ambiguity.
	 *
	 * @param string $text Candidate string scalar.
	 * @return bool True when emitting $text unquoted is unambiguous in YAML 1.1 and 1.2.
	 */
	public static function plainSafe(string $text): bool {
		if ($text === '') {
			return false;
		}

		if (str_contains(self::FORBIDDEN_PLAIN_START, $text[0]) || strpbrk($text, ':#') !== false) {
			return false;
		}

		$last = $text[strlen($text) - 1];

		if ($last === ' ' || $last === "\t" || $last === "\n" || $last === "\r" || $last === "\x0B" || $last === "\x0C") {
			return false;
		}

		if (self::classify($text)[0] !== self::STRING) {
			return false;
		}

		if (self::isPrintableAscii($text)) {
			return true;
		}

		if (preg_match('/^[^\s\-?:,\[\]{}#&*!|>\'"%@`~=<]/u', $text) !== 1 || preg_match('/[\s]$/u', $text) === 1) {
			return false;
		}

		return preg_match('/[\x00-\x1F\x7F\x{80}-\x{9F}\x{2028}\x{2029}\x{FEFF}\x{FFFE}\x{FFFF}]/u', $text) !== 1;
	}
}
