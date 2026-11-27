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

use Somethingwithproof\Casc\Codec\CascCodec;
use Somethingwithproof\Casc\Extension\ProviderRegistry;
use Somethingwithproof\Casc\Model\CascDocument;
use Somethingwithproof\Casc\Model\CrossParameterValidator;
use Somethingwithproof\Casc\Provider\DevicesProvider;
use Somethingwithproof\Casc\Provider\PluginsProvider;
use Somethingwithproof\Casc\Provider\PollersProvider;
use Somethingwithproof\Casc\Provider\SettingsProvider;
use Somethingwithproof\Casc\Provider\SitesProvider;
use Somethingwithproof\Casc\Provider\TemplatesProvider;
use Somethingwithproof\Casc\Provider\TreesProvider;
use Somethingwithproof\Casc\Repository\StateRepository;
use Somethingwithproof\Casc\Secret\SecretResolver;

/**
 * High-level CasC orchestration facade shared by CLI, Web UI, MCP, Doctor, and
 * optional plugin_yaml/plugin_api schema consumers.
 */
final class CascService {
	public const JSON_SCHEMA_DRAFT = 'https://json-schema.org/draft/2020-12/schema';

	private readonly Planner $planner;
	private readonly Applier $applier;

	/**
	 * Construct the CasC orchestration service with core and optional extension registries.
	 *
	 * @param StateRepository $repo Storage repository backing Cacti state.
	 * @param SecretResolver $secrets Secret reference validator and resolver.
	 * @param ProviderRegistry $pluginRegistry Fault-isolated sibling plugin CasC provider registry.
	 * @param string $cactiCompat Target Cacti compatibility string placed in exported metadata.
	 * @param list<callable(string, array<string, mixed>): mixed> $templateCompilers Optional YAML template compilers.
	 */
	public function __construct(
		private readonly StateRepository $repo,
		private readonly SecretResolver $secrets,
		private readonly ProviderRegistry $pluginRegistry = new ProviderRegistry(),
		private readonly string $cactiCompat = '1.2.31',
		array $templateCompilers = [],
	) {
		$this->planner = new Planner([
			new SettingsProvider(),
			new SitesProvider(),
			new PollersProvider(),
			new TemplatesProvider($templateCompilers),
			new DevicesProvider(),
			new TreesProvider(),
			new PluginsProvider($this->pluginRegistry),
		]);

		$this->applier = new Applier($this->planner);
	}

	/**
	 * Return the backing StateRepository instance.
	 *
	 * @return StateRepository Active state repository.
	 */
	public function repository(): StateRepository {
		return $this->repo;
	}

	/**
	 * Return the fault-isolated plugin provider registry.
	 *
	 * @return ProviderRegistry Active provider registry.
	 */
	public function pluginRegistry(): ProviderRegistry {
		return $this->pluginRegistry;
	}

	/**
	 * Generate a live JSON Schema Draft 2020-12 document covering all core
	 * resource domains and registered sibling plugin providers.
	 *
	 * @return array<string, mixed> JSON Schema Draft 2020-12 document.
	 */
	public function describeSchema(): array {
		$specProperties = [];

		foreach ($this->planner->orderedDomains() as $domain) {
			$provider = $this->planner->provider($domain);

			if ($provider !== null) {
				$specProperties[$domain] = $provider->describeSchema();
			}
		}

		return [
			'$schema'              => self::JSON_SCHEMA_DRAFT,
			'$id'                  => 'https://cacti.net/schemas/casc/v1alpha1/manifest.schema.json',
			'title'                => 'Cacti Configuration-as-Code Manifest',
			'type'                 => 'object',
			'required'             => ['apiVersion', 'kind'],
			'additionalProperties' => false,
			'properties'           => [
				'apiVersion' => [
					'const' => CascDocument::API_VERSION,
				],
				'kind' => [
					'const' => CascDocument::KIND,
				],
				'metadata' => [
					'type'                 => 'object',
					'additionalProperties' => [
						'type' => ['string', 'integer', 'number', 'boolean', 'null'],
					],
				],
				'spec' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => $specProperties,
				],
				'reference' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'paths' => [
							'type'                 => 'object',
							'additionalProperties' => ['type' => 'string'],
						],
						'plugins' => [
							'type'                 => 'object',
							'additionalProperties' => ['type' => 'object'],
						],
					],
				],
			],
		];
	}

	/**
	 * Export live state as a canonical CascDocument (zero plaintext secrets).
	 *
	 * @param array<string, mixed> $options Export options (name, omitSecrets, splitTemplatesDir).
	 * @return CascDocument Canonical manifest intermediate representation.
	 */
	public function exportDocument(array $options = []): CascDocument {
		$spec      = [];
		$reference = [];

		foreach ($this->planner->orderedDomains() as $domain) {
			$provider = $this->planner->provider($domain);

			if ($provider === null) {
				continue;
			}

			$exported = $provider->export($this->repo, $options);

			if (isset($exported['spec']) && is_array($exported['spec']) && $exported['spec'] !== []) {
				$spec[$domain] = $exported['spec'];
			}

			if (isset($exported['reference']) && is_array($exported['reference'])) {
				foreach ($exported['reference'] as $refKey => $refVal) {
					if (is_array($refVal) && $refVal !== []) {
						$reference[$refKey] = $refVal;
					}
				}
			}
		}

		$name     = is_string($options['name'] ?? null) && $options['name'] !== '' ? $options['name'] : 'cacti-controller';
		$metadata = [
			'name'        => $name,
			'cactiCompat' => $this->cactiCompat,
		];

		return new CascDocument(
			CascDocument::API_VERSION,
			CascDocument::KIND,
			$metadata,
			$spec,
			$reference
		);
	}

	/**
	 * Export live state serialized in the requested manifest format (yaml or xml).
	 *
	 * @param string $format Target serialization format ('yaml' or 'xml').
	 * @param array<string, mixed> $options Export options.
	 * @return string Serialized manifest text.
	 */
	public function export(string $format, array $options = []): string {
		return CascCodec::write($format, $this->exportDocument($options));
	}

	/**
	 * Validate per-domain structure, secret references, templates, and
	 * cross-parameter configuration invariants.
	 *
	 * @param CascDocument $document Parsed CasC manifest document.
	 * @param array<string, mixed> $options Validation options (e.g. baseDir, templateCompilers).
	 * @return list<string> Deduplicated validation error messages.
	 */
	public function validate(CascDocument $document, array $options = []): array {
		$domainErrors = $this->planner->validate($document, $this->secrets, $options);
		$crossErrors  = CrossParameterValidator::validate($document, $this->repo);

		return array_values(array_unique(array_merge($domainErrors, $crossErrors)));
	}

	/**
	 * Compute a deterministic reconciliation plan and optionally persist the
	 * plan hash for apply confirmation traceability.
	 *
	 * @param CascDocument $document Parsed CasC manifest document.
	 * @param array<string, mixed> $options Planning options (baseDir, prune, templateCompilers).
	 * @param string $actor Operator or subsystem identity recording the plan.
	 * @param bool $recordPlan Whether to store the plan hash in plugin_casc_plan.
	 * @return Plan Deterministic plan with canonical planHash.
	 */
	public function plan(CascDocument $document, array $options = [], string $actor = 'cli', bool $recordPlan = true): Plan {
		$plan = $this->planner->plan($this->repo, $document, $this->secrets, $options);

		if ($recordPlan && !$plan->hasErrors()) {
			$this->repo->recordPlan($plan->planHash, $actor, $plan->manifestSha256, count($plan->actions), time());
		}

		return $plan;
	}

	/**
	 * Transactionally apply a manifest after verifying the live plan hash
	 * matches the operator's confirmed plan hash.
	 *
	 * @param CascDocument $document Parsed CasC manifest document.
	 * @param string $confirmPlanHash Confirmed 64-char SHA-256 plan hash.
	 * @param string $actor Operator identity for audit logging.
	 * @param string $source Invocation channel ('cli' or 'web').
	 * @param array<string, mixed> $options Apply options (baseDir, prune, templateCompilers).
	 * @return ApplyResult Outcome including audit ID and replication flags.
	 */
	public function apply(
		CascDocument $document,
		string $confirmPlanHash,
		string $actor = 'cli',
		string $source = 'cli',
		array $options = [],
	): ApplyResult {
		return $this->applier->apply($this->repo, $document, $this->secrets, $confirmPlanHash, $actor, $source, $options);
	}
}
