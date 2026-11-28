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

namespace Somethingwithproof\Casc\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Casc\Codec\CascCodec;
use Somethingwithproof\Casc\Metrics\CascMetricsCollector;
use Somethingwithproof\Casc\Pages;
use Somethingwithproof\Casc\Schema;
use Somethingwithproof\Casc\Settings;

final class MariaDbIntegrationTest extends TestCase {
	public function testSchemaHooksRealmsAndEndToEndPlanApply(): void {
		$schema = new Schema(false);
		$this->assertTrue($schema->isCurrent());
		$this->assertTrue($schema->hooksRegistered());

		$realmCount = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?', ['casc']);
		$this->assertSame(count(Pages::realms()), $realmCount);

		$service = casc_service();

		// Export live DB state as YAML and XML and verify canonical round-trip.
		$yaml = $service->export(CascCodec::YAML);
		$xml  = $service->export(CascCodec::XML);
		$docY = CascCodec::parse(CascCodec::YAML, $yaml);
		$docX = CascCodec::parse(CascCodec::XML, $xml);
		$this->assertSame($docY->toCanonicalArray(), $docX->toCanonicalArray());

		// Apply a new site, declarative setting, and graph tree against live MariaDB.
		$manifest = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
metadata:
  name: ci-integration
spec:
  settings:
    allowlistMode: merge
    values:
      poller_interval: 300
  sites:
    - name: ci-site-1
      city: San Jose
      country: US
      timezone: UTC
  trees:
    - name: CI Managed Tree
      enabled: true
      sortType: natural
      items:
        - header: Edge
          children:
            - site: ci-site-1
YAML;

		$doc  = CascCodec::parse(CascCodec::YAML, $manifest);
		$plan = $service->plan($doc, [], 'ci', true);

		$this->assertFalse($plan->hasErrors());
		$this->assertTrue($plan->hasChanges());

		$apply = $service->apply($doc, $plan->planHash, 'ci', 'cli');
		$this->assertTrue($apply->succeeded, implode('; ', $apply->errors));
		$this->assertGreaterThan(0, $apply->auditId);

		// Confirm persisted rows in MariaDB.
		$siteCount = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM sites WHERE name = ?', ['ci-site-1']);
		$treeCount = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_tree WHERE name = ?', ['CI Managed Tree']);
		$this->assertSame(1, $siteCount);
		$this->assertSame(1, $treeCount);

		// Re-plan must now report zero changes.
		$planAfter = $service->plan($doc, [], 'ci', false);
		$this->assertFalse($planAfter->hasChanges());

		// Script-server telemetry collector reports live persisted plan/apply metrics.
		$collector = new CascMetricsCollector(
			static fn () => $service,
			static fn (): Settings => casc_settings()
		);
		$metrics = $collector->collect();
		$this->assertSame(0, $metrics['providers_registered']);
		$this->assertSame(0, $metrics['drift_items']);
		$this->assertGreaterThanOrEqual(3, (int) $metrics['last_plan_actions']);
		$this->assertIsInt($metrics['last_apply_ms']);
		$this->assertGreaterThanOrEqual(0, $metrics['last_apply_ms']);
		$this->assertSame(0, $metrics['validation_errors']);
		$this->assertSame(0, $metrics['secret_dirs_configured']);

		$formatted = CascMetricsCollector::format($metrics);
		$this->assertMatchesRegularExpression(
			'/^providers_registered:\d+ drift_items:\d+ last_plan_actions:\d+ last_apply_ms:\d+ validation_errors:\d+ secret_dirs_configured:\d+$/',
			$formatted
		);
	}
}
