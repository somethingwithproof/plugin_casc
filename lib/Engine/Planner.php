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

use Somethingwithproof\Casc\Model\CascDocument;
use Somethingwithproof\Casc\Model\CrossParameterValidator;
use Somethingwithproof\Casc\Provider\ResourceProvider;
use Somethingwithproof\Casc\Repository\ReadCachedStateRepository;
use Somethingwithproof\Casc\Repository\StateRepository;
use Somethingwithproof\Casc\Secret\SecretResolver;

/**
 * Computes deterministic, topologically ordered reconciliation plans with a
 * canonical SHA-256 plan hash for drift-checked apply confirmation.
 */
final class Planner {
	public const TOPOLOGICAL_ORDER = [
		'settings',
		'sites',
		'pollers',
		'templates',
		'devices',
		'trees',
		'plugins',
	];

	/** @var array<string, ResourceProvider> */
	private array $providers = [];

	/**
	 * Construct a planner indexed by domain from a list of resource providers.
	 *
	 * @param list<ResourceProvider> $providers Registered core domain providers.
	 */
	public function __construct(array $providers) {
		foreach ($providers as $provider) {
			$this->providers[$provider->domain()] = $provider;
		}
	}

	/**
	 * Return registered provider domain names in topological reconciliation order.
	 *
	 * @return list<string> Ordered domain names.
	 */
	public function orderedDomains(): array {
		$ordered = [];

		foreach (self::TOPOLOGICAL_ORDER as $domain) {
			if (isset($this->providers[$domain])) {
				$ordered[] = $domain;
			}
		}

		return $ordered;
	}

	/**
	 * Look up the provider registered for a given spec domain.
	 *
	 * @param string $domain Domain identifier.
	 * @return ResourceProvider|null Matching provider or null.
	 */
	public function provider(string $domain): ?ResourceProvider {
		return $this->providers[$domain] ?? null;
	}

	/**
	 * Validate every present spec domain without touching database state.
	 *
	 * @param CascDocument $document Parsed CasC manifest document.
	 * @param SecretResolver $secrets Secret reference validator.
	 * @param array<string, mixed> $options Validation options (e.g. baseDir).
	 * @return list<string> Validation error messages.
	 */
	public function validate(CascDocument $document, SecretResolver $secrets, array $options = []): array {
		$errors = [];

		foreach ($this->orderedDomains() as $domain) {
			if (!isset($document->spec[$domain]) || !is_array($document->spec[$domain])) {
				continue;
			}

			$provider = $this->providers[$domain];

			foreach ($provider->validate($document->spec[$domain], $secrets, $options) as $err) {
				$errors[] = $err;
			}
		}

		return $errors;
	}

	/**
	 * Compute a deterministic plan against the live StateRepository including
	 * cross-parameter invariant verification.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param CascDocument $document Parsed CasC manifest document.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param array<string, mixed> $options Planning options (e.g. prune, baseDir).
	 * @return Plan Deterministic plan with canonical SHA-256 planHash.
	 */
	public function plan(StateRepository $repo, CascDocument $document, SecretResolver $secrets, array $options = []): Plan {
		$actions      = [];
		$snapshotRepo = $repo instanceof ReadCachedStateRepository ? $repo : new ReadCachedStateRepository($repo);

		// Collect site and poller natural keys declared in this manifest as O(1)
		// hash sets so devices referencing newly created sites/pollers plan cleanly.
		$plannedSites   = [];
		$plannedPollers = [];

		if (isset($document->spec['sites']) && is_array($document->spec['sites'])) {
			foreach ($document->spec['sites'] as $s) {
				if (is_array($s) && is_string($s['name'] ?? null) && trim($s['name']) !== '') {
					$plannedSites[trim($s['name'])] = true;
				}
			}
		}

		if (isset($document->spec['pollers']) && is_array($document->spec['pollers'])) {
			foreach ($document->spec['pollers'] as $p) {
				if (is_array($p) && is_string($p['name'] ?? null) && trim($p['name']) !== '') {
					$plannedPollers[trim($p['name'])] = true;
				}
			}
		}

		$providerOptions = $options + [
			'plannedSites'   => $plannedSites,
			'plannedPollers' => $plannedPollers,
		];

		$seenErrors = [];

		foreach ($this->orderedDomains() as $domain) {
			if (!isset($document->spec[$domain]) || !is_array($document->spec[$domain])) {
				continue;
			}

			$provider = $this->providers[$domain];

			foreach ($provider->plan($snapshotRepo, $document->spec[$domain], $secrets, $providerOptions) as $action) {
				if ($action->operation === PlanAction::ERROR) {
					$seenErrors[$action->summary] = true;
				}

				$actions[] = $action;
			}
		}

		foreach (CrossParameterValidator::validateDetailed($document, $snapshotRepo) as $violation) {
			if (isset($seenErrors[$violation['message']])) {
				continue;
			}

			$seenErrors[$violation['message']] = true;
			$actions[]                         = new PlanAction(
				$violation['domain'],
				PlanAction::ERROR,
				$violation['key'],
				$violation['message']
			);
		}

		// Report reference.paths differences as non-mutating warnings.
		if (isset($document->reference['paths']) && is_array($document->reference['paths'])) {
			$currentSettings = $snapshotRepo->getSettings();

			foreach ($document->reference['paths'] as $pathKey => $refVal) {
				$key = (string) $pathKey;
				$cur = $currentSettings[$key] ?? '';
				$ref = is_scalar($refVal) ? (string) $refVal : '';

				if ($ref !== '' && $cur !== $ref) {
					$actions[] = new PlanAction(
						'reference',
						PlanAction::WARNING,
						$key,
						sprintf('Reference path "%s" differs from local host (local="%s", manifest="%s"); not applied', $key, $cur, $ref)
					);
				}
			}
		}

		$manifestCanonical = json_encode($document->toCanonicalArray(), JSON_THROW_ON_ERROR);
		$manifestSha256    = hash('sha256', $manifestCanonical);
		unset($manifestCanonical);

		$prune = [];

		if (is_array($options['prune'] ?? null)) {
			foreach ($options['prune'] as $p) {
				if (is_scalar($p)) {
					$prune[] = (string) $p;
				}
			}
		}

		sort($prune, SORT_STRING);

		$hashPayload = json_encode([
			'manifestSha256' => $manifestSha256,
			'prune'          => $prune,
			'actions'        => array_map(static fn (PlanAction $a): array => $a->toArray(), $actions),
		], JSON_THROW_ON_ERROR);

		$planHash = hash('sha256', $hashPayload);

		return new Plan($planHash, $manifestSha256, $actions);
	}
}
