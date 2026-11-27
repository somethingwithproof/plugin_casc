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

/**
 * Contract for core Cacti resource domain providers participating in CasC
 * export, validation, planning, apply, and JSON Schema description.
 */
interface ResourceProvider {
	/**
	 * Return the canonical manifest spec domain name handled by this provider.
	 *
	 * @return string Spec domain key (e.g. settings, sites, devices).
	 */
	public function domain(): string;

	/**
	 * Return the JSON Schema Draft 2020-12 fragment describing this domain's spec section.
	 *
	 * @return array<string, mixed> JSON Schema object for spec.<domain>.
	 */
	public function describeSchema(): array;

	/**
	 * Export current state for this domain.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<string, mixed> $options Export options (e.g. omitSecrets, splitTemplatesDir).
	 * @return array{spec: mixed, reference?: array<string, mixed>}
	 */
	public function export(StateRepository $repo, array $options = []): array;

	/**
	 * Validate desired section structure, natural keys, and secret references.
	 *
	 * @param array<array-key, mixed> $desired Desired domain payload from the manifest.
	 * @param SecretResolver $secrets Secret reference validator/resolver.
	 * @param array<string, mixed> $options Validation options (e.g. baseDir).
	 * @return list<string> Validation error messages.
	 */
	public function validate(array $desired, SecretResolver $secrets, array $options = []): array;

	/**
	 * Compute deterministic diff actions between live state and desired state.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<array-key, mixed> $desired Desired domain payload from the manifest.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param array<string, mixed> $options Planning options (e.g. prune, baseDir, plannedSites).
	 * @return list<PlanAction> Planned operations for this domain.
	 */
	public function plan(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array;

	/**
	 * Apply desired state mutations inside the active transaction.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<array-key, mixed> $desired Desired domain payload from the manifest.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param array<string, mixed> $options Apply options including confirmed planHash.
	 * @return array{applied: list<PlanAction>, needsReplication: bool, needsPollerCache: bool}
	 */
	public function apply(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array;
}
