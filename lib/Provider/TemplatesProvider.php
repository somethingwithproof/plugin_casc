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
 * Native Cacti XML template and signed .xml.gz package bridge provider.
 */
final class TemplatesProvider implements ResourceProvider {
	public function domain(): string {
		return 'templates';
	}

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

	public function validate(array $desired, SecretResolver $secrets, array $options = []): array {
		$errors  = [];
		$baseDir = is_string($options['baseDir'] ?? null) ? $options['baseDir'] : '';

		if ($desired !== [] && !array_is_list($desired)) {
			return ['templates must be a list of template reference objects'];
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

			if ($baseDir !== '') {
				try {
					TemplateBridge::inspectTemplateFile($path, $baseDir, $sha256);
				} catch (CodecException $e) {
					$errors[] = sprintf('templates[%d]: %s', (int) $idx, $e->getMessage());
				}
			}
		}

		return $errors;
	}

	public function plan(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$actions = [];
		$errors  = $this->validate($desired, $secrets, $options);

		foreach ($errors as $error) {
			$actions[] = new PlanAction('templates', PlanAction::ERROR, 'templates', $error);
		}

		if ($errors !== []) {
			return $actions;
		}

		$baseDir = is_string($options['baseDir'] ?? null) ? $options['baseDir'] : '';

		$knownHashes = [];

		foreach (array_merge($repo->getHostTemplates(), $repo->getDataQueries(), $repo->getGraphTemplates()) as $tpl) {
			if ($tpl['hash'] !== '') {
				$knownHashes[strtolower($tpl['hash'])] = true;
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
				$inspected = TemplateBridge::inspectTemplateFile($path, $baseDir, $sha256);
			} catch (CodecException $e) {
				$actions[] = new PlanAction('templates', PlanAction::ERROR, $path, $e->getMessage());

				continue;
			}

			if ($inspected['isPackage']) {
				$actions[] = new PlanAction('templates', PlanAction::UPDATE, $path, sprintf('Import signed Cacti package "%s" (sha256 %s)', $path, substr($inspected['sha256'], 0, 12)));

				continue;
			}

			$hashes  = TemplateBridge::extractHashes((string) $inspected['xmlContent']);
			$missing = array_values(array_filter($hashes, static fn (string $h): bool => !isset($knownHashes[$h])));

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

	public function apply(StateRepository $repo, array $desired, SecretResolver $secrets, array $options = []): array {
		$plan    = $this->plan($repo, $desired, $secrets, $options);
		$baseDir = is_string($options['baseDir'] ?? null) ? $options['baseDir'] : '';
		$applied = [];

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

			$inspected = TemplateBridge::inspectTemplateFile($action->naturalKey, $baseDir, $sha256);

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
}
