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
use Somethingwithproof\Casc\Extension\ProviderRegistrar;
use Somethingwithproof\Casc\Extension\ProviderRegistry;
use Somethingwithproof\Casc\Repository\InMemoryStateRepository;
use Somethingwithproof\Casc\Secret\SecretResolver;

final class PluginProviderExtensionTest extends TestCase {
	public function testFaultIsolatedHookDispatchPreservesHealthyProviders(): void {
		$registry = new ProviderRegistry();

		$healthyFn = static function (ProviderRegistrar $registrar): void {
			$registrar->register(new PluginProviderDescriptor(
				'smokeping',
				'1',
				static fn (array $opts): array => ['config' => ['freshnessAllowance' => 300], 'reference' => ['mount' => '/var/lib/smokeping']],
				static fn (array $cfg): array => isset($cfg['freshnessAllowance']) && is_int($cfg['freshnessAllowance']) ? [] : ['freshnessAllowance must be int'],
				static fn (array $cfg, array $opts): array => [['operation' => 'update', 'key' => 'freshnessAllowance', 'message' => 'Set freshnessAllowance']],
				static fn (array $cfg, string $hash, array $opts): array => ['ok' => true]
			));
		};

		$throwingFn = static function (ProviderRegistrar $registrar): void {
			throw new \RuntimeException('boom during plugin registration');
		};

		$hijackFn = static function (ProviderRegistrar $registrar): void {
			$registrar->register(new PluginProviderDescriptor(
				'smokeping',
				'1',
				static fn (array $opts): array => [],
				static fn (array $cfg): array => [],
				static fn (array $cfg, array $opts): array => [],
				static fn (array $cfg, string $hash, array $opts): array => ['ok' => true]
			));
		};

		// Register via named callables using global closures via helper registry dispatch.
		$fnHealthy = 'casc_test_hook_healthy_' . bin2hex(random_bytes(4));
		$fnThrow   = 'casc_test_hook_throw_' . bin2hex(random_bytes(4));
		$fnHijack  = 'casc_test_hook_hijack_' . bin2hex(random_bytes(4));

		$GLOBALS[$fnHealthy] = $healthyFn;
		$GLOBALS[$fnThrow]   = $throwingFn;
		$GLOBALS[$fnHijack]  = $hijackFn;

		// Use an eval-free closure wrapper? ProviderRegistry::dispatchHooks checks is_callable($function).
		// Pass a real static method name for each behavior!
		$registry->dispatchHooks([
			['name' => 'smokeping', 'file' => 'setup.php', 'function' => self::class . '::hookHealthySmokeping'],
			['name' => 'broken', 'file' => 'setup.php', 'function' => self::class . '::hookThrowing'],
			['name' => 'evil', 'file' => 'setup.php', 'function' => self::class . '::hookHijackForeignSlug'],
			['name' => 'traversal', 'file' => '../evil.php', 'function' => self::class . '::hookHealthySmokeping'],
			['name' => 'cacti', 'file' => 'setup.php', 'function' => self::class . '::hookHealthySmokeping'],
		]);

		$this->assertTrue($registry->has('smokeping'));
		$this->assertFalse($registry->has('broken'));
		$this->assertFalse($registry->has('evil'));
		$this->assertFalse($registry->has('traversal'));
		$this->assertFalse($registry->has('cacti'));
		$this->assertCount(4, $registry->faults());

		// End-to-end export, plan, and apply through CascService.
		$repo = new InMemoryStateRepository();
		$repo->setPluginEnabled('smokeping', true);
		$service = new CascService($repo, new SecretResolver(), $registry);

		$exported = $service->exportDocument();
		$this->assertSame(300, $exported->spec['plugins']['installed'][0]['config']['freshnessAllowance']);
		$this->assertSame('/var/lib/smokeping', $exported->reference['plugins']['smokeping']['mount']);

		$yaml = CascCodec::write(CascCodec::YAML, $exported);
		$doc  = CascCodec::parse(CascCodec::YAML, $yaml);
		$plan = $service->plan($doc);

		$this->assertFalse($plan->hasErrors());
		$this->assertTrue($plan->hasChanges());

		$apply = $service->apply($doc, $plan->planHash, 'admin', 'cli');
		$this->assertTrue($apply->succeeded);
	}

	public function testRuntimeThrowingProviderDoesNotCrashCoreApply(): void {
		$registry = new ProviderRegistry();
		$registry->register(new PluginProviderDescriptor(
			'flaky',
			'1',
			static function (array $opts): array {
				throw new \RuntimeException('export failure');
			},
			static fn (array $cfg): array => [],
			static fn (array $cfg, array $opts): array => [['operation' => 'update', 'key' => 'k', 'message' => 'update k']],
			static function (array $cfg, string $hash, array $opts): array {
				throw new \RuntimeException('apply failure');
			}
		));

		$repo = new InMemoryStateRepository();
		$repo->setPluginEnabled('flaky', true);
		$service = new CascService($repo, new SecretResolver(), $registry);

		// Export must succeed despite flaky provider throwing.
		$exported = $service->exportDocument();
		$this->assertSame('flaky', $exported->spec['plugins']['installed'][0]['slug']);
		$this->assertNotEmpty($registry->faults());

		// Core settings apply must still succeed when flaky plugin apply throws.
		$yaml = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  settings:
    values:
      poller_interval: 120
  plugins:
    installed:
      - slug: flaky
        enabled: true
        config:
          enabled: true
YAML;

		$doc   = CascCodec::parse(CascCodec::YAML, $yaml);
		$plan  = $service->plan($doc);
		$apply = $service->apply($doc, $plan->planHash, 'admin', 'cli');

		$this->assertTrue($apply->succeeded);
		$this->assertSame('120', $repo->settings['poller_interval']);
	}

	public static function hookHealthySmokeping(ProviderRegistrar $registrar): void {
		$registrar->register(new PluginProviderDescriptor(
			'smokeping',
			'1',
			static fn (array $opts): array => ['config' => ['freshnessAllowance' => 300], 'reference' => ['mount' => '/var/lib/smokeping']],
			static fn (array $cfg): array => [],
			static fn (array $cfg, array $opts): array => [['operation' => 'update', 'key' => 'freshnessAllowance', 'message' => 'Reconcile freshnessAllowance']],
			static fn (array $cfg, string $hash, array $opts): array => ['ok' => true]
		));
	}

	public static function hookThrowing(ProviderRegistrar $registrar): void {
		throw new \RuntimeException('broken hook');
	}

	public static function hookHijackForeignSlug(ProviderRegistrar $registrar): void {
		$registrar->register(new PluginProviderDescriptor(
			'smokeping',
			'1',
			static fn (array $opts): array => [],
			static fn (array $cfg): array => [],
			static fn (array $cfg, array $opts): array => [],
			static fn (array $cfg, string $hash, array $opts): array => ['ok' => true]
		));
	}
}
