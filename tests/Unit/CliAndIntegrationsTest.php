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
use Somethingwithproof\Casc\Engine\CascService;
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
		$this->tempDir = sys_get_temp_dir() . '/casc_cli_test_' . bin2hex(random_bytes(6));
		mkdir($this->tempDir, 0700, true);
	}

	protected function tearDown(): void {
		if ($this->tempDir !== '' && is_dir($this->tempDir)) {
			foreach (glob($this->tempDir . '/*') ?: [] as $file) {
				if (is_dir($file)) {
					foreach (glob($file . '/*') ?: [] as $sub) {
						@unlink($sub);
					}
					@rmdir($file);
				} else {
					@unlink($file);
				}
			}
			@rmdir($this->tempDir);
		}
	}

	public function testCliExportValidatePlanApplyAndDriftLifecycle(): void {
		$repo = new InMemoryStateRepository();
		$repo->hostTemplates[] = ['id' => 1, 'hash' => 'abcdef0123456789abcdef0123456789', 'name' => 'Linux Host', 'class' => ''];
		$repo->templateExports['host_template:1'] = '<cacti><hash_020102abcdef0123456789abcdef0123456789><name>Linux Host</name></hash_020102abcdef0123456789abcdef0123456789></cacti>';

		$service = new CascService($repo, new SecretResolver());
		$cli     = new CliRunner($service);

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

		// Drift (nagios) returns exit 1 (WARNING) before apply.
		$driftBefore = $cli->run(['drift', '--file=' . $desiredPath, '--format=nagios']);
		$this->assertSame(1, $driftBefore['exitCode']);
		$this->assertStringStartsWith('CASC WARNING -', $driftBefore['stdout']);

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

		// Missing file in nagios drift returns exit 3 (UNKNOWN).
		$driftUnknown = $cli->run(['drift', '--file=' . $this->tempDir . '/nope.yaml', '--format=nagios']);
		$this->assertSame(3, $driftUnknown['exitCode']);
		$this->assertStringStartsWith('CASC UNKNOWN -', $driftUnknown['stdout']);
	}

	public function testMcpAbilitiesAndDoctorChecksIntegration(): void {
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

		// Doctor checks.
		$secretDir = $this->tempDir;
		$checks    = DoctorChecks::build(
			static fn (): CascService => $service,
			static fn (): Settings => new Settings([$secretDir], $baseline, false)
		);

		$this->assertCount(3, $checks);
		$byId = [];

		foreach ($checks as $c) {
			$byId[$c['id']] = ($c['run'])();
		}

		$this->assertSame('ok', $byId['casc_secret_dirs']['status']);
		$this->assertSame('warning', $byId['casc_baseline_drift']['status']);
		$this->assertSame('ok', $byId['casc_plugin_providers']['status']);
	}

	public function testRealmsHooksSettingsAndSchemaMetadata(): void {
		$this->assertCount(3, Pages::realms());
		$this->assertArrayHasKey('abilities_register', Schema::HOOKS);
		$this->assertArrayHasKey('doctor_register_checks', Schema::HOOKS);
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
}
