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
 * Decoded YAML or XML document tree paired with source path-to-line metadata.
 */
final class ParsedTree {
	/**
	 * Construct a decoded tree with optional line number map.
	 *
	 * @param array<array-key, mixed> $data Decoded document array.
	 * @param array<string, int> $lineMap Slash-path to 1-based line number map.
	 */
	public function __construct(
		public readonly array $data,
		public readonly array $lineMap = [],
	) {
	}

	/**
	 * Look up the 1-based source line number for a decoded document path.
	 *
	 * @param string $path Slash-delimited path inside the document.
	 * @return int|null Line number when recorded, or null.
	 */
	public function lineFor(string $path): ?int {
		return $this->lineMap[$path] ?? null;
	}
}
