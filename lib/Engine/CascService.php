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
 * High-level CasC orchestration facade shared by CLI, Web UI, MCP, and Doctor.
 */
final class CascService {
	private readonly Planner $planner;
	private readonly Applier $applier;

	public function __construct(
		private readonly StateRepository $repo,
		private readonly SecretResolver $secrets,
		private readonly ProviderRegistry $pluginRegistry = new ProviderRegistry(),
		private readonly string $cactiCompat = '1.2.31',
	) {
		$this->planner = new Planner([
			new SettingsProvider(),
			new SitesProvider(),
			new PollersProvider(),
			new TemplatesProvider(),
			new DevicesProvider(),
			new TreesProvider(),
			new PluginsProvider($this->pluginRegistry),
		]);

		$this->applier = new Applier($this->planner);
	}

	public function repository(): StateRepository {
		return $this->repo;
	}

	public function pluginRegistry(): ProviderRegistry {
		return $this->pluginRegistry;
	}

	/**
	 * Export live state as a canonical CascDocument (zero plaintext secrets).
	 *
	 * @param array<string, mixed> $options
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
	 * @param array<string, mixed> $options
	 */
	public function export(string $format, array $options = []): string {
		return CascCodec::write($format, $this->exportDocument($options));
	}

	/**
	 * @param array<string, mixed> $options
	 * @return list<string>
	 */
	public function validate(CascDocument $document, array $options = []): array {
		return $this->planner->validate($document, $this->secrets, $options);
	}

	/**
	 * @param array<string, mixed> $options
	 */
	public function plan(CascDocument $document, array $options = [], string $actor = 'cli', bool $recordPlan = true): Plan {
		$plan = $this->planner->plan($this->repo, $document, $this->secrets, $options);

		if ($recordPlan && !$plan->hasErrors()) {
			$this->repo->recordPlan($plan->planHash, $actor, $plan->manifestSha256, count($plan->actions), time());
		}

		return $plan;
	}

	/**
	 * @param array<string, mixed> $options
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
