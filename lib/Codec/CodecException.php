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

final class CodecException extends \RuntimeException {
	/** @var list<string> */
	private readonly array $errors;

	/**
	 * @param list<string> $errors
	 */
	public function __construct(
		string $message,
		public readonly ?int $lineNumber = null,
		array $errors = [],
		?\Throwable $previous = null
	) {
		$formatted = $lineNumber !== null ? sprintf('Line %d: %s', $lineNumber, $message) : $message;
		$this->errors = $errors !== [] ? $errors : [$formatted];

		parent::__construct($formatted, 0, $previous);
	}

	/**
	 * @return list<string>
	 */
	public function lines(): array {
		return $this->errors;
	}
}
