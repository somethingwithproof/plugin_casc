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

namespace Somethingwithproof\Casc\Provider;

use Somethingwithproof\Casc\Engine\PlanAction;
use Somethingwithproof\Casc\Repository\StateRepository;
use Somethingwithproof\Casc\Secret\SecretResolver;

interface ResourceProvider {
	public function domain(): string;

	/**
	 * Export current state for this domain.
	 *
	 * @param array<string, mixed> $options
	 * @return array{spec: mixed, reference?: array<string, mixed>}
	 */
	public function export(StateRepository $repo, array $options = []): array;

	/**
	 * Validate desired section structure, natural keys, and secret references.
	 *
	 * @param array<array-key, mixed> $desired
	 * @param array<string, mixed> $options
	 * @return list<string>
	 */
	public function validate(array $desired, SecretResolver $secrets, array $options = []): array;

	/**
	 * Compute deterministic diff actions between live state and desired state.
	 *
	 * @param array<array-key, mixed> $desired
	 * @param array<string, mixed> $options
	 * @return list<PlanAction>
	 */
	public function plan(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array;

	/**
	 * Apply desired state mutations inside the active transaction.
	 *
	 * @param array<array-key, mixed> $desired
	 * @param array<string, mixed> $options
	 * @return array{applied: list<PlanAction>, needsReplication: bool, needsPollerCache: bool}
	 */
	public function apply(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array;
}
