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
	 * @return array{0: string, 1: null|bool|int|float|string}
	 */
	public static function classify(string $text): array {
		if ($text === '' || $text === '~' || in_array($text, ['null', 'Null', 'NULL'], true)) {
			return [self::NIL, null];
		}

		if (in_array($text, ['true', 'True', 'TRUE'], true)) {
			return [self::BOOL, true];
		}

		if (in_array($text, ['false', 'False', 'FALSE'], true)) {
			return [self::BOOL, false];
		}

		if (preg_match('/^(0|-?[1-9][0-9]{0,17})$/D', $text) === 1) {
			return [self::INT, (int) $text];
		}

		if (preg_match('/^-?(0|[1-9][0-9]{0,14})\.[0-9]{1,12}$/D', $text) === 1) {
			return [self::FLOAT, (float) $text];
		}

		if (
			preg_match('/^(y|n|yes|no|on|off)$/iD', $text) === 1
			|| preg_match('/^[-+]?(\.[0-9]+|[0-9]+(\.[0-9]*)?)([eE][-+]?[0-9]+)?$/D', $text) === 1
			|| preg_match('/^[-+]?0[xXoObB]/', $text) === 1
			|| preg_match('/^[-+]?\.(inf|nan)$/iD', $text) === 1
			|| preg_match('/^[-+]?[0-9][0-9_]*(:[0-5]?[0-9])+(\.[0-9]*)?$/D', $text) === 1
			|| preg_match('/^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}/', $text) === 1
			|| $text === '='
			|| $text === '<<'
		) {
			return [self::AMBIGUOUS, $text];
		}

		return [self::STRING, $text];
	}

	/**
	 * Whether the writer may emit the string unquoted without ambiguity.
	 */
	public static function plainSafe(string $text): bool {
		if ($text === '' || self::classify($text)[0] !== self::STRING) {
			return false;
		}

		if (preg_match('/^[^\s\-?:,\[\]{}#&*!|>\'"%@`~=<]/u', $text) !== 1 || preg_match('/[\s]$/u', $text) === 1) {
			return false;
		}

		return strpbrk($text, ':#') === false
			&& preg_match('/[\x00-\x1F\x7F\x{80}-\x{9F}\x{2028}\x{2029}\x{FEFF}\x{FFFE}\x{FFFF}]/u', $text) !== 1;
	}
}
