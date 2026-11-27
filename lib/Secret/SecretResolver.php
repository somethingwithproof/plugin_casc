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

namespace Somethingwithproof\Casc\Secret;

/**
 * Resolves typed secret references (env:VAR, file:/path, vault:plugin:slot) at
 * apply time without persisting secret material into manifests, plans, or logs.
 */
final class SecretResolver {
	public const MAX_SECRET_BYTES = 65536;

	/** @var list<string> */
	private readonly array $allowedDirs;

	/** @var callable(string): (string|false) */
	private $envReader;

	/** @var (callable(string, string): string)|null */
	private $vaultReader;

	/**
	 * Construct a secret resolver with allowlisted directories and optional readers.
	 *
	 * @param list<string> $allowedDirs Allowlisted root directories for file: references.
	 * @param (callable(string): (string|false))|null $envReader Optional environment reader callback.
	 * @param (callable(string, string): string)|null $vaultReader Optional vault reader callback.
	 * @param bool $requireReferences Whether to forbid plaintext secret literals.
	 */
	public function __construct(
		array $allowedDirs = [],
		?callable $envReader = null,
		?callable $vaultReader = null,
		private readonly bool $requireReferences = false,
	) {
		$dirs = [];

		foreach ($allowedDirs as $dir) {
			$trimmed = trim($dir);

			if ($trimmed === '') {
				continue;
			}

			$real = realpath($trimmed);

			if ($real !== false && is_dir($real)) {
				$dirs[] = rtrim($real, '/');
			}
		}

		$this->allowedDirs = array_values(array_unique($dirs));
		$this->envReader   = $envReader ?? static fn (string $name): string|false => getenv($name);
		$this->vaultReader = $vaultReader;
	}

	/**
	 * Validate a secret reference syntax without revealing or returning its value.
	 *
	 * @param string $value Candidate secret reference or literal value.
	 * @return void
	 */
	public function validateReference(string $value): void {
		if ($value === '') {
			return;
		}

		if ($value === SecretPolicy::REDACTED_MARKER) {
			throw new SecretException('Redacted placeholder "<redacted>" cannot be applied; replace it with an env:, file:, or vault: reference');
		}

		if (str_starts_with($value, 'env:')) {
			$var = substr($value, 4);

			if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $var) !== 1) {
				throw new SecretException(sprintf('Invalid env secret reference "%s"', $value));
			}

			return;
		}

		if (str_starts_with($value, 'file:')) {
			$this->validateFilePath(substr($value, 5));

			return;
		}

		if (str_starts_with($value, 'vault:')) {
			$parts = explode(':', substr($value, 6), 2);

			if (count($parts) !== 2 || preg_match('/^[a-z0-9_-]{1,32}$/D', $parts[0]) !== 1 || preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $parts[1]) !== 1) {
				throw new SecretException(sprintf('Invalid vault secret reference "%s"', $value));
			}

			return;
		}

		if ($this->requireReferences) {
			throw new SecretException('Plaintext secret values are not permitted; use env:, file:, or vault: references');
		}
	}

	/**
	 * Resolve a secret reference into its runtime value.
	 *
	 * @param string $value Secret reference or literal value.
	 * @return string Resolved runtime secret value.
	 */
	public function resolve(string $value): string {
		if ($value === '') {
			return '';
		}

		$this->validateReference($value);

		if (str_starts_with($value, 'env:')) {
			$var      = substr($value, 4);
			$resolved = ($this->envReader)($var);

			if ($resolved === false || $resolved === '') {
				throw new SecretException(sprintf('Environment secret variable "%s" is not set or empty', $var));
			}

			return $resolved;
		}

		if (str_starts_with($value, 'file:')) {
			$resolvedPath = $this->validateFilePath(substr($value, 5));
			$size         = filesize($resolvedPath);

			if ($size === false || $size > self::MAX_SECRET_BYTES) {
				throw new SecretException(sprintf('Secret file "%s" exceeds the %d byte limit', $resolvedPath, self::MAX_SECRET_BYTES));
			}

			$contents = file_get_contents($resolvedPath, false, null, 0, self::MAX_SECRET_BYTES + 1);

			if ($contents === false || strlen($contents) > self::MAX_SECRET_BYTES) {
				throw new SecretException(sprintf('Unable to read secret file "%s"', $resolvedPath));
			}

			return rtrim($contents, "\r\n");
		}

		if (str_starts_with($value, 'vault:')) {
			if ($this->vaultReader === null) {
				throw new SecretException(sprintf('Vault secret provider is not configured for "%s"', $value));
			}

			[$plugin, $slot] = explode(':', substr($value, 6), 2);

			return ($this->vaultReader)($plugin, $slot);
		}

		return $value;
	}

	/**
	 * Enforce confinement to allowlisted directories, symlink rejection on
	 * every path segment, regular-file check, and strict 0400/0600 mode.
	 *
	 * @param string $path Candidate absolute secret file path.
	 * @return string Verified canonical filesystem path.
	 */
	private function validateFilePath(string $path): string {
		if ($path === '' || str_contains($path, "\0") || !str_starts_with($path, '/')) {
			throw new SecretException('File secret reference must use an absolute path');
		}

		if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
			throw new SecretException('File secret reference must not contain parent directory traversal');
		}

		if ($this->allowedDirs === []) {
			throw new SecretException('No casc_secret_dirs allowlist is configured; file: secrets are refused');
		}

		// Find matching allowlisted directory prefix and inspect every relative segment for symlinks.
		$matchedBase = null;

		foreach ($this->allowedDirs as $dir) {
			if (str_starts_with($path, $dir . '/')) {
				$matchedBase = $dir;

				break;
			}
		}

		if ($matchedBase === null) {
			throw new SecretException(sprintf('Secret file "%s" is outside allowlisted casc_secret_dirs', $path));
		}

		$relative = substr($path, strlen($matchedBase) + 1);
		$walk     = $matchedBase;

		foreach (explode('/', $relative) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			$walk .= '/' . $segment;

			if (is_link($walk)) {
				throw new SecretException(sprintf('Secret file "%s" must not be or traverse a symlink', $path));
			}
		}

		$real = realpath($path);

		if ($real === false || !is_file($real) || !is_readable($real)) {
			throw new SecretException(sprintf('Secret file "%s" does not exist or is not readable', $path));
		}

		if (!str_starts_with($real, $matchedBase . '/')) {
			throw new SecretException(sprintf('Secret file "%s" resolves outside allowlisted casc_secret_dirs', $path));
		}

		$stat = lstat($real);

		if ($stat === false) {
			throw new SecretException(sprintf('Cannot inspect permissions on secret file "%s"', $path));
		}

		$mode = $stat['mode'] & 0777;

		if ($mode !== 0400 && $mode !== 0600) {
			throw new SecretException(sprintf('Secret file "%s" has insecure mode %04o; require 0400 or 0600', $path, $mode));
		}

		return $real;
	}
}
