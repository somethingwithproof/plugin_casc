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

use Somethingwithproof\Casc\Codec\CodecException;
use Somethingwithproof\Casc\Codec\TemplateBridge;
use Somethingwithproof\Casc\Engine\PlanAction;
use Somethingwithproof\Casc\Repository\StateRepository;
use Somethingwithproof\Casc\Secret\SecretResolver;

/**
 * Native Cacti XML template, signed .xml.gz package, and optional compiled
 * YAML template bridge provider.
 */
final class TemplatesProvider implements ResourceProvider {
	/** @var list<callable(string, array<string, mixed>): mixed> */
	private readonly array $templateCompilers;

	/**
	 * Construct the templates provider with optional YAML template compilers.
	 *
	 * @param list<callable(string, array<string, mixed>): mixed> $templateCompilers Compilers registered via casc_register_template_compilers.
	 */
	public function __construct(array $templateCompilers = []) {
		$this->templateCompilers = array_values($templateCompilers);
	}

	/**
	 * Return the templates domain name.
	 *
	 * @return string Domain identifier ('templates').
	 */
	public function domain(): string {
		return 'templates';
	}

	/**
	 * Return the JSON Schema Draft 2020-12 fragment for spec.templates.
	 *
	 * @return array<string, mixed> JSON Schema describing template reference entries.
	 */
	public function describeSchema(): array {
		return [
			'type'        => 'array',
			'description' => 'Declarative references to Cacti XML templates, signed .xml.gz packages, or compiled YAML templates.',
			'items'       => [
				'type'                 => 'object',
				'required'             => ['path'],
				'additionalProperties' => false,
				'properties'           => [
					'path' => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Relative path to a .xml, .xml.gz, .yaml, or .yml template file under the manifest directory.',
					],
					'sha256' => [
						'type'        => 'string',
						'pattern'     => '^[a-fA-F0-9]{64}$',
						'description' => 'Optional pinned SHA-256 digest of the on-disk template file.',
					],
					'profile' => [
						'type'        => 'string',
						'description' => 'Target Cacti data source profile name (defaults to System Default).',
					],
					'replaceSuggestedValues' => [
						'type'        => 'boolean',
						'description' => 'Whether to replace existing suggested values during import.',
					],
				],
			],
		];
	}

	/**
	 * Export host templates into a split templates directory when requested.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<string, mixed> $options Export options supporting splitTemplatesDir.
	 * @return array{spec: list<array<string, mixed>>}
	 */
	public function export(StateRepository $repo, array $options = []): array {
		$splitDir = is_string($options['splitTemplatesDir'] ?? null) ? trim($options['splitTemplatesDir']) : '';

		if ($splitDir === '') {
			return ['spec' => []];
		}

		if (!is_dir($splitDir) && !@mkdir($splitDir, 0750, true) && !is_dir($splitDir)) {
			throw new \RuntimeException(sprintf('Unable to create split-templates directory "%s"', $splitDir));
		}

		$entries = [];

		foreach ($repo->getHostTemplates() as $tpl) {
			$xml = $repo->exportTemplateXml('host_template', $tpl['id']);

			if ($xml === null || $xml === '') {
				continue;
			}

			$slug = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '_', $tpl['name']));
			$slug = trim($slug, '_') !== '' ? trim($slug, '_') : 'template_' . $tpl['id'];
			$file = $slug . '.xml';
			$dest = rtrim($splitDir, '/') . '/' . $file;

			if (file_put_contents($dest, $xml) === false) {
				throw new \RuntimeException(sprintf('Unable to write template export "%s"', $dest));
			}

			$entries[] = [
				'path'                   => basename(rtrim($splitDir, '/')) . '/' . $file,
				'sha256'                 => hash('sha256', $xml),
				'profile'                => 'System Default',
				'replaceSuggestedValues' => false,
			];
		}

		return ['spec' => $entries];
	}

	/**
	 * Validate template references, pinned digests, compiler availability, and template payloads.
	 *
	 * @param array<array-key, mixed> $desired Desired template list.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param array<string, mixed> $options Validation options (baseDir, templateCompilers).
	 * @return list<string> Validation error messages.
	 */
	public function validate(array $desired, SecretResolver $secrets, array $options = []): array {
		return $this->validateAndInspect($desired, $options)['errors'];
	}

	/**
	 * Validate template references and return inspected file payloads in a single pass
	 * so plan() avoids reading, hashing, and XML/YAML-parsing every template twice.
	 *
	 * @param array<array-key, mixed> $desired Desired template list.
	 * @param array<string, mixed> $options Validation options (baseDir, templateCompilers).
	 * @return array{errors: list<string>, inspected: array<string, array{resolvedPath: string, sha256: string, isPackage: bool, xmlContent: ?string}>}
	 */
	private function validateAndInspect(array $desired, array $options): array {
		$errors    = [];
		$inspected = [];
		$baseDir   = is_string($options['baseDir'] ?? null) ? $options['baseDir'] : '';
		$compilers = $this->resolveCompilers($options);

		if ($desired !== [] && !array_is_list($desired)) {
			return ['errors' => ['templates must be a list of template reference objects'], 'inspected' => []];
		}

		$seen = [];

		foreach ($desired as $idx => $entry) {
			if (!is_array($entry)) {
				$errors[] = sprintf('templates[%d] must be a map', (int) $idx);

				continue;
			}

			$path = is_string($entry['path'] ?? null) ? trim($entry['path']) : '';

			if ($path === '') {
				$errors[] = sprintf('templates[%d].path is required', (int) $idx);

				continue;
			}

			if (isset($seen[$path])) {
				$errors[] = sprintf('Duplicate template path "%s"', $path);
			}

			$seen[$path] = true;

			$sha256 = is_string($entry['sha256'] ?? null) ? $entry['sha256'] : null;

			if ($sha256 !== null && $sha256 !== '' && preg_match('/^[a-f0-9]{64}$/iD', $sha256) !== 1) {
				$errors[] = sprintf('templates[%d].sha256 must be a 64-character hex digest', (int) $idx);

				continue;
			}

			if ($baseDir === '') {
				if (TemplateBridge::isYamlTemplatePath($path) && $compilers === []) {
					$errors[] = sprintf(
						'templates[%d]: Template "%s" is a YAML template, which requires plugin_yaml or a registered casc_register_template_compilers provider',
						(int) $idx,
						$path
					);
				}

				continue;
			}

			try {
				$inspected[$path] = TemplateBridge::inspectTemplateFile($path, $baseDir, $sha256, $compilers);
			} catch (CodecException $e) {
				$errors[] = sprintf('templates[%d]: %s', (int) $idx, $e->getMessage());
			}
		}

		return ['errors' => $errors, 'inspected' => $inspected];
	}

	/**
	 * Plan template imports by comparing extracted Cacti template hashes against live DB hashes.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<array-key, mixed> $desired Desired template list.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param array<string, mixed> $options Planning options (baseDir, templateCompilers).
	 * @return list<PlanAction> Planned template actions.
	 */
	public function plan(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$actions  = [];
		$checked  = $this->validateAndInspect($desired, $options);
		$errors   = $checked['errors'];
		$cached   = $checked['inspected'];

		foreach ($errors as $error) {
			$actions[] = new PlanAction('templates', PlanAction::ERROR, 'templates', $error);
		}

		if ($errors !== []) {
			return $actions;
		}

		$baseDir   = is_string($options['baseDir'] ?? null) ? $options['baseDir'] : '';
		$compilers = $this->resolveCompilers($options);

		$knownHashes = [];

		foreach ([$repo->getHostTemplates(), $repo->getDataQueries(), $repo->getGraphTemplates()] as $group) {
			foreach ($group as $tpl) {
				if ($tpl['hash'] !== '') {
					$knownHashes[strtolower($tpl['hash'])] = true;
				}
			}
		}

		foreach ($desired as $entry) {
			if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
				continue;
			}

			$path   = trim($entry['path']);
			$sha256 = is_string($entry['sha256'] ?? null) ? $entry['sha256'] : null;

			if ($baseDir === '') {
				$actions[] = new PlanAction('templates', PlanAction::UPDATE, $path, sprintf('Import template "%s"', $path));

				continue;
			}

			try {
				$inspected = $cached[$path] ?? TemplateBridge::inspectTemplateFile($path, $baseDir, $sha256, $compilers);
			} catch (CodecException $e) {
				$actions[] = new PlanAction('templates', PlanAction::ERROR, $path, $e->getMessage());

				continue;
			}

			if ($inspected['isPackage']) {
				$actions[] = new PlanAction('templates', PlanAction::UPDATE, $path, sprintf('Import signed Cacti package "%s" (sha256 %s)', $path, substr($inspected['sha256'], 0, 12)));

				continue;
			}

			$hashes  = TemplateBridge::extractHashes((string) $inspected['xmlContent']);
			$missing = [];

			foreach ($hashes as $h) {
				if (!isset($knownHashes[$h])) {
					$missing[] = $h;
				}
			}

			if ($hashes === [] || $missing !== []) {
				$actions[] = new PlanAction(
					'templates',
					PlanAction::CREATE,
					$path,
					sprintf('Import Cacti template "%s" (%d new hashes, sha256 %s)', $path, count($missing), substr($inspected['sha256'], 0, 12))
				);
			}
		}

		return $actions;
	}

	/**
	 * Apply planned template imports via Cacti's native XML or package importer.
	 *
	 * @param StateRepository $repo Active state repository.
	 * @param array<array-key, mixed> $desired Desired template list.
	 * @param SecretResolver $secrets Secret reference resolver.
	 * @param array<string, mixed> $options Apply options (baseDir, templateCompilers).
	 * @return array{applied: list<PlanAction>, needsReplication: bool, needsPollerCache: bool}
	 */
	public function apply(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$plan = null;

		if (isset($options['plannedActions']) && is_array($options['plannedActions'])) {
			$plan = [];

			foreach ($options['plannedActions'] as $action) {
				if ($action instanceof PlanAction) {
					$plan[] = $action;
				}
			}
		}

		$plan    ??= $this->plan($repo, $desired, $secrets, $options);
		$baseDir   = is_string($options['baseDir'] ?? null) ? $options['baseDir'] : '';
		$compilers = $this->resolveCompilers($options);
		$applied   = [];

		if ($plan === []) {
			return [
				'applied'          => [],
				'needsReplication' => false,
				'needsPollerCache' => false,
			];
		}

		$byPath = [];

		foreach ($desired as $entry) {
			if (is_array($entry) && is_string($entry['path'] ?? null)) {
				$byPath[trim($entry['path'])] = $entry;
			}
		}

		foreach ($plan as $action) {
			if ($action->operation === PlanAction::ERROR) {
				throw new \RuntimeException($action->summary);
			}

			if (!$action->isMutation()) {
				continue;
			}

			$entry   = $byPath[$action->naturalKey] ?? [];
			$sha256  = is_string($entry['sha256'] ?? null) ? $entry['sha256'] : null;
			$profile = is_string($entry['profile'] ?? null) ? $entry['profile'] : 'System Default';
			$replace = (bool) ($entry['replaceSuggestedValues'] ?? false);

			if ($baseDir === '') {
				throw new \RuntimeException(sprintf('Cannot apply template "%s" without a manifest base directory', $action->naturalKey));
			}

			$inspected = TemplateBridge::inspectTemplateFile($action->naturalKey, $baseDir, $sha256, $compilers);

			$ok = $inspected['isPackage']
				? $repo->importTemplatePackage($inspected['resolvedPath'], $profile, $replace)
				: $repo->importTemplateXml((string) $inspected['xmlContent'], $profile, $replace);

			if (!$ok) {
				throw new \RuntimeException(sprintf('Template import failed for "%s"', $action->naturalKey));
			}

			$applied[] = $action;
		}

		return [
			'applied'          => $applied,
			'needsReplication' => $applied !== [],
			'needsPollerCache' => false,
		];
	}

	/**
	 * Resolve effective YAML template compilers from options override or constructor injection.
	 *
	 * @param array<string, mixed> $options Provider options map.
	 * @return list<callable(string, array<string, mixed>): mixed> Active compiler callbacks.
	 */
	private function resolveCompilers(array $options): array {
		if (!isset($options['templateCompilers']) || !is_array($options['templateCompilers'])) {
			return $this->templateCompilers;
		}

		$out = [];

		foreach ($options['templateCompilers'] as $candidate) {
			if (is_callable($candidate)) {
				/** @var callable(string, array<string, mixed>): mixed $candidate */
				$out[] = $candidate;
			}
		}

		return $out;
	}
}
