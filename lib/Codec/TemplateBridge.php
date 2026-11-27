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
 * Bridge between CasC template references and native Cacti template XML/package
 * files (<cacti><hash_*>) handled by core lib/import.php and lib/export.php.
 */
final class TemplateBridge {
	public const MAX_TEMPLATE_BYTES = 16777216;

	/**
	 * Validate a referenced template XML or .xml.gz package file on disk.
	 *
	 * Confines relative paths inside $baseDir, rejects symlinks and path
	 * traversal, verifies optional pinned SHA-256 digest, and rejects DTD/ENTITY
	 * declarations on raw XML files before Cacti's import_xml_data() parses them.
	 *
	 * @return array{resolvedPath: string, sha256: string, isPackage: bool, xmlContent: ?string}
	 */
	public static function inspectTemplateFile(string $relativePath, string $baseDir, ?string $expectedSha256 = null): array {
		if ($relativePath === '' || str_contains($relativePath, "\0")) {
			throw new CodecException('Template path must be a non-empty relative path');
		}

		if (str_starts_with($relativePath, '/') || preg_match('#(^|/)\.\.(/|$)#', $relativePath) === 1) {
			throw new CodecException(sprintf('Template path "%s" must be relative without parent traversal', $relativePath));
		}

		$realBase = realpath($baseDir);

		if ($realBase === false || !is_dir($realBase)) {
			throw new CodecException(sprintf('Template base directory "%s" does not exist', $baseDir));
		}

		$candidate = $realBase . '/' . ltrim($relativePath, '/');

		// Walk every path segment to refuse symlinks before realpath resolves them.
		$walk = $realBase;

		foreach (explode('/', ltrim($relativePath, '/')) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			$walk .= '/' . $segment;

			if (is_link($walk)) {
				throw new CodecException(sprintf('Template path "%s" must not traverse symlinks', $relativePath));
			}
		}

		$resolved = realpath($candidate);

		if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
			throw new CodecException(sprintf('Template file "%s" is missing or unreadable', $relativePath));
		}

		if (!str_starts_with($resolved, $realBase . '/')) {
			throw new CodecException(sprintf('Template path "%s" escapes base directory', $relativePath));
		}

		$size = filesize($resolved);

		if ($size === false || $size > self::MAX_TEMPLATE_BYTES) {
			throw new CodecException(sprintf('Template file "%s" exceeds the %d byte limit', $relativePath, self::MAX_TEMPLATE_BYTES));
		}

		$raw = file_get_contents($resolved, false, null, 0, self::MAX_TEMPLATE_BYTES + 1);

		if ($raw === false || strlen($raw) > self::MAX_TEMPLATE_BYTES) {
			throw new CodecException(sprintf('Unable to read template file "%s" within size limit', $relativePath));
		}

		$actualSha256 = hash('sha256', $raw);

		if ($expectedSha256 !== null && $expectedSha256 !== '') {
			if (preg_match('/^[a-f0-9]{64}$/D', strtolower($expectedSha256)) !== 1) {
				throw new CodecException(sprintf('Template "%s" sha256 must be 64 lowercase hex characters', $relativePath));
			}

			if (!hash_equals(strtolower($expectedSha256), $actualSha256)) {
				throw new CodecException(sprintf('Template "%s" sha256 mismatch (expected %s, actual %s)', $relativePath, strtolower($expectedSha256), $actualSha256));
			}
		}

		$isPackage = str_ends_with(strtolower($resolved), '.xml.gz');

		if (!$isPackage) {
			if (preg_match('/<!(DOCTYPE|ENTITY)\b/i', $raw) === 1) {
				throw new CodecException(sprintf('Template XML "%s" must not contain DOCTYPE or ENTITY declarations', $relativePath));
			}

			if (!str_contains($raw, '<cacti>')) {
				throw new CodecException(sprintf('Template XML "%s" is not a native Cacti template (<cacti> root missing)', $relativePath));
			}
		}

		return [
			'resolvedPath' => $resolved,
			'sha256'       => $actualSha256,
			'isPackage'    => $isPackage,
			'xmlContent'   => $isPackage ? null : $raw,
		];
	}

	/**
	 * Extract native Cacti template hashes (32-hex hash_XXXXXX tags) from XML.
	 *
	 * @return list<string>
	 */
	public static function extractHashes(string $xml): array {
		if (preg_match_all('/<hash_[0-9a-f]{6}([0-9a-f]{32})>/i', $xml, $matches) < 1) {
			return [];
		}

		$hashes = array_values(array_unique(array_map('strtolower', $matches[1])));
		sort($hashes, SORT_STRING);

		return $hashes;
	}
}
