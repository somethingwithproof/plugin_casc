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
use Somethingwithproof\Casc\Codec\CascCodec;
use Somethingwithproof\Casc\Engine\CascService;
use Somethingwithproof\Casc\Extension\PluginProviderDescriptor;
use Somethingwithproof\Casc\Extension\ProviderRegistry;
use Somethingwithproof\Casc\Metrics\CascMetricsCollector;
use Somethingwithproof\Casc\Repository\InMemoryStateRepository;
use Somethingwithproof\Casc\Secret\SecretResolver;
use Somethingwithproof\Casc\Settings;

final class CascMetricsCollectorTest extends TestCase {
	private string $tempDir = '';

	protected function setUp(): void {
		$this->tempDir             = sys_get_temp_dir() . '/casc_metrics_test_' . bin2hex(random_bytes(6));
		$GLOBALS['__casc_log']     = [];
		$GLOBALS['__casc_mock']    = [];
		$GLOBALS['__casc_options'] = [];
		mkdir($this->tempDir, 0700, true);
	}

	protected function tearDown(): void {
		$GLOBALS['__casc_mock']    = [];
		$GLOBALS['__casc_options'] = [];

		if ($this->tempDir !== '' && is_dir($this->tempDir)) {
			foreach (glob($this->tempDir . '/*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($this->tempDir);
		}
	}

	public function testCollectEmitsExpectedMetricsWithZeroSecrets(): void {
		$repo     = new InMemoryStateRepository();
		$registry = new ProviderRegistry();
		$registry->register(new PluginProviderDescriptor(
			'smokeping',
			'1',
			static fn (array $o): array => [],
			static fn (array $c): array => [],
			static fn (array $c, array $o): array => [],
			static fn (array $c, string $h, array $o): array => ['ok' => true]
		));

		$service  = new CascService($repo, new SecretResolver(), $registry);
		$baseline = $this->tempDir . '/baseline.yaml';
		file_put_contents($baseline, <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  settings:
    values:
      poller_interval: 60
  sites:
    - name: sjc1
      city: San Jose
YAML);

		$doc  = CascCodec::parse(CascCodec::YAML, (string) file_get_contents($baseline));
		$plan = $service->plan($doc, [], 'admin', true);

		$settings  = new Settings(['/run/cacti-secrets', '/etc/cacti/secrets'], $baseline, false);
		$collector = new CascMetricsCollector(
			static fn (): CascService => $service,
			static fn (): Settings => $settings
		);

		// Before apply: 1 provider, 2 drift items, 2 last plan actions, 0 apply ms, 0 errors, 2 secret dirs.
		$before = $collector->collect();
		$this->assertSame(1, $before['providers_registered']);
		$this->assertSame(2, $before['drift_items']);
		$this->assertSame(2, $before['last_plan_actions']);
		$this->assertSame(0, $before['last_apply_ms']);
		$this->assertSame(0, $before['validation_errors']);
		$this->assertSame(2, $before['secret_dirs_configured']);

		$lineBefore = $collector->collectFormatted();
		$this->assertSame(
			'providers_registered:1 drift_items:2 last_plan_actions:2 last_apply_ms:0 validation_errors:0 secret_dirs_configured:2',
			$lineBefore
		);
		$this->assertStringNotContainsString('/run/cacti-secrets', $lineBefore);
		$this->assertStringNotContainsString($baseline, $lineBefore);

		// Apply plan: drift drops to 0 and last_apply_ms is recorded in audit summary_json.
		$apply = $service->apply($doc, $plan->planHash, 'admin', 'cli');
		$this->assertTrue($apply->succeeded);

		$after = $collector->collect();
		$this->assertSame(0, $after['drift_items']);
		$this->assertIsInt($after['last_apply_ms']);
		$this->assertGreaterThanOrEqual(0, $after['last_apply_ms']);
		$this->assertSame('1', $collector->collectFormatted('providers_registered'));
		$this->assertSame('U', $collector->collectFormatted('unknown_metric'));
	}

	public function testCollectHandlesMissingBaselineParseErrorsValidationErrorsAndExceptions(): void {
		$repo    = new InMemoryStateRepository();
		$service = new CascService($repo, new SecretResolver());

		// No baseline configured -> 0 drift, 0 validation errors.
		$emptyCollector = new CascMetricsCollector(
			static fn (): CascService => $service,
			static fn (): Settings => new Settings([], '', false)
		);
		$this->assertSame(
			'providers_registered:0 drift_items:0 last_plan_actions:0 last_apply_ms:0 validation_errors:0 secret_dirs_configured:0',
			$emptyCollector->collectFormatted()
		);

		// Missing baseline file -> drift_items:U validation_errors:U.
		$missingCollector = new CascMetricsCollector(
			static fn (): CascService => $service,
			static fn (): Settings => new Settings([], $this->tempDir . '/missing.yaml', false)
		);
		$missing = $missingCollector->collect();
		$this->assertSame('U', $missing['drift_items']);
		$this->assertSame('U', $missing['validation_errors']);

		// Corrupt baseline syntax -> drift_items:U, validation_errors >= 1.
		$badSyntax = $this->tempDir . '/corrupt.yaml';
		file_put_contents($badSyntax, "apiVersion: wrong\nkind: Invalid\n");
		$corruptCollector = new CascMetricsCollector(
			static fn (): CascService => $service,
			static fn (): Settings => new Settings([], $badSyntax, false)
		);
		$corrupt = $corruptCollector->collect();
		$this->assertSame('U', $corrupt['drift_items']);
		$this->assertGreaterThanOrEqual(1, (int) $corrupt['validation_errors']);

		// Cross-parameter validation error -> drift_items:U, validation_errors >= 1.
		$invalidBaseline = $this->tempDir . '/invalid_snmp.yaml';
		file_put_contents($invalidBaseline, <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  devices:
    - key: bad-v3
      hostname: 10.0.0.1
      description: Bad V3
      snmpVersion: 3
YAML);
		$invalidCollector = new CascMetricsCollector(
			static fn (): CascService => $service,
			static fn (): Settings => new Settings([], $invalidBaseline, false)
		);
		$invalid = $invalidCollector->collect();
		$this->assertSame('U', $invalid['drift_items']);
		$this->assertGreaterThanOrEqual(1, (int) $invalid['validation_errors']);

		// Unsupported PHP floor -> every field returns U.
		$oldPhpCollector = new CascMetricsCollector(
			static fn (): CascService => $service,
			static fn (): Settings => new Settings([], '', false),
			'8.1.34'
		);
		$this->assertSame(
			'providers_registered:U drift_items:U last_plan_actions:U last_apply_ms:U validation_errors:U secret_dirs_configured:U',
			$oldPhpCollector->collectFormatted()
		);
		$this->assertSame('U', $oldPhpCollector->collectFormatted('providers_registered'));

		// Exception during collection -> every field returns U and no secret leaks.
		$failingCollector = new CascMetricsCollector(
			static function (): CascService {
				throw new \RuntimeException('db connection lost: password=topsecret');
			},
			static fn (): Settings => new Settings([], '', false)
		);
		$failingLine = $failingCollector->collectFormatted();
		$this->assertSame(
			'providers_registered:U drift_items:U last_plan_actions:U last_apply_ms:U validation_errors:U secret_dirs_configured:U',
			$failingLine
		);
		$this->assertStringNotContainsString('topsecret', $failingLine);
		$this->assertNotEmpty($GLOBALS['__casc_log']);
		$this->assertStringNotContainsString('topsecret', $GLOBALS['__casc_log'][0]['message']);

		// Format sanitizes negative or non-integer values to U.
		$sanitized = CascMetricsCollector::format([
			'providers_registered'   => -5,
			'drift_items'            => 'malicious:secret',
			'last_plan_actions'      => 4,
			'last_apply_ms'          => 12,
			'validation_errors'      => 0,
			'secret_dirs_configured' => 1,
		]);
		$this->assertSame(
			'providers_registered:U drift_items:U last_plan_actions:4 last_apply_ms:12 validation_errors:0 secret_dirs_configured:1',
			$sanitized
		);
	}

	public function testScriptServerEntryPointEmitsValidCactiTelemetryLine(): void {
		$GLOBALS['__casc_options']['casc_secret_dirs']   = '/run/secrets:/etc/cacti/secrets';
		$GLOBALS['__casc_options']['casc_baseline_file'] = '';

		$called_by_script_server = true;
		require_once dirname(__DIR__, 2) . '/scripts/ss_casc_stats.php';

		$all = ss_casc_stats();
		$this->assertMatchesRegularExpression(
			'/^providers_registered:0 drift_items:0 last_plan_actions:0 last_apply_ms:0 validation_errors:0 secret_dirs_configured:2$/',
			$all
		);
		$this->assertSame('2', ss_casc_stats('secret_dirs_configured'));
		$this->assertSame('U', ss_casc_stats('nonexistent_field'));
	}
}
