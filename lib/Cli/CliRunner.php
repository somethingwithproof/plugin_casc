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

namespace Somethingwithproof\Casc\Cli;

use Somethingwithproof\Casc\Codec\CascCodec;
use Somethingwithproof\Casc\Codec\CodecException;
use Somethingwithproof\Casc\Engine\CascService;

/**
 * Pure CLI command runner for cli/casc.php.
 *
 * Exit codes:
 *   export:   0 success, 1 error
 *   validate: 0 valid, 1 error/invalid
 *   plan:     0 no-op, 2 changes planned, 1 error
 *   apply:    0 applied/no-op, 1 error/drift/missing confirm
 *   drift:    json: 0 clean, 2 drift, 1 error; nagios: 0 OK, 1 WARNING, 2 CRITICAL, 3 UNKNOWN
 */
final class CliRunner {
	/**
	 * Construct a CLI command runner backed by a CasC service instance.
	 *
	 * @param CascService $service Active CasC orchestration service.
	 */
	public function __construct(
		private readonly CascService $service,
	) {
	}

	/**
	 * Execute a CasC CLI subcommand and return exit code, stdout, and stderr.
	 *
	 * @param list<string> $argv Arguments excluding script name.
	 * @param string $actor Operator identity recorded in plan/audit logs.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	public function run(array $argv, string $actor = 'cli'): array {
		if ($argv === [] || in_array($argv[0], ['-h', '--help', 'help'], true)) {
			return ['exitCode' => 0, 'stdout' => self::usage(), 'stderr' => ''];
		}

		$command = array_shift($argv);
		$parsed  = self::parseOptions($argv);

		if ($parsed['error'] !== null) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'ERROR: ' . $parsed['error'] . "\n" . self::usage()];
		}

		$options = $parsed['options'];

		return match ($command) {
			'export'   => $this->cmdExport($options),
			'validate' => $this->cmdValidate($options),
			'plan'     => $this->cmdPlan($options, $actor),
			'apply'    => $this->cmdApply($options, $actor),
			'drift'    => $this->cmdDrift($options),
			default    => ['exitCode' => 1, 'stdout' => '', 'stderr' => sprintf("ERROR: Unknown command \"%s\"\n%s", $command, self::usage())],
		};
	}

	/**
	 * Return CLI usage instructions for all supported CasC subcommands.
	 *
	 * @return string Multi-line usage help text.
	 */
	public static function usage(): string {
		return "usage: cli/casc.php export [--format=yaml|xml] [--output=PATH] [--split-templates=DIR] [--omit-secrets]\n"
			. "       cli/casc.php validate --file=PATH [--format=yaml|xml]\n"
			. "       cli/casc.php plan --file=PATH [--format=text|json] [--prune=DOMAINS]\n"
			. "       cli/casc.php apply --file=PATH --confirm=<PLAN_HASH> [--prune=DOMAINS]\n"
			. "       cli/casc.php drift --file=PATH [--format=json|nagios] [--prune=DOMAINS]\n";
	}

	/**
	 * @param array<string, string|bool> $options
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdExport(array $options): array {
		$format = is_string($options['format'] ?? null) ? strtolower($options['format']) : CascCodec::YAML;

		if (!CascCodec::isFormat($format)) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => sprintf("ERROR: Unsupported export format \"%s\"; use yaml or xml\n", $format)];
		}

		$exportOptions = [
			'omitSecrets'       => ($options['omit-secrets'] ?? false) === true,
			'splitTemplatesDir' => is_string($options['split-templates'] ?? null) ? $options['split-templates'] : '',
		];

		try {
			$content = $this->service->export($format, $exportOptions);
		} catch (\Throwable $e) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'ERROR: ' . $e->getMessage() . "\n"];
		}

		$outputPath = is_string($options['output'] ?? null) ? $options['output'] : '';

		if ($outputPath === '' || $outputPath === '-') {
			return ['exitCode' => 0, 'stdout' => $content, 'stderr' => ''];
		}

		if (file_put_contents($outputPath, $content) === false) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => sprintf("ERROR: Failed to write \"%s\"\n", $outputPath)];
		}

		return ['exitCode' => 0, 'stdout' => sprintf("Exported %s manifest to %s\n", $format, $outputPath), 'stderr' => ''];
	}

	/**
	 * @param array<string, string|bool> $options
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdValidate(array $options): array {
		$loaded = $this->loadManifest($options);

		if ($loaded['error'] !== null || $loaded['document'] === null) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => $loaded['error'] ?? "ERROR: Failed to load manifest\n"];
		}

		$errors = $this->service->validate($loaded['document'], ['baseDir' => $loaded['baseDir']]);

		if ($errors !== []) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => "Validation failed:\n- " . implode("\n- ", $errors) . "\n"];
		}

		return ['exitCode' => 0, 'stdout' => "Manifest is valid.\n", 'stderr' => ''];
	}

	/**
	 * Execute the plan subcommand and format output as text or JSON.
	 *
	 * @param array<string, string|bool> $options Parsed CLI options.
	 * @param string $actor Operator identity recorded in plugin_casc_plan.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdPlan(array $options, string $actor): array {
		$loaded = $this->loadManifest($options);

		if ($loaded['error'] !== null || $loaded['document'] === null) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => $loaded['error'] ?? "ERROR: Failed to load manifest\n"];
		}

		$outFormat = is_string($options['format'] ?? null) ? strtolower($options['format']) : 'text';

		if (!in_array($outFormat, ['text', 'json'], true)) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => sprintf("ERROR: Unsupported plan format \"%s\"; use text or json\n", $outFormat)];
		}

		$plan = $this->service->plan(
			$loaded['document'],
			[
				'baseDir' => $loaded['baseDir'],
				'prune'   => self::parsePrune($options),
			],
			$actor
		);

		$stdout = $outFormat === 'json' ? $plan->formatJson() : $plan->formatText();

		if ($plan->hasErrors()) {
			return ['exitCode' => 1, 'stdout' => $stdout, 'stderr' => "ERROR: Plan contains validation or resolution errors.\n"];
		}

		return ['exitCode' => $plan->hasChanges() ? 2 : 0, 'stdout' => $stdout, 'stderr' => ''];
	}

	/**
	 * Execute the hash-confirmed apply subcommand.
	 *
	 * @param array<string, string|bool> $options Parsed CLI options.
	 * @param string $actor Operator identity recorded in plugin_casc_audit.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdApply(array $options, string $actor): array {
		$confirm = is_string($options['confirm'] ?? null) ? trim($options['confirm']) : '';

		if ($confirm === '') {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => "ERROR: apply requires --confirm=<PLAN_HASH> from a prior plan run.\n"];
		}

		$loaded = $this->loadManifest($options);

		if ($loaded['error'] !== null || $loaded['document'] === null) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => $loaded['error'] ?? "ERROR: Failed to load manifest\n"];
		}

		$result = $this->service->apply(
			$loaded['document'],
			$confirm,
			$actor,
			'cli',
			[
				'baseDir' => $loaded['baseDir'],
				'prune'   => self::parsePrune($options),
			]
		);

		if (!$result->succeeded) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => "ERROR: " . implode("\nERROR: ", $result->errors) . "\n"];
		}

		$summary = sprintf(
			"Applied plan %s (%d actions; replication=%s; pollerCache=%s; auditId=%d)\n",
			$result->planHash,
			count($result->applied),
			$result->replicationTriggered ? 'yes' : 'no',
			$result->pollerCacheUpdated ? 'yes' : 'no',
			$result->auditId
		);

		return ['exitCode' => 0, 'stdout' => $summary, 'stderr' => ''];
	}

	/**
	 * @param array<string, string|bool> $options
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdDrift(array $options): array {
		$outFormat = is_string($options['format'] ?? null) ? strtolower($options['format']) : 'json';

		if (!in_array($outFormat, ['json', 'nagios'], true)) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => sprintf("ERROR: Unsupported drift format \"%s\"; use json or nagios\n", $outFormat)];
		}

		$loaded = $this->loadManifest($options);

		if ($loaded['error'] !== null || $loaded['document'] === null) {
			$errText = $loaded['error'] ?? "ERROR: Failed to load manifest\n";

			if ($outFormat === 'nagios') {
				return ['exitCode' => 3, 'stdout' => 'CASC UNKNOWN - ' . trim($errText) . "\n", 'stderr' => ''];
			}

			return ['exitCode' => 1, 'stdout' => '', 'stderr' => $errText];
		}

		$plan = $this->service->plan(
			$loaded['document'],
			[
				'baseDir' => $loaded['baseDir'],
				'prune'   => self::parsePrune($options),
			],
			'drift',
			false
		);

		if ($outFormat === 'nagios') {
			$nagios = $plan->formatNagios();

			return ['exitCode' => $nagios['code'], 'stdout' => $nagios['line'] . "\n", 'stderr' => ''];
		}

		$exit = $plan->hasErrors() ? 1 : ($plan->hasChanges() ? 2 : 0);

		return ['exitCode' => $exit, 'stdout' => $plan->formatJson(), 'stderr' => ''];
	}

	/**
	 * @param array<string, string|bool> $options
	 * @return array{document: \Somethingwithproof\Casc\Model\CascDocument|null, baseDir: string, error: ?string}
	 */
	private function loadManifest(array $options): array {
		$file = is_string($options['file'] ?? null) ? trim($options['file']) : '';

		if ($file === '') {
			return ['document' => null, 'baseDir' => '', 'error' => "ERROR: --file=PATH is required\n"];
		}

		try {
			$raw    = CascCodec::readFile($file);
			$format = CascCodec::detectFormatFromPath($file);

			if (isset($options['input-format']) && is_string($options['input-format']) && CascCodec::isFormat($options['input-format'])) {
				$format = $options['input-format'];
			}

			$doc = CascCodec::parse($format, $raw);

			return ['document' => $doc, 'baseDir' => dirname($file), 'error' => null];
		} catch (CodecException $e) {
			return ['document' => null, 'baseDir' => '', 'error' => "ERROR: " . implode("\nERROR: ", $e->lines()) . "\n"];
		}
	}

	/**
	 * @param array<string, string|bool> $options
	 * @return list<string>
	 */
	private static function parsePrune(array $options): array {
		$raw = is_string($options['prune'] ?? null) ? $options['prune'] : '';

		if ($raw === '') {
			return [];
		}

		return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));
	}

	/**
	 * @param list<string> $args
	 * @return array{options: array<string, string|bool>, error: ?string}
	 */
	private static function parseOptions(array $args): array {
		$options = [];
		$count   = count($args);

		for ($i = 0; $i < $count; $i++) {
			$arg = $args[$i];

			if (!str_starts_with($arg, '--')) {
				return ['options' => [], 'error' => sprintf('Unexpected positional argument "%s"', $arg)];
			}

			$body = substr($arg, 2);

			if (str_contains($body, '=')) {
				[$key, $val]   = explode('=', $body, 2);
				$options[$key] = $val;

				continue;
			}

			if (in_array($body, ['omit-secrets', 'apply'], true)) {
				$options[$body] = true;

				continue;
			}

			if ($i + 1 < $count && !str_starts_with($args[$i + 1], '--')) {
				$options[$body] = $args[++$i];

				continue;
			}

			return ['options' => [], 'error' => sprintf('Option "--%s" requires a value', $body)];
		}

		return ['options' => $options, 'error' => null];
	}
}
