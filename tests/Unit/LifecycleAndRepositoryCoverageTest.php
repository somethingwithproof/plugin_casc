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
use Somethingwithproof\Casc\Codec\XmlCodec;
use Somethingwithproof\Casc\Codec\YamlWriter;
use Somethingwithproof\Casc\Engine\CascService;
use Somethingwithproof\Casc\Engine\Plan;
use Somethingwithproof\Casc\Engine\PlanAction;
use Somethingwithproof\Casc\Extension\PluginProviderDescriptor;
use Somethingwithproof\Casc\Integration\McpAbilities;
use Somethingwithproof\Casc\Log;
use Somethingwithproof\Casc\Pages;
use Somethingwithproof\Casc\Repository\CactiStateRepository;
use Somethingwithproof\Casc\Repository\InMemoryStateRepository;
use Somethingwithproof\Casc\Schema;
use Somethingwithproof\Casc\Secret\SecretResolver;
use Somethingwithproof\Casc\Settings;
use Somethingwithproof\Casc\Ui\PagesRenderer;

final class LifecycleAndRepositoryCoverageTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['__casc_log']     = [];
		$GLOBALS['__casc_options'] = [];
		$GLOBALS['__casc_mock']    = [];
		$GLOBALS['menu']           = [];
		$GLOBALS['tabs']           = [];
		$GLOBALS['settings']       = [];
		$GLOBALS['current_user']   = ['enabled' => 'on'];
		$GLOBALS['user_auth_realm_filenames'] = [
			'casc_admin.php'    => 101,
			'casc_operator.php' => 102,
			'casc_view.php'     => 103,
		];
		$_SESSION = ['sess_user_id' => 1];
	}

	protected function tearDown(): void {
		$GLOBALS['__casc_mock']    = [];
		$GLOBALS['__casc_options'] = [];
		$_SESSION                  = [];
	}

	public function testSchemaMigrateUpgradeAndUninstallPaths(): void {
		$tablesCreated = [];
		$executedSql   = [];

		$GLOBALS['__casc_mock']['db_fetch_cell_prepared'] = static function (string $sql, array $params) use (&$tablesCreated): mixed {
			if (str_contains($sql, 'FROM settings WHERE name = ?')) {
				return '0';
			}

			if (str_contains($sql, 'information_schema.TABLES')) {
				return isset($tablesCreated[(string) ($params[0] ?? '')]) ? 1 : 0;
			}

			if (str_contains($sql, 'SELECT version FROM plugin_config')) {
				return '0.1.0';
			}

			if (str_contains($sql, 'SELECT status FROM plugin_config')) {
				return 1;
			}

			if (str_contains($sql, 'COUNT(DISTINCT hook)')) {
				return count(Schema::HOOKS);
			}

			if (str_contains($sql, 'FROM plugin_realms')) {
				return count(Pages::realms());
			}

			return 0;
		};

		$GLOBALS['__casc_mock']['api_plugin_db_table_create'] = static function (string $plugin, string $table, array $def) use (&$tablesCreated): bool {
			$tablesCreated[$table] = true;

			return true;
		};

		$GLOBALS['__casc_mock']['db_execute_prepared'] = static function (string $sql, array $params) use (&$executedSql): bool {
			$executedSql[] = $sql;

			return true;
		};

		$schema = new Schema(false);
		$this->assertFalse($schema->isCurrent());
		$this->assertTrue($schema->migrate());
		$this->assertTrue($schema->pluginEnabled());
		$this->assertTrue($schema->hooksRegistered());
		$this->assertSame('0.1.0', $schema->recordedPluginVersion());

		$schema->recordPluginVersion('0.1.0', 'Thomas Vincent', 'https://example.invalid');
		$schema->untrackTables();
		$schema->forgetVersion();

		$this->assertNotEmpty($executedSql);

		// Downgrade refusal when stored version > VERSION.
		$GLOBALS['__casc_mock']['db_fetch_cell_prepared'] = static fn (string $sql, array $params): mixed => 99;
		$this->assertFalse($schema->migrate());

		// Table creation failure path.
		$GLOBALS['__casc_mock']['db_fetch_cell_prepared'] = static fn (string $sql, array $params): mixed => 0;
		$this->assertFalse($schema->migrate());
	}

	public function testSetupHooksLifecycleAndRealms(): void {
		$hooksRegistered  = [];
		$realmsRegistered = [];

		$GLOBALS['__casc_mock']['db_fetch_cell_prepared'] = static function (string $sql, array $params): mixed {
			if (str_contains($sql, 'FROM settings WHERE name = ?')) {
				return '1';
			}

			if (str_contains($sql, 'SELECT version FROM plugin_config')) {
				return '0.1.0';
			}

			if (str_contains($sql, 'SELECT status FROM plugin_config')) {
				return 1;
			}

			if (str_contains($sql, 'COUNT(DISTINCT hook)')) {
				return count(Schema::HOOKS);
			}

			if (str_contains($sql, 'FROM plugin_realms')) {
				return count(Pages::realms());
			}

			return 1;
		};

		$GLOBALS['__casc_mock']['api_plugin_register_hook'] = static function (string $plugin, string $hook, string $fn) use (&$hooksRegistered): bool {
			$hooksRegistered[$hook] = $fn;

			return true;
		};

		$GLOBALS['__casc_mock']['api_plugin_register_realm'] = static function (string $plugin, string $file, string $name) use (&$realmsRegistered): bool {
			$realmsRegistered[] = $file;

			return true;
		};

		$this->assertTrue(casc_php_supported());
		$this->assertTrue(casc_boot());
		$this->assertFalse(casc_refuse_unsupported_php());

		$version = plugin_casc_version();
		$this->assertSame('casc', $version['name']);

		plugin_casc_install();
		$this->assertTrue(plugin_casc_check_config());
		$this->assertTrue(plugin_casc_upgrade());

		casc_setup_hooks();
		$this->assertSame(Schema::HOOKS, $hooksRegistered);
		$this->assertCount(3, $realmsRegistered);

		// Uninstall keep vs drop tables.
		$GLOBALS['__casc_options']['casc_uninstall_keep_tables'] = 'on';
		plugin_casc_uninstall();
		$GLOBALS['__casc_options']['casc_uninstall_keep_tables'] = '';
		plugin_casc_uninstall();

		// Menu, settings, nav, and 3-tier realm inheritance.
		$GLOBALS['__casc_mock']['get_current_page'] = static fn (): string => 'settings.php';
		casc_config_arrays();
		casc_config_settings();
		$this->assertArrayHasKey('casc', $GLOBALS['tabs']);
		$this->assertArrayHasKey('casc', $GLOBALS['settings']);
		$nav = casc_draw_navigation_text([]);
		$this->assertArrayHasKey('casc_admin.php:', $nav);

		// Admin inherits operator & view realms.
		$GLOBALS['__casc_mock']['get_current_page'] = static fn (): string => 'casc_view.php';
		$GLOBALS['__casc_mock']['is_realm_allowed'] = static fn (int $realm): bool => $realm === 101;
		$this->assertTrue(casc_page_allowed());
		$this->assertTrue(casc_page_start());

		// Operator inherits view realm.
		$GLOBALS['user_auth_realm_filenames']       = ['casc_admin.php' => 101, 'casc_operator.php' => 102, 'casc_view.php' => 103];
		$GLOBALS['__casc_mock']['is_realm_allowed'] = static fn (int $realm): bool => $realm === 102;
		$this->assertTrue(casc_page_allowed());

		// Unauthenticated session defers view/operator realm.
		$_SESSION = [];
		casc_auth_alternate_realms();
		$this->assertSame(-1, $GLOBALS['user_auth_realm_filenames']['casc_view.php']);

		// Disabled plugin renders fallback box.
		$GLOBALS['__casc_mock']['api_plugin_is_enabled'] = static fn (): bool => false;
		ob_start();
		$started = casc_page_start();
		$out     = ob_get_clean();
		$this->assertFalse($started);
		$this->assertStringContainsString('not enabled', (string) $out);

		// Setup hook dispatchers.
		$GLOBALS['__casc_mock']['api_plugin_is_enabled'] = static fn (): bool => true;
		$this->assertCount(3, casc_doctor_register_checks(['mode' => 'list', 'plugin' => 'casc']));
		$this->assertCount(1, casc_doctor_register_debug_info([]));
		$this->assertCount(4, casc_api_register_resources([]));
		$liveSchema = casc_describe_schema([]);
		$this->assertSame('https://json-schema.org/draft/2020-12/schema', $liveSchema['$schema']);

		// Abilities hook registration & userHasViewRealm.
		$registered = [];
		$registrar  = new class ($registered) {
			/** @param array<string, mixed> $registered */
			public function __construct(public array &$registered) {
			}

			/** @param array<string, mixed> $def */
			public function register(string $id, array $def): void {
				$this->registered[$id] = $def;
			}
		};
		casc_abilities_register($registrar);
		$this->assertArrayHasKey('casc-get-status', $registered);

		$GLOBALS['__casc_mock']['db_fetch_cell_prepared'] = static fn (): int => 5;
		$GLOBALS['__casc_mock']['is_realm_allowed']       = static fn (int $realm, int $uid = 0): bool => $realm === 105 && $uid === 7;
		$this->assertTrue(McpAbilities::userHasViewRealm(7));
		$this->assertFalse(McpAbilities::userHasViewRealm(0));

		// Extension hook fetch with mock DB rows.
		$GLOBALS['__casc_mock']['db_fetch_assoc_prepared'] = static fn (string $sql, array $params): array => [
			['name' => 'yaml', 'file' => 'setup.php', 'function' => PluginProviderExtensionTest::class . '::hookTemplateCompilerCallable'],
		];
		$this->assertCount(1, casc_template_compilers());
	}

	public function testCactiStateRepositoryCrudAndTreeAndReplicationPaths(): void {
		$sqlLog       = [];
		$replicated   = [];
		$cacheUpdated = [];
		$repopulated  = false;
		$pluginOps    = [];
		$deviceSaved  = false;
		$deviceDel    = 0;

		$GLOBALS['__casc_mock']['db_execute_prepared'] = static function (string $sql, array $params) use (&$sqlLog): bool {
			$sqlLog[] = $sql;

			return true;
		};
		$GLOBALS['__casc_mock']['db_fetch_insert_id'] = static fn (): int => 42;
		$GLOBALS['__casc_mock']['replicate_out']      = static function (int $id, string $class) use (&$replicated): bool {
			$replicated[] = [$id, $class];

			return true;
		};
		$GLOBALS['__casc_mock']['update_poller_cache'] = static function (int $id) use (&$cacheUpdated): bool {
			$cacheUpdated[] = $id;

			return true;
		};
		$GLOBALS['__casc_mock']['repopulate_poller_cache'] = static function () use (&$repopulated): bool {
			$repopulated = true;

			return true;
		};
		$GLOBALS['__casc_mock']['api_device_save'] = static function (...$args) use (&$deviceSaved): int {
			$deviceSaved = true;

			return 11;
		};
		$GLOBALS['__casc_mock']['api_device_remove'] = static function (int $id) use (&$deviceDel): bool {
			$deviceDel = $id;

			return true;
		};
		$GLOBALS['__casc_mock']['api_plugin_install'] = static function (string $slug) use (&$pluginOps): bool {
			$pluginOps[] = 'install:' . $slug;

			return true;
		};
		$GLOBALS['__casc_mock']['api_plugin_enable'] = static function (string $slug) use (&$pluginOps): bool {
			$pluginOps[] = 'enable:' . $slug;

			return true;
		};
		$GLOBALS['__casc_mock']['api_plugin_disable'] = static function (string $slug) use (&$pluginOps): bool {
			$pluginOps[] = 'disable:' . $slug;

			return true;
		};
		$GLOBALS['__casc_mock']['get_item_xml'] = static fn (string $type, int $id): string => '<cacti/>';

		$GLOBALS['__casc_mock']['db_fetch_assoc_prepared'] = static function (string $sql, array $params): array {
			if (str_contains($sql, 'FROM poller WHERE id > 1')) {
				return [['id' => 2]];
			}

			if (str_contains($sql, 'FROM data_local')) {
				return [['id' => 99]];
			}

			if (str_contains($sql, 'FROM settings')) {
				return [['name' => 'poller_interval', 'value' => '300']];
			}

			if (str_contains($sql, 'FROM sites')) {
				return [['id' => 1, 'name' => 'sjc1', 'city' => 'San Jose']];
			}

			if (str_contains($sql, 'FROM poller ORDER BY')) {
				return [['id' => 1, 'name' => 'Main Poller', 'hostname' => 'localhost']];
			}

			if (str_contains($sql, 'FROM host_template')) {
				return [['id' => 1, 'hash' => 'abc', 'name' => 'Linux', 'class' => '']];
			}

			if (str_contains($sql, 'FROM snmp_query')) {
				return [['id' => 2, 'hash' => 'def', 'name' => 'Interface Stats']];
			}

			if (str_contains($sql, 'FROM graph_templates')) {
				return [['id' => 3, 'hash' => 'ghi', 'name' => 'Uptime']];
			}

			if (str_contains($sql, 'FROM host WHERE deleted')) {
				return [['id' => 11, 'hostname' => '10.0.0.1', 'description' => 'Core']];
			}

			if (str_contains($sql, 'FROM host_snmp_query')) {
				return [['name' => 'Interface Stats']];
			}

			if (str_contains($sql, 'FROM host_graph')) {
				return [['name' => 'Uptime']];
			}

			if (str_contains($sql, 'FROM graph_tree ORDER BY')) {
				return [['id' => 7, 'name' => 'Default Tree', 'enabled' => 'on', 'sort_type' => 4]];
			}

			if (str_contains($sql, 'FROM graph_tree_items')) {
				return [
					['id' => 10, 'parent' => 0, 'position' => 1, 'title' => 'Edge', 'host_id' => 0, 'site_id' => 0, 'local_graph_id' => 0],
					['id' => 11, 'parent' => 10, 'position' => 1, 'title' => '', 'host_id' => 1, 'site_id' => 0, 'local_graph_id' => 0, 'host_external_id' => 'edge-1', 'host_hostname' => '10.0.0.1', 'host_description' => 'Edge'],
					['id' => 12, 'parent' => 10, 'position' => 2, 'title' => '', 'host_id' => 0, 'site_id' => 1, 'local_graph_id' => 0, 'site_name' => 'sjc1'],
					['id' => 13, 'parent' => 10, 'position' => 3, 'title' => '', 'host_id' => 0, 'site_id' => 0, 'local_graph_id' => 5],
				];
			}

			if (str_contains($sql, 'FROM plugin_config')) {
				return [['directory' => 'casc', 'name' => 'CasC', 'status' => 1, 'version' => '0.1.0']];
			}

			if (str_contains($sql, 'FROM plugin_casc_audit')) {
				return [['id' => 1, 'actor' => 'admin', 'status' => 'succeeded']];
			}

			return [];
		};

		$GLOBALS['__casc_mock']['db_fetch_cell_prepared'] = static function (string $sql, array $params): mixed {
			if (str_contains($sql, 'FROM data_source_profiles')) {
				return 1;
			}

			if (str_contains($sql, 'FROM host WHERE external_id')) {
				return ($params[0] ?? '') === 'edge-1' ? 11 : 0;
			}

			if (str_contains($sql, 'FROM host WHERE hostname')) {
				return 12;
			}

			if (str_contains($sql, 'FROM sites WHERE name')) {
				return 1;
			}

			return 0;
		};

		$repo = new CactiStateRepository('/nonexistent/lib', false);

		$repo->beginTransaction();
		$repo->commit();
		$repo->rollback();

		$repo->triggerReplication('all');
		$this->assertSame([[2, 'all']], $replicated);

		$repo->triggerPollerCacheUpdate(11);
		$repo->triggerPollerCacheUpdate(null);
		$this->assertSame([99], $cacheUpdated);
		$this->assertTrue($repopulated);

		$this->assertSame(['poller_interval' => '300'], $repo->getSettings());
		$repo->setSetting('poller_interval', '60');
		$repo->deleteSetting('poller_interval');

		$this->assertCount(1, $repo->getSites());
		$this->assertSame(42, $repo->saveSite(['name' => 'new-site']));
		$this->assertSame(5, $repo->saveSite(['id' => 5, 'name' => 'upd-site']));
		$repo->deleteSite(5);

		$this->assertCount(1, $repo->getPollers());
		$this->assertSame(42, $repo->savePoller(['name' => 'remote']));
		$this->assertSame(2, $repo->savePoller(['id' => 2, 'name' => 'remote']));
		$repo->deletePoller(2);

		$this->assertCount(1, $repo->getHostTemplates());
		$this->assertCount(1, $repo->getDataQueries());
		$this->assertCount(1, $repo->getGraphTemplates());
		$this->assertTrue($repo->importTemplateXml('<cacti/>', 'System Default', false));
		$this->assertTrue($repo->importTemplatePackage('/tmp/pkg.xml.gz', 'System Default', true));
		$this->assertSame('<cacti/>', $repo->exportTemplateXml('host_template', 1));

		$devices = $repo->getDevices();
		$this->assertCount(1, $devices);
		$this->assertSame(['Interface Stats'], $devices[0]['data_queries']);
		$this->assertSame(11, $repo->saveDevice([
			'hostname'        => '10.0.0.1',
			'description'     => 'Core',
			'data_queries'    => ['Interface Stats'],
			'graph_templates' => ['Uptime'],
		]));
		$this->assertTrue($deviceSaved);
		$repo->deleteDevice(11);
		$this->assertSame(11, $deviceDel);

		$trees = $repo->getTrees();
		$this->assertCount(1, $trees);
		$this->assertSame('Edge', $trees[0]['items'][0]['header']);
		$this->assertSame(42, $repo->saveTree([
			'name'  => 'New Tree',
			'items' => [
				[
					'header'   => 'Edge',
					'children' => [
						['device' => 'edge-1'],
						['device' => '10.0.0.2::Fallback'],
						['device' => 'missing-dev'],
						['site' => 'sjc1'],
					],
				],
			],
		]));
		$this->assertSame(7, $repo->saveTree(['id' => 7, 'name' => 'Upd Tree', 'items' => []]));
		$repo->deleteTree(7);

		$this->assertCount(1, $repo->getPlugins());
		$repo->setPluginEnabled('doctor', true);
		$repo->setPluginEnabled('doctor', false);
		$this->assertSame(['install:doctor', 'enable:doctor', 'disable:doctor'], $pluginOps);

		$this->assertSame(42, $repo->recordAudit(['actor' => 'admin', 'plan_hash' => str_repeat('a', 64)]));
		$this->assertCount(1, $repo->getRecentAudits(10));
		$repo->recordPlan(str_repeat('b', 64), 'admin', str_repeat('c', 64), 3, time());

		// Deleting primary poller id=1 must throw.
		$this->expectException(\RuntimeException::class);
		$repo->deletePoller(1);
	}

	public function testPagesRendererLogSettingsAndCodecEdgeCases(): void {
		Log::info('info msg');
		Log::warning('warn msg');
		Log::error('err msg');
		$this->assertCount(3, $GLOBALS['__casc_log']);

		$this->assertArrayHasKey('casc_secret_dirs', Settings::form());

		$provider = new PluginProviderDescriptor(
			'demo',
			'1',
			static fn (array $o): array => [],
			static fn (array $c): array => [],
			static fn (array $c, array $o): array => [],
			static fn (array $c, string $h, array $o): array => ['ok' => true]
		);

		$htmlEmpty = PagesRenderer::renderAuditAndProviders([], [], []);
		$this->assertStringContainsString('No CasC audit entries', $htmlEmpty);

		$htmlFull = PagesRenderer::renderAuditAndProviders(
			[['occurred_at' => 1700000000, 'actor' => 'admin', 'source' => 'cli', 'action' => 'apply', 'status' => 'succeeded', 'plan_hash' => str_repeat('a', 64), 'created_count' => 1, 'updated_count' => 2, 'deleted_count' => 0]],
			[$provider],
			[['slug' => 'bad', 'phase' => 'register', 'reason' => 'boom']]
		);
		$this->assertStringContainsString('demo', $htmlFull);
		$this->assertStringContainsString('Isolated Provider Faults', $htmlFull);

		$plan = new Plan(
			str_repeat('a', 64),
			str_repeat('b', 64),
			[new PlanAction('sites', PlanAction::CREATE, 's1', 'Create site s1', ['city' => ['from' => null, 'to' => 'NYC']])]
		);

		$preview = PagesRenderer::renderPlanPreview($plan, ['Minor error'], true);
		$this->assertStringContainsString('Apply Planned Changes', $preview);
		$this->assertStringContainsString('Minor error', $preview);
		$this->assertSame('', PagesRenderer::renderPlanPreview(null, [], false));

		// Stream reading and format detection.
		$stream = fopen('php://memory', 'r+');
		$this->assertIsResource($stream);
		fwrite($stream, "<cacti-casc/>");
		rewind($stream);
		$this->assertSame('<cacti-casc/>', CascCodec::readStream($stream));
		fclose($stream);

		$this->assertSame(CascCodec::XML, CascCodec::detectFormatFromContent('  <cacti-casc/>'));
		$this->assertSame(CascCodec::YAML, CascCodec::detectFormatFromContent('apiVersion: cacti.net/v1alpha1'));
		$this->assertSame(CascCodec::XML, CascCodec::detectFormatFromPath('manifest.XML'));

		// XmlCodec and YamlWriter error guards.
		$this->expectException(CodecException::class);
		YamlWriter::write([]);
	}

	public function testInMemoryUpdateAndPruneAcrossAllCoreProviders(): void {
		$repo = new InMemoryStateRepository();
		$repo->settings = [
			'poller_interval' => '300',
			'cron_interval'   => '300',
		];
		$repo->saveSite(['name' => 'sjc1', 'city' => 'Old City', 'latitude' => 10.0, 'longitude' => 20.0, 'zoom' => 5]);
		$repo->savePoller(['name' => 'remote1', 'hostname' => 'old.local', 'processes' => 1, 'threads' => 1, 'dbpass' => 'oldpass']);
		$repo->saveDevice([
			'external_id'          => 'edge-1',
			'hostname'             => '10.0.0.1',
			'description'          => 'Old Desc',
			'site_id'              => 1,
			'poller_id'            => 1,
			'host_template_id'     => 0,
			'snmp_version'         => 2,
			'snmp_community'       => 'oldcomm',
		]);
		$repo->saveDevice([
			'external_id' => 'old-dev',
			'hostname'    => '10.0.0.99',
			'description' => 'To Prune',
			'poller_id'   => 1,
			'site_id'     => 0,
		]);
		$repo->saveTree(['name' => 'Tree1', 'enabled' => 'on', 'sort_type' => 1, 'items' => [['header' => 'Old']]]);
		$repo->saveTree(['name' => 'OldTree', 'enabled' => 'on', 'sort_type' => 1, 'items' => []]);
		$repo->setPluginEnabled('doctor', true);

		$secrets = new SecretResolver([], static fn (string $k): string|false => 'newsecret');
		$service = new CascService($repo, $secrets);

		$yaml = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  settings:
    allowlistMode: strict
    values:
      poller_interval: 60
  sites:
    - name: sjc1
      city: New City
      latitude: 37.3
      longitude: -121.8
      zoom: 12
  pollers:
    - name: Main Poller
      hostname: localhost
    - name: remote1
      hostname: new.local
      processes: 4
      threads: 8
      disabled: true
      dbpass: 'env:NEW_PASS'
  devices:
    - key: edge-1
      hostname: 10.0.0.1
      description: New Desc
      site: sjc1
      poller: remote1
      disabled: true
      snmpVersion: 2
      snmpCommunity: 'env:NEW_COMM'
  trees:
    - name: Tree1
      enabled: false
      sortType: alpha
      items:
        - header: New
  plugins:
    installed:
      - slug: doctor
        enabled: false
YAML;

		$doc  = CascCodec::parse(CascCodec::YAML, $yaml);
		$opts = ['prune' => ['settings', 'devices', 'trees']];
		$plan = $service->plan($doc, $opts);
		$this->assertFalse($plan->hasErrors(), $plan->formatText());
		$this->assertGreaterThan(0, $plan->counts()['update']);
		$this->assertSame(3, $plan->counts()['delete']);

		$apply = $service->apply($doc, $plan->planHash, 'admin', 'cli', $opts);
		$this->assertTrue($apply->succeeded, implode('; ', $apply->errors));
		$this->assertArrayNotHasKey('cron_interval', $repo->settings);
		$this->assertCount(1, $repo->devices);
		$this->assertCount(1, $repo->trees);
		$this->assertSame(0, $repo->plugins['doctor']['status']);
		$this->assertSame(1, $apply->toArray()['auditId']);

		// Re-applying with clean plan hits noop path.
		$noopPlan  = $service->plan($doc, $opts);
		$noopApply = $service->apply($doc, $noopPlan->planHash, 'admin', 'cli', $opts);
		$this->assertTrue($noopApply->succeeded);
		$this->assertSame([], $noopApply->applied);
	}
}
