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
use Somethingwithproof\Casc\Codec\XmlCodec;
use Somethingwithproof\Casc\Engine\CascService;
use Somethingwithproof\Casc\Extension\ProviderRegistrar;
use Somethingwithproof\Casc\Extension\ProviderRegistry;
use Somethingwithproof\Casc\Model\CascDocument;
use Somethingwithproof\Casc\Repository\CactiStateRepository;
use Somethingwithproof\Casc\Repository\InMemoryStateRepository;
use Somethingwithproof\Casc\Repository\ReadCachedStateRepository;
use Somethingwithproof\Casc\Secret\SecretResolver;

final class PerformanceAndCachingTest extends TestCase {
	private string $tempDir = '';

	protected function setUp(): void {
		$this->tempDir          = sys_get_temp_dir() . '/casc_perf_test_' . bin2hex(random_bytes(6));
		$GLOBALS['__casc_mock'] = [];
		mkdir($this->tempDir . '/templates', 0700, true);
	}

	protected function tearDown(): void {
		$GLOBALS['__casc_mock'] = [];

		if ($this->tempDir !== '' && is_dir($this->tempDir)) {
			foreach (glob($this->tempDir . '/templates/*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($this->tempDir . '/templates');
			@rmdir($this->tempDir);
		}
	}

	public function testCactiStateRepositoryUsesConstantBulkSqlQueriesForDevicesAndTrees(): void {
		$hosts = [];
		$dqs   = [];
		$gts   = [];

		for ($i = 1; $i <= 50; $i++) {
			$hosts[] = ['id' => $i, 'hostname' => '10.0.0.' . $i, 'description' => 'Host ' . $i, 'external_id' => 'ext-' . $i];
			$dqs[]   = ['host_id' => $i, 'name' => 'Interface Stats'];
			$gts[]   = ['host_id' => $i, 'name' => 'Uptime'];
		}

		$trees     = [];
		$treeItems = [];

		for ($t = 1; $t <= 10; $t++) {
			$trees[]     = ['id' => $t, 'name' => 'Tree ' . $t, 'enabled' => 'on', 'sort_type' => 1];
			$treeItems[] = ['graph_tree_id' => $t, 'id' => $t * 100, 'parent' => 0, 'position' => 1, 'title' => 'Root ' . $t, 'host_id' => 0, 'site_id' => 0, 'local_graph_id' => 0];
		}

		$queryCount = 0;

		$GLOBALS['__casc_mock']['db_fetch_assoc_prepared'] = static function (string $sql) use (&$queryCount, $hosts, $dqs, $gts, $trees, $treeItems): array {
			$queryCount++;

			if (str_contains($sql, 'FROM host WHERE deleted')) {
				return $hosts;
			}

			if (str_contains($sql, 'FROM host_snmp_query')) {
				return $dqs;
			}

			if (str_contains($sql, 'FROM host_graph')) {
				return $gts;
			}

			if (str_contains($sql, 'FROM graph_tree ORDER BY')) {
				return $trees;
			}

			if (str_contains($sql, 'FROM graph_tree_items')) {
				return $treeItems;
			}

			return [];
		};

		$repo = new CactiStateRepository('/nonexistent', false);

		$loadedDevices = $repo->getDevices();
		$this->assertCount(50, $loadedDevices);
		$this->assertSame(3, $queryCount, 'getDevices() must execute exactly 3 bulk SQL queries regardless of host count');
		$this->assertSame(['Interface Stats'], $loadedDevices[49]['data_queries']);
		$this->assertSame(['Uptime'], $loadedDevices[49]['graph_templates']);

		$queryCount  = 0;
		$loadedTrees = $repo->getTrees();
		$this->assertCount(10, $loadedTrees);
		$this->assertSame(2, $queryCount, 'getTrees() must execute exactly 2 bulk SQL queries regardless of tree count');
		$this->assertSame('Root 10', $loadedTrees[9]['items'][0]['header']);
	}

	public function testReadCachedStateRepositoryCachesReadsAndInvalidatesOnWrites(): void {
		$inner = new InMemoryStateRepository();
		$inner->settings = ['poller_interval' => '300'];
		$inner->saveSite(['name' => 's1', 'city' => 'Austin']);
		$inner->savePoller(['name' => 'p2', 'hostname' => 'p2.local']);
		$inner->saveDevice(['external_id' => 'd1', 'hostname' => '10.0.0.1', 'description' => 'D1']);
		$inner->saveTree(['name' => 't1', 'items' => []]);
		$inner->setPluginEnabled('doctor', true);
		$inner->templateExports['host_template:1'] = '<cacti/>';

		$cached = new ReadCachedStateRepository($inner);

		// Warm every read cache.
		$this->assertSame(['poller_interval' => '300'], $cached->getSettings());
		$this->assertCount(1, $cached->getSites());
		$this->assertCount(2, $cached->getPollers());
		$this->assertSame([], $cached->getHostTemplates());
		$this->assertSame([], $cached->getDataQueries());
		$this->assertSame([], $cached->getGraphTemplates());
		$this->assertCount(1, $cached->getDevices());
		$this->assertCount(1, $cached->getTrees());
		$this->assertCount(1, $cached->getPlugins());

		// Underlying direct mutation is hidden until cache invalidation.
		$inner->settings['poller_interval'] = '60';
		$this->assertSame('300', $cached->getSettings()['poller_interval']);

		// Mutations through proxy invalidate corresponding caches.
		$cached->setSetting('poller_interval', '120');
		$this->assertSame('120', $cached->getSettings()['poller_interval']);
		$cached->deleteSetting('poller_interval');
		$this->assertSame([], $cached->getSettings());

		$siteId = $cached->saveSite(['name' => 's2', 'city' => 'Boston']);
		$this->assertCount(2, $cached->getSites());
		$cached->deleteSite($siteId);
		$this->assertCount(1, $cached->getSites());

		$pollerId = $cached->savePoller(['name' => 'p3', 'hostname' => 'p3.local']);
		$this->assertCount(3, $cached->getPollers());
		$cached->deletePoller($pollerId);
		$this->assertCount(2, $cached->getPollers());

		$devId = $cached->saveDevice(['external_id' => 'd2', 'hostname' => '10.0.0.2', 'description' => 'D2']);
		$this->assertCount(2, $cached->getDevices());
		$cached->deleteDevice($devId);
		$this->assertCount(1, $cached->getDevices());

		$treeId = $cached->saveTree(['name' => 't2', 'items' => []]);
		$this->assertCount(2, $cached->getTrees());
		$cached->deleteTree($treeId);
		$this->assertCount(1, $cached->getTrees());

		$cached->setPluginEnabled('mcp', true);
		$this->assertCount(2, $cached->getPlugins());

		$this->assertTrue($cached->importTemplateXml('<cacti/>', 'System Default', false));
		$this->assertTrue($cached->importTemplatePackage('/tmp/a.xml.gz', 'System Default', false));
		$this->assertSame('<cacti/>', $cached->exportTemplateXml('host_template', 1));

		$cached->beginTransaction();
		$cached->triggerReplication('all');
		$cached->triggerPollerCacheUpdate(null);
		$cached->recordPlan(str_repeat('a', 64), 'admin', str_repeat('b', 64), 2, 1700000000);
		$this->assertNotNull($cached->getLatestPlan());
		$auditId = $cached->recordAudit(['actor' => 'admin', 'plan_hash' => str_repeat('a', 64)]);
		$this->assertGreaterThan(0, $auditId);
		$this->assertCount(1, $cached->getRecentAudits(5));
		$cached->commit();

		$cached->beginTransaction();
		$cached->rollback();
	}

	public function testSharedSecretResolutionAndTemplateInspectionAreDeduplicated(): void {
		$envLookups = 0;
		$secrets    = new SecretResolver([], static function (string $var) use (&$envLookups): string|false {
			$envLookups++;

			return $var === 'SHARED_SNMP' ? 'shared-secret-value' : false;
		});

		$repo    = new InMemoryStateRepository();
		$devices = [];

		for ($i = 1; $i <= 20; $i++) {
			$key = sprintf('dev-%02d', $i);
			$repo->saveDevice([
				'external_id'    => $key,
				'hostname'       => '10.0.0.' . $i,
				'description'    => 'Device ' . $i,
				'poller_id'      => 1,
				'site_id'        => 0,
				'snmp_version'   => 2,
				'snmp_community' => 'shared-secret-value',
			]);
			$devices[] = [
				'key'           => $key,
				'hostname'      => '10.0.0.' . $i,
				'description'   => 'Device ' . $i,
				'snmpVersion'   => 2,
				'snmpCommunity' => 'env:SHARED_SNMP',
			];
		}

		$compileCalls = 0;
		$yamlTpl      = "name: Linux\n";
		file_put_contents($this->tempDir . '/templates/linux.yaml', $yamlTpl);

		$compiler = static function (string $src, array $ctx) use (&$compileCalls): string {
			$compileCalls++;

			return '<cacti><hash_0201020123456789abcdef0123456789abcdef><name>Linux</name></hash_0201020123456789abcdef0123456789abcdef></cacti>';
		};

		$service = new CascService($repo, $secrets, new ProviderRegistry(), '1.2.31', [$compiler]);
		$doc     = new CascDocument(
			CascDocument::API_VERSION,
			CascDocument::KIND,
			['name' => 'dedup-test'],
			[
				'templates' => [['path' => 'templates/linux.yaml']],
				'devices'   => $devices,
			]
		);

		$plan = $service->plan($doc, ['baseDir' => $this->tempDir], 'test', false);
		$this->assertFalse($plan->hasErrors());
		$this->assertSame(1, $envLookups, 'Shared env: secret across 20 devices must resolve once per plan()');
		$this->assertSame(1, $compileCalls, 'TemplatesProvider::plan() must inspect/compile each template once, not twice');
	}

	public function testFiftySiblingPluginProvidersDispatchAndCacheSortedOrder(): void {
		$hooks = [];
		$slugs = [];

		for ($i = 50; $i >= 1; $i--) {
			$slug    = sprintf('plug_%02d', $i);
			$slugs[] = $slug;
			$hooks[] = ['name' => $slug, 'file' => 'setup.php', 'function' => self::class . '::registerBenchPlugin'];
		}

		$GLOBALS['__casc_test_slugs'] = $slugs;
		$GLOBALS['__casc_test_idx']   = 0;

		$registry = new ProviderRegistry();
		$registry->dispatchHooks($hooks);

		$all1 = $registry->all();
		$all2 = $registry->all();

		$this->assertCount(50, $all1);
		$this->assertSame('plug_01', $all1[0]->slug);
		$this->assertSame('plug_50', $all1[49]->slug);
		$this->assertSame($all1, $all2);
		$this->assertSame([], $registry->faults());
	}

	public function testXmlCodecRoundTripsControlEscapesAndSpecialCharacters(): void {
		$doc = new CascDocument(
			CascDocument::API_VERSION,
			CascDocument::KIND,
			[
				'name' => 'xml-edge & "quotes" <tag>',
			],
			[
				'sites' => [
					[
						'name'  => "site\t1",
						'notes' => "line1\r\nline2 & <xml> > 'single'",
					],
				],
			]
		);

		$xml    = CascCodec::write(CascCodec::XML, $doc);
		$parsed = CascCodec::parse(CascCodec::XML, $xml);
		$this->assertSame($doc->toCanonicalArray(), $parsed->toCanonicalArray());

		// Empty root sections emit self-closing root element that parses back cleanly.
		$emptyXml    = XmlCodec::write(['apiVersion' => CascDocument::API_VERSION, 'kind' => CascDocument::KIND]);
		$emptyParsed = XmlCodec::parse($emptyXml);
		$this->assertSame(CascDocument::API_VERSION, $emptyParsed->data['apiVersion']);
	}

	/**
	 * Hook callback helper for 50-provider dispatch testing.
	 *
	 * @param ProviderRegistrar $registrar Scoped plugin provider registrar.
	 * @return void
	 */
	public static function registerBenchPlugin(ProviderRegistrar $registrar): void {
		/** @var list<string> $slugs */
		$slugs = $GLOBALS['__casc_test_slugs'];
		/** @var int $idx */
		$idx  = $GLOBALS['__casc_test_idx'];
		$slug = $slugs[$idx];
		$GLOBALS['__casc_test_idx'] = $idx + 1;

		$registrar->register([
			'slug'          => $slug,
			'schemaVersion' => '1',
			'export'        => static fn (array $o): array => [],
			'validate'      => static fn (array $c): array => [],
			'plan'          => static fn (array $c, array $o): array => [],
			'apply'         => static fn (array $c, string $h, array $o): array => ['ok' => true],
		]);
	}
}
