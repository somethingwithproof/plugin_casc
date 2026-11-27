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
use Somethingwithproof\Casc\Codec\CodecException;
use Somethingwithproof\Casc\Codec\TemplateBridge;
use Somethingwithproof\Casc\Engine\CascService;
use Somethingwithproof\Casc\Extension\PluginProviderDescriptor;
use Somethingwithproof\Casc\Extension\ProviderRegistrar;
use Somethingwithproof\Casc\Extension\ProviderRegistry;
use Somethingwithproof\Casc\Repository\InMemoryStateRepository;
use Somethingwithproof\Casc\Secret\SecretResolver;

final class PluginProviderExtensionTest extends TestCase {
	private string $tempDir = '';

	protected function setUp(): void {
		$base          = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
		$this->tempDir = rtrim($base, '/') . '/casc_ext_test_' . bin2hex(random_bytes(6));
		mkdir($this->tempDir . '/templates', 0700, true);
	}

	protected function tearDown(): void {
		if ($this->tempDir !== '' && is_dir($this->tempDir)) {
			foreach (glob($this->tempDir . '/templates/*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($this->tempDir . '/templates');
			@rmdir($this->tempDir);
		}
	}

	public function testFaultIsolatedHookDispatchPreservesHealthyProviders(): void {
		$registry = new ProviderRegistry();

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

	public function testPlainArrayProviderRegistrationViaRegistrarAndReturnValue(): void {
		$registry = new ProviderRegistry();
		$registry->dispatchHooks([
			['name' => 'doctor', 'file' => 'setup.php', 'function' => self::class . '::hookPlainArrayViaRegistrar'],
			['name' => 'mcp', 'file' => 'setup.php', 'function' => self::class . '::hookPlainArrayByReturn'],
			['name' => 'badarray', 'file' => 'setup.php', 'function' => self::class . '::hookInvalidPlainArray'],
			['name' => 'hijackreturn', 'file' => 'setup.php', 'function' => self::class . '::hookForeignSlugReturn'],
		]);

		$this->assertTrue($registry->has('doctor'));
		$this->assertTrue($registry->has('mcp'));
		$this->assertFalse($registry->has('badarray'));
		$this->assertFalse($registry->has('hijackreturn'));
		$this->assertCount(2, $registry->faults());

		$this->assertSame('1', $registry->get('doctor')?->schemaVersion);
		$this->assertSame('2', $registry->get('mcp')?->schemaVersion);
	}

	public function testJsonSchemaDraft202012AcrossCoreAndPluginProviders(): void {
		$registry = new ProviderRegistry();
		$registry->dispatchHooks([
			['name' => 'doctor', 'file' => 'setup.php', 'function' => self::class . '::hookPlainArrayViaRegistrar'],
			['name' => 'mcp', 'file' => 'setup.php', 'function' => self::class . '::hookPlainArrayByReturn'],
			['name' => 'flakyschema', 'file' => 'setup.php', 'function' => self::class . '::hookThrowingSchemaProvider'],
		]);

		$service = new CascService(new InMemoryStateRepository(), new SecretResolver(), $registry);
		$schema  = $service->describeSchema();

		$this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
		$this->assertSame('object', $schema['type']);
		$this->assertSame(['apiVersion', 'kind'], $schema['required']);

		foreach (['settings', 'sites', 'pollers', 'templates', 'devices', 'trees', 'plugins'] as $domain) {
			$this->assertArrayHasKey($domain, $schema['properties']['spec']['properties']);
		}

		$pluginsSchema = $schema['properties']['spec']['properties']['plugins'];
		$this->assertArrayHasKey('x-plugin-providers', $pluginsSchema);
		$this->assertArrayHasKey('doctor', $pluginsSchema['x-plugin-providers']);
		$this->assertArrayHasKey('mcp', $pluginsSchema['x-plugin-providers']);
		$this->assertSame('integer', $pluginsSchema['x-plugin-providers']['doctor']['schema']['properties']['timeout']['type']);
		$this->assertSame('boolean', $pluginsSchema['x-plugin-providers']['mcp']['schema']['properties']['readOnly']['type']);

		// Faulty describeSchema callback was isolated without crashing schema generation.
		$this->assertArrayNotHasKey('schema', $pluginsSchema['x-plugin-providers']['flakyschema']);
		$this->assertNotEmpty($registry->faults());
	}

	public function testYamlTemplateCompilationFaultIsolationAndInvalidXmlRejection(): void {
		$yamlTpl = "template:\n  name: Linux YAML\n";
		file_put_contents($this->tempDir . '/templates/linux.yaml', $yamlTpl);
		file_put_contents($this->tempDir . '/templates/switch.YML', $yamlTpl);

		$validXml = '<cacti><hash_0201020123456789abcdef0123456789abcdef><name>Linux YAML</name></hash_0201020123456789abcdef0123456789abcdef></cacti>';

		// 1. Missing compiler returns clear error referencing plugin_yaml / casc_register_template_compilers.
		$serviceNoCompiler = new CascService(new InMemoryStateRepository(), new SecretResolver());
		$manifest          = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  templates:
    - path: templates/linux.yaml
YAML;
		$doc        = CascCodec::parse(CascCodec::YAML, $manifest);
		$valErrors  = $serviceNoCompiler->validate($doc, ['baseDir' => $this->tempDir]);
		$this->assertCount(1, $valErrors);
		$this->assertStringContainsString('plugin_yaml', $valErrors[0]);
		$this->assertStringContainsString('casc_register_template_compilers', $valErrors[0]);

		// Also fails validation cleanly when baseDir is omitted.
		$valNoBase = $serviceNoCompiler->validate($doc);
		$this->assertCount(1, $valNoBase);
		$this->assertStringContainsString('plugin_yaml', $valNoBase[0]);

		$planNoCompiler = $serviceNoCompiler->plan($doc, ['baseDir' => $this->tempDir]);
		$this->assertTrue($planNoCompiler->hasErrors());

		// 2. Faulty first compiler is isolated and second healthy compiler succeeds end-to-end (validate, plan, apply).
		$faultyCompiler = static function (string $yaml, array $ctx): string {
			throw new \RuntimeException('compiler crashed');
		};
		$healthyCompiler = static fn (string $yaml, array $ctx): string => $validXml;

		$repo            = new InMemoryStateRepository();
		$serviceCompiled = new CascService($repo, new SecretResolver(), new ProviderRegistry(), '1.2.31', [$faultyCompiler, $healthyCompiler]);

		$manifestBoth = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  templates:
    - path: templates/linux.yaml
    - path: templates/switch.YML
YAML;
		$docBoth = CascCodec::parse(CascCodec::YAML, $manifestBoth);

		$this->assertSame([], $serviceCompiled->validate($docBoth, ['baseDir' => $this->tempDir]));
		$plan = $serviceCompiled->plan($docBoth, ['baseDir' => $this->tempDir]);
		$this->assertFalse($plan->hasErrors());
		$this->assertSame(2, $plan->counts()['create']);

		$apply = $serviceCompiled->apply($docBoth, $plan->planHash, 'admin', 'cli', ['baseDir' => $this->tempDir]);
		$this->assertTrue($apply->succeeded);
		$this->assertCount(2, $repo->importedTemplates);
		$this->assertSame($validXml, $repo->importedTemplates[0]['payload']);

		// 3. Invalid compiled XML (XXE DOCTYPE, wrong root, malformed XML) is strictly rejected.
		$badOutputs = [
			'xxe'       => '<!DOCTYPE foo [<!ENTITY x SYSTEM "file:///etc/passwd">]><cacti/>',
			'wrongRoot' => '<notcacti><item/></notcacti>',
			'malformed' => '<cacti><unclosed></cacti>',
			'empty'     => '',
		];

		foreach ($badOutputs as $label => $badXml) {
			$badCompiler = static fn (string $yaml, array $ctx): string => $badXml;
			$threw       = false;

			try {
				TemplateBridge::inspectTemplateFile('templates/linux.yaml', $this->tempDir, null, [$badCompiler]);
			} catch (CodecException) {
				$threw = true;
			}

			$this->assertTrue($threw, 'Expected TemplateBridge to reject compiled output: ' . $label);
		}
	}

	public function testTemplateCompilerHookDiscoveryIsolatesFaults(): void {
		$compilers = TemplateBridge::dispatchCompilerHooks([
			['name' => 'yaml', 'file' => 'setup.php', 'function' => self::class . '::hookTemplateCompilerCallable'],
			['name' => 'yaml_desc', 'file' => 'setup.php', 'function' => self::class . '::hookTemplateCompilerDescriptor'],
			['name' => 'broken', 'file' => 'setup.php', 'function' => self::class . '::hookTemplateCompilerThrow'],
			['name' => 'bad_path', 'file' => '../etc/passwd', 'function' => self::class . '::hookTemplateCompilerCallable'],
			['name' => 'BAD-SLUG', 'file' => 'setup.php', 'function' => self::class . '::hookTemplateCompilerCallable'],
		]);

		$this->assertCount(2, $compilers);
		$this->assertSame('<cacti/>', $compilers[0]('x: 1', []));
		$this->assertSame('<cacti/>', $compilers[1]('x: 1', []));
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

	public static function hookPlainArrayViaRegistrar(ProviderRegistrar $registrar): void {
		$registrar->register([
			'slug'          => 'doctor',
			'schemaVersion' => '1',
			'export'        => static fn (array $opts): array => ['config' => ['timeout' => 30]],
			'validate'      => static fn (array $cfg): array => [],
			'plan'          => static fn (array $cfg, array $opts): array => [],
			'apply'         => static fn (array $cfg, string $hash, array $opts): array => ['ok' => true],
			'schema'        => [
				'type'       => 'object',
				'properties' => [
					'timeout' => ['type' => 'integer', 'minimum' => 1],
				],
			],
		]);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function hookPlainArrayByReturn(ProviderRegistrar $registrar): array {
		return [
			'slug'           => 'mcp',
			'schemaVersion'  => '2',
			'export'         => static fn (array $opts): array => ['config' => ['readOnly' => true]],
			'validate'       => static fn (array $cfg): array => [],
			'plan'           => static fn (array $cfg, array $opts): array => [],
			'apply'          => static fn (array $cfg, string $hash, array $opts): array => ['ok' => true],
			'describeSchema' => static fn (): array => [
				'type'       => 'object',
				'properties' => [
					'readOnly' => ['type' => 'boolean'],
				],
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function hookInvalidPlainArray(ProviderRegistrar $registrar): array {
		return [
			'slug'          => 'badarray',
			'schemaVersion' => '1',
			'export'        => 'not_callable',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function hookForeignSlugReturn(ProviderRegistrar $registrar): array {
		return [
			'slug'          => 'doctor',
			'schemaVersion' => '1',
			'export'        => static fn (array $opts): array => [],
			'validate'      => static fn (array $cfg): array => [],
			'plan'          => static fn (array $cfg, array $opts): array => [],
			'apply'         => static fn (array $cfg, string $hash, array $opts): array => ['ok' => true],
		];
	}

	public static function hookThrowingSchemaProvider(ProviderRegistrar $registrar): void {
		$registrar->register([
			'slug'           => 'flakyschema',
			'schemaVersion'  => '1',
			'export'         => static fn (array $opts): array => [],
			'validate'       => static fn (array $cfg): array => [],
			'plan'           => static fn (array $cfg, array $opts): array => [],
			'apply'          => static fn (array $cfg, string $hash, array $opts): array => ['ok' => true],
			'describeSchema' => static function (): array {
				throw new \RuntimeException('schema callback exploded');
			},
		]);
	}

	/**
	 * @param array<mixed> $compilers
	 * @return callable(string, array<string, mixed>): string
	 */
	public static function hookTemplateCompilerCallable(array $compilers): callable {
		return static fn (string $yaml, array $ctx): string => '<cacti/>';
	}

	/**
	 * @param array<mixed> $compilers
	 * @return array{compile: callable(string, array<string, mixed>): string}
	 */
	public static function hookTemplateCompilerDescriptor(array $compilers): array {
		return ['compile' => static fn (string $yaml, array $ctx): string => '<cacti/>'];
	}

	/**
	 * @param array<mixed> $compilers
	 * @return list<mixed>
	 */
	public static function hookTemplateCompilerThrow(array $compilers): array {
		throw new \RuntimeException('compiler hook failed');
	}
}
