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

use Somethingwithproof\Casc\Log;

/**
 * Bridge between CasC template references and native Cacti template XML/package
 * files (<cacti><hash_*>) handled by core lib/import.php and lib/export.php,
 * with optional fault-isolated YAML template compiler dispatch.
 */
final class TemplateBridge {
	public const MAX_TEMPLATE_BYTES = 16777216;

	/**
	 * Determine whether a template path targets a YAML template source file.
	 *
	 * @param string $path Template path or filename.
	 * @return bool True when the path ends in .yaml or .yml (case-insensitive).
	 */
	public static function isYamlTemplatePath(string $path): bool {
		$lower = strtolower(trim($path));

		return str_ends_with($lower, '.yaml') || str_ends_with($lower, '.yml');
	}

	/**
	 * Discover template compiler callables from registered
	 * casc_register_template_compilers plugin hooks with fault isolation.
	 *
	 * @param list<array{name: string, file: string, function: string}> $hooks Hook rows from plugin_hooks.
	 * @param (callable(string, string): (string|false))|null $fileResolver Optional plugin file path resolver.
	 * @return list<callable(string, array<string, mixed>): mixed> Collected compiler callables.
	 */
	public static function dispatchCompilerHooks(array $hooks, ?callable $fileResolver = null): array {
		$compilers = [];

		foreach ($hooks as $hook) {
			$slug     = $hook['name'];
			$file     = $hook['file'];
			$function = $hook['function'];

			if ($slug === '' || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $slug) !== 1) {
				Log::warning(sprintf('Template compiler hook rejected for invalid plugin slug "%s"', $slug));

				continue;
			}

			if ($file === '' || str_contains($file, '..') || str_starts_with($file, '/') || str_contains($file, "\0")) {
				Log::warning(sprintf('Template compiler hook rejected for plugin "%s": invalid file path', $slug));

				continue;
			}

			try {
				if ($fileResolver !== null) {
					$resolved = $fileResolver($slug, $file);

					if (is_string($resolved) && is_file($resolved)) {
						include_once($resolved);
					}
				}

				if (!is_callable($function)) {
					Log::warning(sprintf('Template compiler hook callback for plugin "%s" is not callable', $slug));

					continue;
				}

				$returned = $function([]);

				foreach (self::extractCompilerCallables($returned) as $compiler) {
					$compilers[] = $compiler;
				}
			} catch (\Throwable $error) {
				Log::warning(sprintf('Template compiler hook fault for plugin "%s": %s', $slug, $error::class));
			}
		}

		return $compilers;
	}

	/**
	 * Validate a referenced template XML, .xml.gz package, or optional YAML
	 * template file on disk.
	 *
	 * Confines relative paths inside $baseDir, rejects symlinks and path
	 * traversal, verifies optional pinned SHA-256 digest, compiles .yaml/.yml
	 * templates via registered casc_register_template_compilers callbacks, and
	 * enforces XXE-safe canonical <cacti> XML validation before core
	 * import_xml_data() parses the output.
	 *
	 * @param string $relativePath Relative path to template file under $baseDir.
	 * @param string $baseDir Base directory confining template references.
	 * @param string|null $expectedSha256 Optional 64-char hex SHA-256 digest of the on-disk file.
	 * @param list<callable(string, array<string, mixed>): mixed> $templateCompilers Optional registered YAML-to-XML compilers.
	 * @return array{resolvedPath: string, sha256: string, isPackage: bool, xmlContent: ?string}
	 */
	public static function inspectTemplateFile(
		string $relativePath,
		string $baseDir,
		?string $expectedSha256 = null,
		array $templateCompilers = [],
	): array {
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

		if ($isPackage) {
			return [
				'resolvedPath' => $resolved,
				'sha256'       => $actualSha256,
				'isPackage'    => true,
				'xmlContent'   => null,
			];
		}

		if (self::isYamlTemplatePath($relativePath)) {
			$xml = self::compileYamlTemplate(
				$raw,
				$relativePath,
				[
					'path'         => $relativePath,
					'resolvedPath' => $resolved,
					'baseDir'      => $realBase,
				],
				$templateCompilers
			);

			return [
				'resolvedPath' => $resolved,
				'sha256'       => $actualSha256,
				'isPackage'    => false,
				'xmlContent'   => $xml,
			];
		}

		self::validateTemplateXml($raw, $relativePath);

		return [
			'resolvedPath' => $resolved,
			'sha256'       => $actualSha256,
			'isPackage'    => false,
			'xmlContent'   => $raw,
		];
	}

	/**
	 * Validate that XML text is XXE-safe, well-formed, and rooted at <cacti>.
	 *
	 * @param string $xml Candidate Cacti template XML string.
	 * @param string $relativePath Display path for error diagnostics.
	 * @return void
	 */
	public static function validateTemplateXml(string $xml, string $relativePath): void {
		if (strlen($xml) > self::MAX_TEMPLATE_BYTES) {
			throw new CodecException(sprintf('Template XML "%s" exceeds the %d byte limit', $relativePath, self::MAX_TEMPLATE_BYTES));
		}

		if (preg_match('//u', $xml) !== 1) {
			throw new CodecException(sprintf('Template XML "%s" is not valid UTF-8', $relativePath));
		}

		if (preg_match('/<!(DOCTYPE|ENTITY)\b/i', $xml) === 1) {
			throw new CodecException(sprintf('Template XML "%s" must not contain DOCTYPE or ENTITY declarations', $relativePath));
		}

		if (!str_contains($xml, '<cacti')) {
			throw new CodecException(sprintf('Template XML "%s" is not a native Cacti template (<cacti> root missing)', $relativePath));
		}

		$previous = libxml_use_internal_errors(true);

		try {
			$dom = new \DOMDocument();

			if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
				$error = libxml_get_last_error();

				throw new CodecException(
					sprintf('Template XML "%s" is not well formed: %s', $relativePath, trim($error === false ? 'parse error' : $error->message)),
					$error === false || $error->line < 1 ? null : $error->line
				);
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if ($dom->doctype !== null) {
			throw new CodecException(sprintf('Template XML "%s" must not contain a DOCTYPE declaration', $relativePath));
		}

		$root = $dom->documentElement;

		if ($root === null || $root->nodeName !== 'cacti') {
			throw new CodecException(sprintf('Template XML "%s" must have a <cacti> root element', $relativePath));
		}
	}

	/**
	 * Extract native Cacti template hashes (32-hex hash_XXXXXX tags) from XML.
	 *
	 * @param string $xml Validated Cacti template XML content.
	 * @return list<string> Sorted unique lowercase 32-hex template hashes.
	 */
	public static function extractHashes(string $xml): array {
		if (preg_match_all('/<hash_[0-9a-f]{6}([0-9a-f]{32})>/i', $xml, $matches) < 1) {
			return [];
		}

		$hashes = array_values(array_unique(array_map('strtolower', $matches[1])));
		sort($hashes, SORT_STRING);

		return $hashes;
	}

	/**
	 * Compile YAML template source to validated canonical <cacti> XML via
	 * registered fault-isolated template compilers.
	 *
	 * @param string $yamlSource Raw YAML template source text.
	 * @param string $relativePath Relative template path for diagnostics.
	 * @param array<string, mixed> $context Metadata passed to the compiler callback.
	 * @param list<callable(string, array<string, mixed>): mixed> $compilers Registered template compilers.
	 * @return string Validated canonical <cacti> XML output.
	 */
	private static function compileYamlTemplate(string $yamlSource, string $relativePath, array $context, array $compilers): string {
		if ($compilers === []) {
			throw new CodecException(
				sprintf(
					'Template "%s" is a YAML template, which requires plugin_yaml or a registered casc_register_template_compilers provider',
					$relativePath
				)
			);
		}

		$errors = [];

		foreach ($compilers as $index => $compiler) {
			try {
				$compiled = $compiler($yamlSource, $context);
			} catch (\Throwable $error) {
				$errors[] = sprintf('compiler[%d] threw %s: %s', $index, $error::class, $error->getMessage());
				Log::warning(sprintf('Template compiler[%d] fault for "%s": %s', $index, $relativePath, $error::class));

				continue;
			}

			if (!is_string($compiled) || trim($compiled) === '') {
				$errors[] = sprintf('compiler[%d] returned empty or non-string XML', $index);

				continue;
			}

			try {
				self::validateTemplateXml($compiled, $relativePath);

				return $compiled;
			} catch (CodecException $validationError) {
				$errors[] = sprintf('compiler[%d] produced invalid XML: %s', $index, $validationError->getMessage());
			}
		}

		throw new CodecException(
			sprintf('Failed to compile YAML template "%s": %s', $relativePath, implode('; ', $errors))
		);
	}

	/**
	 * Normalize hook return payloads into a flat list of compiler callables.
	 *
	 * @param mixed $returned Hook return value (callable, descriptor map, or list).
	 * @return list<callable(string, array<string, mixed>): mixed> Extracted compiler callables.
	 */
	private static function extractCompilerCallables(mixed $returned): array {
		if (is_callable($returned)) {
			/** @var callable(string, array<string, mixed>): mixed $returned */
			return [$returned];
		}

		if (!is_array($returned)) {
			return [];
		}

		if (isset($returned['compile']) && is_callable($returned['compile'])) {
			/** @var callable(string, array<string, mixed>): mixed $fn */
			$fn = $returned['compile'];

			return [$fn];
		}

		$out = [];

		foreach ($returned as $item) {
			if (is_callable($item)) {
				/** @var callable(string, array<string, mixed>): mixed $item */
				$out[] = $item;
			} elseif (is_array($item) && isset($item['compile']) && is_callable($item['compile'])) {
				/** @var callable(string, array<string, mixed>): mixed $fn */
				$fn    = $item['compile'];
				$out[] = $fn;
			}
		}

		return $out;
	}
}
