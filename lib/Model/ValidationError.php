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
 * Structured schema validation error carrying manifest path and optional line number.
 */
final class ValidationError {
	/**
	 * Construct a validation error at a manifest path and optional source line.
	 *
	 * @param string $path Slash-delimited path inside the manifest.
	 * @param string $message Human-readable validation failure description.
	 * @param int|null $line Optional 1-based line number from the source codec.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $message,
		public readonly ?int $line = null,
	) {
	}

	/**
	 * Format the validation error into a single diagnostic line.
	 *
	 * @return string Formatted error text including line number when known.
	 */
	public function format(): string {
		$prefix = $this->line !== null ? sprintf('Line %d (%s): ', $this->line, $this->path) : sprintf('%s: ', $this->path);

		return $prefix . $this->message;
	}
}
