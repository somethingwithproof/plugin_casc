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
use Somethingwithproof\Casc\Model\CrossParameterValidator;
use Somethingwithproof\Casc\Repository\InMemoryStateRepository;
use Somethingwithproof\Casc\Secret\SecretResolver;

final class CrossParameterValidatorTest extends TestCase {
	private function makeService(?InMemoryStateRepository $repo = null): CascService {
		$secrets = new SecretResolver([], static fn (string $k): string|false => match ($k) {
			'SNMP_COMM' => 'public_ro',
			'SNMP_AUTH' => 'authpass123',
			'SNMP_PRIV' => 'privpass123',
			default     => false,
		});

		return new CascService($repo ?? new InMemoryStateRepository(), $secrets);
	}

	public function testValidSnmpV2AndV3AuthPrivAndLiveReferencesPassValidateAndPlan(): void {
		$repo = new InMemoryStateRepository();
		$repo->saveSite(['name' => 'existing-site', 'city' => 'Denver']);

		$service = $this->makeService($repo);
		$yaml    = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  sites:
    - name: manifest-site
  pollers:
    - name: remote-poller
      hostname: poller2.internal
  devices:
    - key: sw-v2
      hostname: 10.0.0.2
      description: Access Switch V2
      site: existing-site
      poller: Main Poller
      snmpVersion: 2
      snmpCommunity: 'env:SNMP_COMM'
    - key: rtr-v3-authpriv
      hostname: 10.0.0.3
      description: Core Router V3
      site: manifest-site
      poller: remote-poller
      snmpVersion: 3
      snmpUsername: cacti_ro
      snmpAuthProtocol: SHA256
      snmpPassword: 'env:SNMP_AUTH'
      snmpPrivProtocol: AES256
      snmpPrivPassphrase: 'env:SNMP_PRIV'
    - key: rtr-v3-noauth
      hostname: 10.0.0.4
      description: Lab Router V3 NoAuth
      snmpVersion: 3
      snmpUsername: cacti_lab
      snmpAuthProtocol: NONE
      snmpPrivProtocol: NONE
YAML;

		$doc = CascCodec::parse(CascCodec::YAML, $yaml);

		$this->assertSame([], $service->validate($doc));
		$plan = $service->plan($doc);
		$this->assertFalse($plan->hasErrors(), $plan->formatText());
		$this->assertSame(5, $plan->counts()['create']);
	}

	public function testSnmpV3RequiresUsernameAndRejectsCommunity(): void {
		$service = $this->makeService();
		$yaml    = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  devices:
    - key: bad-v3
      hostname: 10.0.1.1
      description: Bad V3 Device
      snmpVersion: 3
      snmpCommunity: 'env:SNMP_COMM'
YAML;

		$doc    = CascCodec::parse(CascCodec::YAML, $yaml);
		$errors = $service->validate($doc);

		$this->assertNotEmpty($errors);
		$joined = implode("\n", $errors);
		$this->assertStringContainsString('snmpUsername', $joined);
		$this->assertStringContainsString('snmpCommunity', $joined);

		$plan = $service->plan($doc);
		$this->assertTrue($plan->hasErrors());
		$this->assertStringContainsString('snmpUsername', $plan->formatText());
	}

	public function testSnmpV3AuthAndPrivDependenciesAreEnforcedInValidateAndPlan(): void {
		$service = $this->makeService();

		// Auth protocol set without password.
		$yamlAuthNoPass = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  devices:
    - key: auth-no-pass
      hostname: 10.0.2.1
      description: Auth No Pass
      snmpVersion: 3
      snmpUsername: cacti_ro
      snmpAuthProtocol: SHA256
YAML;
		$docAuth = CascCodec::parse(CascCodec::YAML, $yamlAuthNoPass);
		$this->assertStringContainsString('requires snmpPassword', implode("\n", $service->validate($docAuth)));
		$this->assertTrue($service->plan($docAuth)->hasErrors());

		// Privacy protocol set without active auth protocol (invalid noAuthPriv) and without passphrase.
		$yamlPrivNoAuth = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  devices:
    - key: priv-no-auth
      hostname: 10.0.2.2
      description: Priv Without Auth
      snmpVersion: 3
      snmpUsername: cacti_ro
      snmpPrivProtocol: AES256
YAML;
		$docPriv = CascCodec::parse(CascCodec::YAML, $yamlPrivNoAuth);
		$privErr = implode("\n", $service->validate($docPriv));
		$this->assertStringContainsString('authPriv requires authentication', $privErr);
		$this->assertStringContainsString('requires snmpPrivPassphrase', $privErr);
		$this->assertTrue($service->plan($docPriv)->hasErrors());

		// Privacy passphrase set without active privacy protocol.
		$yamlOrphanPriv = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  devices:
    - key: orphan-priv
      hostname: 10.0.2.3
      description: Orphan Priv Passphrase
      snmpVersion: 3
      snmpUsername: cacti_ro
      snmpPrivPassphrase: 'env:SNMP_PRIV'
YAML;
		$docOrphan = CascCodec::parse(CascCodec::YAML, $yamlOrphanPriv);
		$this->assertStringContainsString('without an active snmpPrivProtocol', implode("\n", $service->validate($docOrphan)));
		$this->assertTrue($service->plan($docOrphan)->hasErrors());
	}

	public function testSnmpV1AndV2RequireCommunityAndRejectV3Fields(): void {
		$service = $this->makeService();
		$yaml    = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  devices:
    - key: v2-missing-comm
      hostname: 10.0.3.1
      description: V2 Missing Community
      snmpVersion: 2
    - key: v1-with-v3-fields
      hostname: 10.0.3.2
      description: V1 With V3 Fields
      snmpVersion: 1
      snmpCommunity: 'env:SNMP_COMM'
      snmpUsername: stray_user
      snmpAuthProtocol: SHA
    - key: bad-ver
      hostname: 10.0.3.3
      description: Bad Version
      snmpVersion: 4
YAML;

		$doc    = CascCodec::parse(CascCodec::YAML, $yaml);
		$errors = $service->validate($doc);
		$joined = implode("\n", $errors);

		$this->assertStringContainsString('requires non-empty snmpCommunity', $joined);
		$this->assertStringContainsString('must not set SNMPv3-only field "snmpUsername"', $joined);
		$this->assertStringContainsString('must not set SNMPv3-only field "snmpAuthProtocol"', $joined);
		$this->assertStringContainsString('snmpVersion 4 is invalid', $joined);

		$plan = $service->plan($doc);
		$this->assertTrue($plan->hasErrors());
	}

	public function testDeviceToSiteAndDeviceToPollerReferenceIntegrity(): void {
		$service = $this->makeService();
		$yaml    = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  devices:
    - key: d1
      hostname: 10.0.4.1
      description: Orphan Site Device
      site: ghost-site
      poller: ghost-poller
YAML;

		$doc    = CascCodec::parse(CascCodec::YAML, $yaml);
		$errors = $service->validate($doc);
		$joined = implode("\n", $errors);

		$this->assertStringContainsString('unknown site "ghost-site"', $joined);
		$this->assertStringContainsString('unknown poller "ghost-poller"', $joined);

		$plan = $service->plan($doc);
		$this->assertTrue($plan->hasErrors());
	}

	public function testDuplicateNaturalKeysAndDuplicateHostnameDescriptionPairsAreDetected(): void {
		$service = $this->makeService();
		$yaml    = <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  sites:
    - name: dup-site
    - name: dup-site
  pollers:
    - name: dup-poller
    - name: dup-poller
  trees:
    - name: dup-tree
    - name: dup-tree
  devices:
    - key: dev-a
      hostname: 10.0.5.1
      description: Same Host Desc
    - key: dev-b
      hostname: 10.0.5.1
      description: Same Host Desc
    - key: dev-a
      hostname: 10.0.5.2
      description: Another Desc
YAML;

		$doc    = CascCodec::parse(CascCodec::YAML, $yaml);
		$errors = CrossParameterValidator::validate($doc);
		$joined = implode("\n", $errors);

		$this->assertStringContainsString('Duplicate site natural key "dup-site"', $joined);
		$this->assertStringContainsString('Duplicate poller natural key "dup-poller"', $joined);
		$this->assertStringContainsString('Duplicate tree natural key "dup-tree"', $joined);
		$this->assertStringContainsString('Duplicate device natural key "dev-a"', $joined);
		$this->assertStringContainsString('Duplicate device hostname/description pair "10.0.5.1::Same Host Desc"', $joined);

		$this->assertNotEmpty($service->validate($doc));
		$this->assertTrue($service->plan($doc)->hasErrors());
	}
}
