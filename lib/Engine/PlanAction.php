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

namespace Somethingwithproof\Casc\Engine;

/**
 * Single planned operation within a CasC reconciliation plan.
 */
final class PlanAction {
	public const CREATE  = 'create';
	public const UPDATE  = 'update';
	public const DELETE  = 'delete';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	/**
	 * @param array<string, array{from: mixed, to: mixed}> $changes Masked field-level diff
	 */
	public function __construct(
		public readonly string $domain,
		public readonly string $operation,
		public readonly string $naturalKey,
		public readonly string $summary,
		public readonly array $changes = [],
	) {
	}

	public function isMutation(): bool {
		return $this->operation === self::CREATE
			|| $this->operation === self::UPDATE
			|| $this->operation === self::DELETE;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$out = [
			'domain'     => $this->domain,
			'operation'  => $this->operation,
			'naturalKey' => $this->naturalKey,
			'summary'    => $this->summary,
		];

		if ($this->changes !== []) {
			$changes = $this->changes;
			ksort($changes, SORT_STRING);
			$out['changes'] = $changes;
		}

		return $out;
	}
}
