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

namespace Somethingwithproof\Casc\Tests\Quality;

use PHPUnit\Framework\TestCase;

/**
 * AST/token-based gate verifying 100% PHPDoc coverage across all production
 * PHP files (named classes, interfaces, traits, enums, functions, and methods).
 */
final class PhpDocCoverageTest extends TestCase {
	public function testAllProductionSymbolsHaveCompletePhpDoc(): void {
		$root  = dirname(__DIR__, 2);
		$files = $this->productionFiles($root);

		$this->assertNotEmpty($files, 'Expected production PHP files to inspect');

		$missing = [];

		foreach ($files as $file) {
			$relative = ltrim(str_replace($root, '', $file), '/');
			$code     = file_get_contents($file);

			if ($code === false) {
				$missing[] = $relative . ': unable to read file';

				continue;
			}

			foreach ($this->inspectFile($code, $relative) as $violation) {
				$missing[] = $violation;
			}
		}

		$this->assertSame([], $missing, "Missing or incomplete PHPDoc blocks:\n" . implode("\n", $missing));
	}

	/**
	 * @return list<string>
	 */
	private function productionFiles(string $root): array {
		$files = [];

		foreach (['setup.php', 'casc_admin.php', 'casc_operator.php', 'casc_view.php', 'index.php', 'cli/casc.php', 'include/autoload.php'] as $entry) {
			$path = $root . '/' . $entry;

			if (is_file($path)) {
				$files[] = $path;
			}
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $item) {
			if ($item instanceof \SplFileInfo && $item->isFile() && $item->getExtension() === 'php') {
				$files[] = $item->getPathname();
			}
		}

		sort($files, SORT_STRING);

		return $files;
	}

	/**
	 * @return list<string>
	 */
	private function inspectFile(string $code, string $relative): array {
		$tokens     = token_get_all($code);
		$count      = count($tokens);
		$violations = [];

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];

			if (!is_array($token)) {
				continue;
			}

			$id = $token[0];

			if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
				if ($id === T_CLASS && $this->isAnonymousClass($tokens, $i)) {
					continue;
				}

				$name = $this->nextIdentifier($tokens, $i + 1);

				if ($name === null) {
					continue;
				}

				$doc = $this->precedingDocComment($tokens, $i);

				if ($doc === null) {
					$violations[] = sprintf('%s:%d missing PHPDoc on %s %s', $relative, $token[2], strtolower($token[1]), $name);
				}

				continue;
			}

			if ($id === T_FUNCTION) {
				$name = $this->functionName($tokens, $i + 1);

				if ($name === null) {
					// Anonymous closure.
					continue;
				}

				$doc = $this->precedingDocComment($tokens, $i);

				if ($doc === null) {
					$violations[] = sprintf('%s:%d missing PHPDoc on function/method %s()', $relative, $token[2], $name);

					continue;
				}

				$params = $this->extractParameterNames($tokens, $i + 1);

				foreach ($params as $paramName) {
					if (preg_match('/@param\b[^\n]*\$' . preg_quote($paramName, '/') . '\b/', $doc) !== 1) {
						$violations[] = sprintf('%s:%d missing @param $%s on %s()', $relative, $token[2], $paramName, $name);
					}
				}

				if ($name !== '__construct' && $this->hasNonVoidReturnOrNoVoidHint($tokens, $i + 1)) {
					if (preg_match('/@return\b/', $doc) !== 1) {
						$violations[] = sprintf('%s:%d missing @return on %s()', $relative, $token[2], $name);
					}
				}
			}
		}

		return $violations;
	}

	/**
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private function isAnonymousClass(array $tokens, int $classIndex): bool {
		for ($j = $classIndex - 1; $j >= 0; $j--) {
			$t = $tokens[$j];

			if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_READONLY], true)) {
				continue;
			}

			return is_array($t) && $t[0] === T_NEW;
		}

		return false;
	}

	/**
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private function nextIdentifier(array $tokens, int $start): ?string {
		$count = count($tokens);

		for ($j = $start; $j < $count; $j++) {
			$t = $tokens[$j];

			if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}

			if (is_array($t) && $t[0] === T_STRING) {
				return $t[1];
			}

			return null;
		}

		return null;
	}

	/**
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private function functionName(array $tokens, int $start): ?string {
		$count = count($tokens);

		for ($j = $start; $j < $count; $j++) {
			$t = $tokens[$j];

			if ($t === '&') {
				continue;
			}

			if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}

			if (is_array($t) && $t[0] === T_STRING) {
				return $t[1];
			}

			return null;
		}

		return null;
	}

	/**
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private function precedingDocComment(array $tokens, int $declIndex): ?string {
		$modifiers = [
			T_WHITESPACE,
			T_PUBLIC,
			T_PROTECTED,
			T_PRIVATE,
			T_STATIC,
			T_FINAL,
			T_ABSTRACT,
			T_READONLY,
		];

		$inAttr = 0;

		for ($j = $declIndex - 1; $j >= 0; $j--) {
			$t = $tokens[$j];

			if ($t === ']') {
				$inAttr++;

				continue;
			}

			if ($inAttr > 0) {
				if (is_array($t) && $t[0] === T_ATTRIBUTE) {
					$inAttr--;
				} elseif ($t === '[') {
					$inAttr--;
				}

				continue;
			}

			if (is_array($t) && $t[0] === T_DOC_COMMENT) {
				return $t[1];
			}

			if (is_array($t) && in_array($t[0], $modifiers, true)) {
				continue;
			}

			return null;
		}

		return null;
	}

	/**
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 * @return list<string>
	 */
	private function extractParameterNames(array $tokens, int $start): array {
		$count  = count($tokens);
		$params = [];
		$depth  = 0;
		$found  = false;

		for ($j = $start; $j < $count; $j++) {
			$t = $tokens[$j];

			if ($t === '(') {
				$depth++;
				$found = true;

				continue;
			}

			if ($t === ')') {
				$depth--;

				if ($found && $depth === 0) {
					break;
				}

				continue;
			}

			if ($found && $depth === 1 && is_array($t) && $t[0] === T_VARIABLE) {
				$params[] = ltrim($t[1], '$');
			}
		}

		return $params;
	}

	/**
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private function hasNonVoidReturnOrNoVoidHint(array $tokens, int $start): bool {
		$count = count($tokens);
		$depth = 0;
		$found = false;
		$after = $start;

		for ($j = $start; $j < $count; $j++) {
			$t = $tokens[$j];

			if ($t === '(') {
				$depth++;
				$found = true;

				continue;
			}

			if ($t === ')') {
				$depth--;

				if ($found && $depth === 0) {
					$after = $j + 1;

					break;
				}
			}
		}

		$sawColon   = false;
		$returnText = '';

		for ($j = $after; $j < $count; $j++) {
			$t = $tokens[$j];

			if ($t === '{' || $t === ';') {
				break;
			}

			if ($t === ':') {
				$sawColon = true;

				continue;
			}

			if ($sawColon) {
				$returnText .= is_array($t) ? $t[1] : $t;
			}
		}

		$trimmed = strtolower(trim($returnText));

		if ($trimmed === 'void' || $trimmed === 'never') {
			return false;
		}

		return true;
	}
}
