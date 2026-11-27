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
use Somethingwithproof\Casc\Engine\CascService;
use Somethingwithproof\Casc\Engine\PlanAction;
use Somethingwithproof\Casc\Engine\Planner;
use Somethingwithproof\Casc\Repository\InMemoryStateRepository;
use Somethingwithproof\Casc\Secret\SecretResolver;

final class EngineAndProvidersTest extends TestCase {
	public function testTopologicalOrderDeterministicPlanHashAndTransactionalApply(): void {
		$repo    = new InMemoryStateRepository();
		$secrets = new SecretResolver([], static fn (string $k): string|false => match ($k) {
			'SNMP_PASS'   => 's3cr3t-auth',
			'POLLER_PASS' => 'p0ll3r-db-pass',
			default       => false,
		});

		$service = new CascService($repo, $secrets);

		$yaml = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
metadata:
  name: prod-cluster
spec:
  settings:
    allowlistMode: merge
    values:
      poller_interval: 60
      snmp_password: 'env:SNMP_PASS'
  sites:
    - name: sjc1
      city: San Jose
      country: US
  pollers:
    - name: Main Poller
      hostname: main.internal
      processes: 4
      threads: 8
    - name: sjc1-remote
      hostname: remote.internal
      dbhost: remote.internal
      dbpass: 'env:POLLER_PASS'
  devices:
    - key: edge-01
      hostname: 198.51.100.10
      description: Edge Router 01
      site: sjc1
      poller: sjc1-remote
      snmpVersion: 3
      snmpUsername: cacti_ro
      snmpPassword: 'env:SNMP_PASS'
  trees:
    - name: Core Network
      enabled: true
      sortType: natural
      items:
        - header: SJC1
          children:
            - device: edge-01
  plugins:
    installed:
      - slug: doctor
        enabled: true
reference:
  paths:
    path_rrdtool: /usr/bin/rrdtool
YAML;

		$doc   = CascCodec::parse(CascCodec::YAML, $yaml);
		$plan1 = $service->plan($doc);
		$plan2 = $service->plan($doc);

		$this->assertFalse($plan1->hasErrors());
		$this->assertTrue($plan1->hasChanges());
		$this->assertSame($plan1->planHash, $plan2->planHash);

		// Verify topological domain ordering of mutation actions.
		$mutationDomains = [];

		foreach ($plan1->actions as $action) {
			if ($action->isMutation()) {
				$mutationDomains[] = $action->domain;
			}
		}

		$orderIndex = array_flip(Planner::TOPOLOGICAL_ORDER);
		$lastIdx    = -1;

		foreach ($mutationDomains as $dom) {
			$this->assertArrayHasKey($dom, $orderIndex);
			$this->assertGreaterThanOrEqual($lastIdx, $orderIndex[$dom], 'Out-of-order domain: ' . $dom);
			$lastIdx = $orderIndex[$dom];
		}

		// Verify secrets are never printed in plan text/JSON.
		$planText = $plan1->formatText();
		$this->assertStringNotContainsString('s3cr3t-auth', $planText);
		$this->assertStringNotContainsString('p0ll3r-db-pass', $planText);

		// Apply with confirmed plan hash.
		$result = $service->apply($doc, $plan1->planHash, 'admin', 'cli');

		$this->assertTrue($result->succeeded);
		$this->assertTrue($result->replicationTriggered);
		$this->assertTrue($result->pollerCacheUpdated);
		$this->assertGreaterThan(0, $result->auditId);
		$this->assertSame(['all'], $repo->replications);
		$this->assertSame([null], $repo->pollerCacheUpdates);
		$this->assertSame('s3cr3t-auth', $repo->settings['snmp_password']);
		$this->assertSame('p0ll3r-db-pass', $repo->pollers[2]['dbpass']);

		// Audit entry must not contain resolved plaintext secrets.
		$auditJson = (string) ($repo->audits[0]['summary_json'] ?? '');
		$this->assertStringNotContainsString('s3cr3t-auth', $auditJson);
		$this->assertStringNotContainsString('p0ll3r-db-pass', $auditJson);

		// Re-planning after apply must be a clean zero-change no-op (only reference warning).
		$planAfter = $service->plan($doc);
		$this->assertFalse($planAfter->hasChanges());

		// Exporting live state (omitSecrets) and re-planning must report zero drift across both YAML and XML.
		$exportedYaml = $service->export(CascCodec::YAML, ['omitSecrets' => true]);
		$exportedXml  = $service->export(CascCodec::XML, ['omitSecrets' => true]);
		$this->assertFalse($service->plan(CascCodec::parse(CascCodec::YAML, $exportedYaml))->hasChanges());
		$this->assertFalse($service->plan(CascCodec::parse(CascCodec::XML, $exportedXml))->hasChanges());
	}

	public function testApplyAbortsWhenLiveStateDriftsAfterPlan(): void {
		$repo    = new InMemoryStateRepository();
		$service = new CascService($repo, new SecretResolver());

		$yaml = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  sites:
    - name: fra1
      city: Frankfurt
YAML;

		$doc  = CascCodec::parse(CascCodec::YAML, $yaml);
		$plan = $service->plan($doc);

		// Simulate concurrent out-of-band DB change after planning.
		$repo->saveSite(['name' => 'fra1', 'city' => 'Berlin']);

		$result = $service->apply($doc, $plan->planHash, 'operator', 'cli');

		$this->assertFalse($result->succeeded);
		$this->assertStringContainsString('Plan hash mismatch', $result->errors[0]);
		$this->assertSame('Berlin', $repo->sites[1]['city']);
		$this->assertSame('drifted', $repo->audits[0]['status']);
	}

	public function testApplyRollsBackAtomicallyOnMidApplyFailure(): void {
		$repo    = new InMemoryStateRepository();
		$service = new CascService($repo, new SecretResolver());

		$yaml = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  settings:
    values:
      poller_interval: 60
  sites:
    - name: iad1
      city: Ashburn
YAML;

		$doc  = CascCodec::parse(CascCodec::YAML, $yaml);
		$plan = $service->plan($doc);

		$repo->failOnOperation = 'saveSite';
		$result                = $service->apply($doc, $plan->planHash, 'admin', 'cli');

		$this->assertFalse($result->succeeded);
		$this->assertArrayNotHasKey('poller_interval', $repo->settings, 'Transaction rollback must revert earlier settings write');
		$this->assertSame([], $repo->sites);
		$this->assertSame([], $repo->replications);
		$this->assertSame('failed', $repo->audits[0]['status']);
	}

	public function testAdditiveDefaultAndPruneOptInProtectsPrimaryPoller(): void {
		$repo = new InMemoryStateRepository();
		$repo->saveSite(['name' => 'keep-me', 'city' => 'Austin']);
		$repo->saveSite(['name' => 'prune-me', 'city' => 'Dallas']);
		$repo->savePoller(['name' => 'remote-old', 'hostname' => 'old.internal']);

		$service = new CascService($repo, new SecretResolver());

		$yaml = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  sites:
    - name: keep-me
      city: Austin
  pollers:
    - name: remote-new
      hostname: new.internal
YAML;

		$doc = CascCodec::parse(CascCodec::YAML, $yaml);

		// Default additive mode must not delete prune-me or remote-old.
		$planDefault = $service->plan($doc);
		$this->assertSame(0, $planDefault->counts()['delete']);

		// Explicit prune mode deletes prune-me & remote-old, but protects Main Poller (id=1).
		$planPrune = $service->plan($doc, ['prune' => ['sites', 'pollers']]);
		$this->assertSame(2, $planPrune->counts()['delete']);
		$this->assertSame(1, $planPrune->counts()['warning']);

		$apply = $service->apply($doc, $planPrune->planHash, 'admin', 'cli', ['prune' => ['sites', 'pollers']]);
		$this->assertTrue($apply->succeeded);
		$this->assertArrayHasKey(1, $repo->pollers, 'Primary poller id=1 must never be pruned');
		$this->assertCount(1, $repo->sites);
		$this->assertSame('keep-me', array_values($repo->sites)[0]['name']);
	}

	public function testSchemaRejectsAutoIncrementIdInManifest(): void {
		$yaml = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  sites:
    - id: 42
      name: bad-site
YAML;

		$this->expectException(CodecException::class);
		$this->expectExceptionMessage('Auto-increment "id" is forbidden');
		CascCodec::parse(CascCodec::YAML, $yaml);
	}

	public function testDuplicateSiteNamesInDatabaseFailPlan(): void {
		$repo = new InMemoryStateRepository();
		$repo->saveSite(['name' => 'dup', 'city' => 'A']);
		$repo->saveSite(['name' => 'dup', 'city' => 'B']);

		$service = new CascService($repo, new SecretResolver());
		$doc     = CascCodec::parse(CascCodec::YAML, "apiVersion: cacti.net/v1alpha1\nkind: CactiConfig\nspec:\n  sites:\n    - name: dup\n");

		$plan = $service->plan($doc);
		$this->assertTrue($plan->hasErrors());
		$this->assertSame(PlanAction::ERROR, $plan->actions[0]->operation);
	}
}
