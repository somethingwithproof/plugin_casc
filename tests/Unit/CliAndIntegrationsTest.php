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

namespace Somethingwithproof\Casc\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Casc\Cli\CliRunner;
use Somethingwithproof\Casc\Codec\YamlReader;
use Somethingwithproof\Casc\Engine\CascService;
use Somethingwithproof\Casc\Extension\ProviderRegistry;
use Somethingwithproof\Casc\Integration\ApiResources;
use Somethingwithproof\Casc\Integration\DoctorChecks;
use Somethingwithproof\Casc\Integration\McpAbilities;
use Somethingwithproof\Casc\Pages;
use Somethingwithproof\Casc\Repository\InMemoryStateRepository;
use Somethingwithproof\Casc\Schema;
use Somethingwithproof\Casc\Secret\SecretResolver;
use Somethingwithproof\Casc\Settings;

final class CliAndIntegrationsTest extends TestCase {
	private string $tempDir = '';

	protected function setUp(): void {
		$this->tempDir          = sys_get_temp_dir() . '/casc_cli_test_' . bin2hex(random_bytes(6));
		$GLOBALS['__casc_mock'] = [];
		$GLOBALS['__casc_options'] = [];
		mkdir($this->tempDir, 0700, true);
	}

	protected function tearDown(): void {
		$GLOBALS['__casc_mock']    = [];
		$GLOBALS['__casc_options'] = [];

		if ($this->tempDir !== '' && is_dir($this->tempDir)) {
			$this->removeTree($this->tempDir);
		}
	}

	public function testCliExportValidatePlanApplyAndDriftLifecycle(): void {
		$repo                                      = new InMemoryStateRepository();
		$repo->hostTemplates[]                     = ['id' => 1, 'hash' => 'abcdef0123456789abcdef0123456789', 'name' => 'Linux Host', 'class' => ''];
		$repo->templateExports['host_template:1']  = '<cacti><hash_020102abcdef0123456789abcdef0123456789><name>Linux Host</name></hash_020102abcdef0123456789abcdef0123456789></cacti>';

		$service = new CascService($repo, new SecretResolver());
		$cli     = new CliRunner($service);

		// Help and unknown command handling.
		$helpRes = $cli->run(['--help']);
		$this->assertSame(0, $helpRes['exitCode']);
		$this->assertStringContainsString('usage:', $helpRes['stdout']);

		$badCmd = $cli->run(['bogus']);
		$this->assertSame(1, $badCmd['exitCode']);
		$this->assertStringContainsString('Unknown command', $badCmd['stderr']);

		$badOpt = $cli->run(['export', '--format']);
		$this->assertSame(1, $badOpt['exitCode']);
		$this->assertStringContainsString('requires a value', $badOpt['stderr']);

		$badPos = $cli->run(['export', 'positional']);
		$this->assertSame(1, $badPos['exitCode']);

		$badFmt = $cli->run(['export', '--format=toml']);
		$this->assertSame(1, $badFmt['exitCode']);

		// Export to stdout.
		$stdoutExp = $cli->run(['export', '--format=yaml']);
		$this->assertSame(0, $stdoutExp['exitCode']);
		$this->assertStringContainsString('apiVersion: cacti.net/v1alpha1', $stdoutExp['stdout']);

		// Export with split templates.
		$exportPath = $this->tempDir . '/exported.yaml';
		$tplDir     = $this->tempDir . '/templates';
		$expRes     = $cli->run(['export', '--format=yaml', '--output=' . $exportPath, '--split-templates=' . $tplDir]);

		$this->assertSame(0, $expRes['exitCode']);
		$this->assertFileExists($exportPath);
		$this->assertFileExists($tplDir . '/linux_host.xml');

		// Validate exported manifest.
		$valRes = $cli->run(['validate', '--file=' . $exportPath]);
		$this->assertSame(0, $valRes['exitCode']);

		// Write desired manifest with a new site and setting.
		$desiredPath = $this->tempDir . '/desired.yaml';
		file_put_contents($desiredPath, <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
metadata:
  name: lab
spec:
  settings:
    values:
      poller_interval: 60
  sites:
    - name: lab-west
      city: Seattle
YAML);

		// Plan returns exit 2 when changes are planned and prints planHash in JSON.
		$planRes = $cli->run(['plan', '--file=' . $desiredPath, '--format=json']);
		$this->assertSame(2, $planRes['exitCode']);

		/** @var array{planHash: string, counts: array{create: int}} $planJson */
		$planJson = json_decode($planRes['stdout'], true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(2, $planJson['counts']['create']);
		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $planJson['planHash']);

		// Plan text format & unsupported format error.
		$planTextRes = $cli->run(['plan', '--file', $desiredPath, '--format=text', '--prune=sites']);
		$this->assertSame(2, $planTextRes['exitCode']);
		$this->assertSame(1, $cli->run(['plan', '--file=' . $desiredPath, '--format=csv'])['exitCode']);

		// Drift (nagios) returns exit 1 (WARNING) before apply; JSON drift returns exit 2.
		$driftBefore = $cli->run(['drift', '--file=' . $desiredPath, '--format=nagios']);
		$this->assertSame(1, $driftBefore['exitCode']);
		$this->assertStringStartsWith('CASC WARNING -', $driftBefore['stdout']);

		$driftJsonBefore = $cli->run(['drift', '--file=' . $desiredPath, '--format=json']);
		$this->assertSame(2, $driftJsonBefore['exitCode']);
		$this->assertSame(1, $cli->run(['drift', '--file=' . $desiredPath, '--format=csv'])['exitCode']);

		// Apply without --confirm is refused.
		$applyNoConfirm = $cli->run(['apply', '--file=' . $desiredPath]);
		$this->assertSame(1, $applyNoConfirm['exitCode']);

		// Apply with confirmed plan hash succeeds.
		$applyOk = $cli->run(['apply', '--file=' . $desiredPath, '--confirm=' . $planJson['planHash']]);
		$this->assertSame(0, $applyOk['exitCode']);

		// Drift (nagios) returns exit 0 (OK) after apply.
		$driftAfter = $cli->run(['drift', '--file=' . $desiredPath, '--format=nagios']);
		$this->assertSame(0, $driftAfter['exitCode']);
		$this->assertStringStartsWith('CASC OK -', $driftAfter['stdout']);

		// Missing file in nagios drift returns exit 3 (UNKNOWN) and JSON returns exit 1.
		$driftUnknown = $cli->run(['drift', '--file=' . $this->tempDir . '/nope.yaml', '--format=nagios']);
		$this->assertSame(3, $driftUnknown['exitCode']);
		$this->assertStringStartsWith('CASC UNKNOWN -', $driftUnknown['stdout']);
		$this->assertSame(1, $cli->run(['drift', '--file=' . $this->tempDir . '/nope.yaml', '--format=json'])['exitCode']);
	}

	public function testMcpAbilitiesAndDoctorChecksContractModeListAndRun(): void {
		$repo     = new InMemoryStateRepository();
		$service  = new CascService($repo, new SecretResolver());
		$baseline = $this->tempDir . '/baseline.yaml';
		file_put_contents($baseline, "apiVersion: cacti.net/v1alpha1\nkind: CactiConfig\nspec:\n  sites:\n    - name: s1\n");

		$registered = [];
		$registrar  = new class ($registered) {
			/** @param array<string, array<string, mixed>> $registered */
			public function __construct(public array &$registered) {
			}

			/** @param array<string, mixed> $definition */
			public function register(string $id, array $definition): void {
				$this->registered[$id] = $definition;
			}
		};

		McpAbilities::register(
			$registrar,
			static fn (): CascService => $service,
			static fn (): string => $baseline,
			static fn (int $uid): bool => $uid === 1
		);

		$this->assertArrayHasKey('casc-get-status', $registered);
		$this->assertArrayHasKey('casc-check-drift', $registered);
		$this->assertSame(['readOnly' => true, 'destructive' => false, 'idempotent' => true], $registered['casc-check-drift']['annotations']);

		/** @var callable(int, \stdClass): array<string, mixed> $execStatus */
		$execStatus = $registered['casc-get-status']['execute'];
		$statusOut  = $execStatus(1, new \stdClass());
		$this->assertTrue($statusOut['baselineConfigured']);

		/** @var callable(int, \stdClass): array<string, mixed> $execDrift */
		$execDrift = $registered['casc-check-drift']['execute'];
		$driftOut  = $execDrift(1, new \stdClass());
		$this->assertTrue($driftOut['configured']);
		$this->assertTrue($driftOut['drifted']);
		$this->assertSame(1, $driftOut['create']);

		// Doctor checks: mode=list must return metadata-only descriptors with status='info' without invoking factories.
		$factoryCalls = 0;
		$listChecks   = DoctorChecks::build(
			static function () use ($service, &$factoryCalls): CascService {
				$factoryCalls++;

				return $service;
			},
			static function () use (&$factoryCalls): Settings {
				$factoryCalls++;

				return new Settings([], '', false);
			},
			['mode' => 'list', 'plugin' => 'casc']
		);

		$this->assertSame(0, $factoryCalls, 'mode=list must not execute runtime factories');
		$this->assertCount(3, $listChecks);

		$expectedIds = ['casc.secret_dirs', 'casc.baseline_drift', 'casc.plugin_providers'];
		$seenIds     = [];

		foreach ($listChecks as $check) {
			$this->assertValidDoctorDescriptor($check);
			$this->assertSame('info', $check['status']);
			$seenIds[] = $check['id'];
		}

		$this->assertSame($expectedIds, $seenIds);

		// Doctor checks: mode=run executes checks and returns ok|warn|fail statuses.
		$secretDir = $this->tempDir;
		$runChecks = DoctorChecks::build(
			static fn (): CascService => $service,
			static fn (): Settings => new Settings([$secretDir], $baseline, false),
			['mode' => 'run', 'plugin' => 'casc']
		);

		$this->assertCount(3, $runChecks);
		$byId = [];

		foreach ($runChecks as $check) {
			$this->assertValidDoctorDescriptor($check);
			$byId[$check['id']] = $check;
		}

		$this->assertSame('ok', $byId['casc.secret_dirs']['status']);
		$this->assertSame('warn', $byId['casc.baseline_drift']['status']);
		$this->assertNotSame('', $byId['casc.baseline_drift']['remediation']);
		$this->assertSame('ok', $byId['casc.plugin_providers']['status']);

		// Doctor checks: omitted context defaults safely to mode=run.
		$defaultChecks = DoctorChecks::build(
			static fn (): CascService => $service,
			static fn (): Settings => new Settings([], '', false)
		);
		$this->assertSame('ok', $defaultChecks[0]['status']);
		$this->assertSame('ok', $defaultChecks[1]['status']);
		$this->assertSame('ok', $defaultChecks[2]['status']);
	}

	public function testDoctorChecksWarningAndFailureBranches(): void {
		$repo     = new InMemoryStateRepository();
		$registry = new ProviderRegistry();
		$registry->dispatchHooks([
			['name' => 'broken', 'file' => '../evil.php', 'function' => 'nope'],
		]);
		$service = new CascService($repo, new SecretResolver(), $registry);

		$worldWritable = $this->tempDir . '/ww';
		mkdir($worldWritable, 0777);
		chmod($worldWritable, 0777);

		$symTarget = $this->tempDir . '/real';
		mkdir($symTarget, 0700);
		$symDir = $this->tempDir . '/sym';
		symlink($symTarget, $symDir);

		$missingDir = $this->tempDir . '/does_not_exist';

		// Broken manifest (parse failure -> status 'fail').
		$brokenBaseline = $this->tempDir . '/broken.yaml';
		file_put_contents($brokenBaseline, "apiVersion: wrong\nkind: Wrong\n");

		$checks = DoctorChecks::build(
			static fn (): CascService => $service,
			static fn (): Settings => new Settings([$missingDir, $symDir, $worldWritable], $brokenBaseline, false),
			['mode' => 'run', 'plugin' => 'casc']
		);

		foreach ($checks as $check) {
			$this->assertValidDoctorDescriptor($check);
		}

		$this->assertSame('warn', $checks[0]['status']);
		$this->assertStringContainsString('Directory missing', $checks[0]['message']);
		$this->assertStringContainsString('symlink', $checks[0]['message']);
		$this->assertStringContainsString('world-writable', $checks[0]['message']);
		$this->assertSame('fail', $checks[1]['status']);
		$this->assertSame('warn', $checks[2]['status']);

		// Baseline with plan error -> status 'fail'.
		$errorBaseline = $this->tempDir . '/plan_error.yaml';
		file_put_contents($errorBaseline, "apiVersion: cacti.net/v1alpha1\nkind: CactiConfig\nspec:\n  devices:\n    - hostname: 10.0.0.1\n      description: Bad\n      site: missing-site\n");

		$planErrChecks = DoctorChecks::build(
			static fn (): CascService => $service,
			static fn (): Settings => new Settings([], $errorBaseline, false),
			['mode' => 'run']
		);
		$this->assertSame('fail', $planErrChecks[1]['status']);

		// Unreadable/missing baseline -> status 'warn'.
		$missingBaseline       = $this->tempDir . '/missing.yaml';
		$missingBaselineChecks = DoctorChecks::build(
			static fn (): CascService => $service,
			static fn (): Settings => new Settings([], $missingBaseline, false),
			['mode' => 'run']
		);
		$this->assertSame('warn', $missingBaselineChecks[1]['status']);

		// Clean matching baseline -> status 'ok'.
		$cleanBaseline = $this->tempDir . '/clean.yaml';
		file_put_contents($cleanBaseline, "apiVersion: cacti.net/v1alpha1\nkind: CactiConfig\nmetadata:\n  name: clean\n");
		$cleanChecks = DoctorChecks::build(
			static fn (): CascService => $service,
			static fn (): Settings => new Settings([], $cleanBaseline, false),
			['mode' => 'run']
		);
		$this->assertSame('ok', $cleanChecks[1]['status']);
	}

	public function testDebugInfoApiResourcesAndStaticSchemaContract(): void {
		$repo     = new InMemoryStateRepository();
		$service  = new CascService($repo, new SecretResolver());
		$baseline = $this->tempDir . '/base.yaml';
		file_put_contents($baseline, "apiVersion: cacti.net/v1alpha1\nkind: CactiConfig\n");

		$sections = DoctorChecks::debugInfo(
			static fn (): CascService => $service,
			static fn (): Settings => new Settings(['/run/secrets'], $baseline, false),
			'0.1.0'
		);

		$this->assertCount(1, $sections);
		$section = $sections[0];
		$this->assertSame('casc.runtime', $section['id']);
		$this->assertSame('casc', $section['plugin']);
		$this->assertSame('CasC Runtime', $section['label']);
		$this->assertFalse($section['private']);
		$this->assertSame('0.1.0', $section['fields']['version']['value']);
		$this->assertSame((string) Schema::VERSION, $section['fields']['schema_version']['value']);
		$this->assertSame('yes', $section['fields']['baseline_configured']['value']);
		$this->assertSame(1, $section['fields']['secret_dirs_count']['value']);
		$this->assertSame(0, $section['fields']['registered_providers_count']['value']);

		// Ensure no secret file paths leak in debug info JSON.
		$encoded = json_encode($sections, JSON_THROW_ON_ERROR);
		$this->assertStringNotContainsString('/run/secrets', $encoded);

		// api_register_resources returns canonical plugin_api descriptor referencing schema/casc.schema.yaml.
		$existing = [['plugin' => 'other', 'collection' => 'otherHealth', 'realm_read' => 'other_view.php', 'handlers' => ['list' => static fn (): array => []]]];
		$combined = ApiResources::register(
			$existing,
			static fn (): CascService => $service,
			static fn (): Settings => new Settings([], $baseline, false)
		);
		$this->assertCount(2, $combined);

		$cascResources = array_values(array_filter($combined, static fn (array $r): bool => ($r['plugin'] ?? '') === 'casc'));
		$this->assertCount(1, $cascResources);
		$res = $cascResources[0];
		$this->assertSame('casc', $res['plugin']);
		$this->assertSame('cascStatus', $res['collection']);
		$this->assertMatchesRegularExpression('/^[a-z][A-Za-z0-9]{2,63}$/', (string) $res['collection']);
		$this->assertSame('casc_view.php', $res['realm_read']);
		$this->assertSame('schema/casc.schema.yaml', $res['schema']);
		$this->assertIsArray($res['handlers']);
		$this->assertIsCallable($res['handlers']['list'] ?? null);
		$this->assertIsCallable($res['handlers']['get'] ?? null);

		$listed = ($res['handlers']['list'])([]);
		$this->assertCount(1, $listed);
		$this->assertSame('summary', $listed[0]['id']);
		$this->assertTrue($listed[0]['baselineConfigured']);

		$fetched = ($res['handlers']['get'])('summary', []);
		$this->assertIsArray($fetched);
		$this->assertSame('cascStatus/summary', $fetched['name']);

		$schemaEntry = ($res['handlers']['get'])('schema', []);
		$this->assertIsArray($schemaEntry);
		$this->assertSame('https://json-schema.org/draft/2020-12/schema', $schemaEntry['schema']['$schema']);
		$this->assertNull(($res['handlers']['get'])('unknown', []));

		// Verify static schema/casc.schema.yaml parses cleanly and preserves strict config/state split.
		$schemaPath = dirname(__DIR__, 2) . '/schema/casc.schema.yaml';
		$this->assertFileExists($schemaPath);
		$raw = file_get_contents($schemaPath);
		$this->assertIsString($raw);
		$parsed = YamlReader::parse($raw);
		$this->assertSame('casc', $parsed->data['plugin']);
		$this->assertArrayHasKey('config', $parsed->data);
		$this->assertArrayHasKey('state', $parsed->data);
		$this->assertArrayHasKey('casc_secret_dirs', $parsed->data['config']['settings']);
		$this->assertArrayHasKey('casc_baseline_file', $parsed->data['config']['settings']);
		$this->assertArrayHasKey('casc_uninstall_keep_tables', $parsed->data['config']['settings']);
		$this->assertArrayHasKey('plugin_casc_audit', $parsed->data['state']['tables']);
		$this->assertArrayHasKey('plugin_casc_plan', $parsed->data['state']['tables']);
	}

	public function testRealmsHooksSettingsAndSchemaMetadata(): void {
		$this->assertCount(3, Pages::realms());
		$this->assertArrayHasKey('abilities_register', Schema::HOOKS);
		$this->assertArrayHasKey('doctor_register_checks', Schema::HOOKS);
		$this->assertArrayHasKey('doctor_register_debug_info', Schema::HOOKS);
		$this->assertArrayHasKey('api_register_resources', Schema::HOOKS);
		$this->assertArrayHasKey('casc_describe_schema', Schema::HOOKS);
		$this->assertArrayHasKey('plugin_casc_audit', Schema::tables());
		$this->assertArrayHasKey('plugin_casc_plan', Schema::tables());

		$settings = Settings::fromRow([
			'casc_secret_dirs'           => '/run/secrets:/etc/cacti/secrets',
			'casc_baseline_file'         => '/etc/cacti/baseline.yaml',
			'casc_uninstall_keep_tables' => 'on',
		]);

		$this->assertSame(['/run/secrets', '/etc/cacti/secrets'], $settings->secretDirs);
		$this->assertSame('/etc/cacti/baseline.yaml', $settings->baselineFile);
		$this->assertTrue($settings->uninstallKeepTables);
	}

	/**
	 * Assert every plugin_doctor#54 check descriptor invariant.
	 *
	 * @param array<string, mixed> $check
	 */
	private function assertValidDoctorDescriptor(array $check): void {
		foreach (['id', 'plugin', 'category', 'title', 'status', 'message', 'details', 'remediation'] as $key) {
			$this->assertArrayHasKey($key, $check, 'Missing required doctor check key: ' . $key);
		}

		$this->assertIsString($check['id']);
		$this->assertStringStartsWith('casc.', $check['id']);
		$this->assertMatchesRegularExpression('/\A[a-z0-9_.]{1,128}\z/', $check['id']);
		$this->assertSame('casc', $check['plugin']);
		$this->assertSame('CasC', $check['category']);
		$this->assertIsString($check['title']);
		$this->assertNotSame('', trim($check['title']));
		$this->assertLessThanOrEqual(256, strlen($check['title']));
		$this->assertContains($check['status'], ['ok', 'warn', 'fail', 'info']);
		$this->assertIsString($check['message']);
		$this->assertNotSame('', trim($check['message']));
		$this->assertLessThanOrEqual(2048, strlen($check['message']));
		$this->assertIsArray($check['details']);
		$this->assertLessThanOrEqual(16384, strlen(json_encode($check['details'], JSON_THROW_ON_ERROR)));
		$this->assertIsString($check['remediation']);
		$this->assertLessThanOrEqual(2048, strlen($check['remediation']));
	}

	private function removeTree(string $path): void {
		foreach (scandir($path) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$full = $path . '/' . $entry;

			if (is_link($full) || is_file($full)) {
				@chmod($full, 0600);
				@unlink($full);
			} elseif (is_dir($full)) {
				$this->removeTree($full);
			}
		}

		@rmdir($path);
	}
}
