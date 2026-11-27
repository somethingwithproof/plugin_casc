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

final class ApplyResult {
	/**
	 * @param list<PlanAction> $applied
	 * @param list<string> $errors
	 */
	public function __construct(
		public readonly bool $succeeded,
		public readonly string $planHash,
		public readonly array $applied,
		public readonly array $errors,
		public readonly bool $replicationTriggered,
		public readonly bool $pollerCacheUpdated,
		public readonly int $auditId,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'succeeded'            => $this->succeeded,
			'planHash'             => $this->planHash,
			'appliedCount'         => count($this->applied),
			'errors'               => $this->errors,
			'replicationTriggered' => $this->replicationTriggered,
			'pollerCacheUpdated'   => $this->pollerCacheUpdated,
			'auditId'              => $this->auditId,
		];
	}
}
