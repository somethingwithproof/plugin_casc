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

namespace Somethingwithproof\Casc\Tests\Security;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Casc\Codec\CascCodec;
use Somethingwithproof\Casc\Codec\CodecException;
use Somethingwithproof\Casc\Codec\TemplateBridge;
use Somethingwithproof\Casc\Codec\XmlCodec;
use Somethingwithproof\Casc\Codec\YamlReader;
use Somethingwithproof\Casc\Engine\CascService;
use Somethingwithproof\Casc\Repository\InMemoryStateRepository;
use Somethingwithproof\Casc\Secret\SecretException;
use Somethingwithproof\Casc\Secret\SecretPolicy;
use Somethingwithproof\Casc\Secret\SecretResolver;

final class AdversarialCodecAndSecretTest extends TestCase {
	private string $tempDir = '';

	protected function setUp(): void {
		$base          = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
		$this->tempDir = rtrim($base, '/') . '/casc_sec_test_' . bin2hex(random_bytes(6));
		mkdir($this->tempDir, 0700, true);
	}

	protected function tearDown(): void {
		if ($this->tempDir !== '' && is_dir($this->tempDir)) {
			$this->removeTree($this->tempDir);
		}
	}

	public function testYamlRejectsDuplicateKeysInBlockAndJsonForms(): void {
		$block = "a: 1\na: 2\n";

		try {
			YamlReader::parse($block);
			$this->fail('Expected duplicate key exception in block YAML');
		} catch (CodecException $e) {
			$this->assertSame(2, $e->lineNumber);
			$this->assertStringContainsString('Duplicate key', $e->getMessage());
		}

		$json = '{"a": 1, "a": 2}';

		try {
			YamlReader::parse($json);
			$this->fail('Expected duplicate key exception in JSON-form YAML');
		} catch (CodecException $e) {
			$this->assertStringContainsString('Duplicate key', $e->getMessage());
		}
	}

	public function testYamlRejectsAnchorsAliasesTagsFlowMergeTabsAndAmbiguousScalars(): void {
		$cases = [
			'anchor'       => "a: &ref value\n",
			'alias'        => "a: *ref\n",
			'tag'          => "a: !!str hello\n",
			'flow_list'    => "a: [1, 2]\n",
			'flow_map'     => "a: {k: v}\n",
			'merge_key'    => "<<: value\n",
			'complex_key'  => "? key\n",
			'tab_indent'   => "a:\n\tb: 1\n",
			'yaml11_yes'   => "enabled: yes\n",
			'yaml11_off'   => "enabled: off\n",
			'octal'        => "mode: 0755\n",
			'sexagesimal'  => "time: 12:30\n",
			'unquoted_date'=> "date: 2026-11-27\n",
			'directive'    => "%YAML 1.2\na: 1\n",
			'multi_doc'    => "---\na: 1\n---\nb: 2\n",
			'c1_control'   => "a: raw\xC2\x85nel\n",
		];

		foreach ($cases as $label => $input) {
			$threw = false;

			try {
				YamlReader::parse($input);
			} catch (CodecException) {
				$threw = true;
			}

			$this->assertTrue($threw, 'Expected YamlReader to reject case: ' . $label);
		}
	}

	public function testYamlEnforcesByteAndDepthLimits(): void {
		$tooBig = str_repeat("a: 1\n", 100);

		$this->expectException(CodecException::class);
		YamlReader::parse($tooBig, 64);
	}

	public function testYamlEnforcesMaxDepth16(): void {
		$deep = '';

		for ($i = 0; $i < 17; $i++) {
			$deep .= str_repeat('  ', $i) . 'k' . $i . ":\n";
		}

		$deep .= str_repeat('  ', 17) . "leaf: 1\n";

		$this->expectException(CodecException::class);
		YamlReader::parse($deep, YamlReader::MAX_BYTES, 16);
	}

	public function testXmlRejectsDoctypeEntitiesAndXxePayloads(): void {
		$payloads = [
			'xxe_file' => '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><cacti-casc apiVersion="cacti.net/v1alpha1" kind="CactiConfig"><metadata><entry key="a" type="string">&xxe;</entry></metadata></cacti-casc>',
			'billion_laughs' => '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;">]><cacti-casc apiVersion="cacti.net/v1alpha1" kind="CactiConfig"/>',
			'entity_only' => '<!ENTITY x "y"><cacti-casc apiVersion="cacti.net/v1alpha1" kind="CactiConfig"/>',
			'wrong_root' => '<other apiVersion="cacti.net/v1alpha1"/>',
			'dup_entry' => '<cacti-casc apiVersion="cacti.net/v1alpha1" kind="CactiConfig"><metadata><entry key="a" type="string">1</entry><entry key="a" type="string">2</entry></metadata></cacti-casc>',
		];

		foreach ($payloads as $label => $xml) {
			$threw = false;

			try {
				XmlCodec::parse($xml);
			} catch (CodecException) {
				$threw = true;
			}

			$this->assertTrue($threw, 'Expected XmlCodec to reject payload: ' . $label);
		}
	}

	public function testExportNeverLeaksBlockedKeysOrDatabaseCredentials(): void {
		$repo = new InMemoryStateRepository();

		// Populate hard-blocked keys and all 4 settings password keys + DB credentials.
		$repo->settings = [
			'secret_encryption_key'   => 'SUPER_SECRET_ENC_KEY_999',
			'csrf_secret'             => 'SUPER_SECRET_CSRF_888',
			'rsa_private_key'         => 'SUPER_SECRET_RSA_PRIV_777',
			'rrdp_fingerprint'        => 'AA:BB:CC:DD:EE:FF',
			'snmp_password'           => 'PLAIN_SNMP_PASS_111',
			'snmp_priv_passphrase'    => 'PLAIN_SNMP_PRIV_222',
			'ldap_specific_password'  => 'PLAIN_LDAP_PASS_333',
			'settings_smtp_password'  => 'PLAIN_SMTP_PASS_444',
			'poller_interval'         => '300',
			'path_rrdtool'            => '/usr/bin/rrdtool',
		];

		$repo->pollers[2] = [
			'id'            => 2,
			'name'          => 'remote-1',
			'hostname'      => 'poller1.internal',
			'disabled'      => '',
			'dbpass'        => 'PLAIN_POLLER_DBPASS_555',
			'processes'     => 2,
			'threads'       => 4,
			'sync_interval' => 7200,
		];

		$repo->saveDevice([
			'external_id'          => 'core-sw-01',
			'hostname'             => '10.0.0.1',
			'description'          => 'Core Switch 01',
			'poller_id'            => 1,
			'site_id'              => 0,
			'host_template_id'     => 0,
			'snmp_version'         => 3,
			'snmp_community'       => 'PLAIN_COMMUNITY_666',
			'snmp_username'        => 'cacti_ro',
			'snmp_password'        => 'PLAIN_DEV_AUTH_777',
			'snmp_priv_passphrase' => 'PLAIN_DEV_PRIV_888',
		]);

		$service = new CascService($repo, new SecretResolver());
		$yaml    = $service->export(CascCodec::YAML);
		$xml     = $service->export(CascCodec::XML);

		$forbiddenSecrets = [
			'SUPER_SECRET_ENC_KEY_999',
			'SUPER_SECRET_CSRF_888',
			'SUPER_SECRET_RSA_PRIV_777',
			'AA:BB:CC:DD:EE:FF',
			'PLAIN_SNMP_PASS_111',
			'PLAIN_SNMP_PRIV_222',
			'PLAIN_LDAP_PASS_333',
			'PLAIN_SMTP_PASS_444',
			'PLAIN_POLLER_DBPASS_555',
			'PLAIN_COMMUNITY_666',
			'PLAIN_DEV_AUTH_777',
			'PLAIN_DEV_PRIV_888',
			'secret_encryption_key',
			'csrf_secret',
			'rsa_private_key',
		];

		foreach ($forbiddenSecrets as $secret) {
			$this->assertStringNotContainsString($secret, $yaml, 'YAML export leaked secret: ' . $secret);
			$this->assertStringNotContainsString($secret, $xml, 'XML export leaked secret: ' . $secret);
		}

		// Verify placeholder env: references were emitted instead and path_rrdtool is in reference:.
		$this->assertStringContainsString('env:CACTI_SECRET_SETTINGS_SNMP_PASSWORD', $yaml);
		$this->assertStringContainsString('env:CACTI_SECRET_POLLER_REMOTE_1_DBPASS', $yaml);
		$this->assertStringContainsString('path_rrdtool: /usr/bin/rrdtool', $yaml);
		$this->assertTrue(SecretPolicy::isSecretColumn('automation_snmp_items', 'snmp_password'));
		$this->assertTrue(SecretPolicy::isSecretColumn('snmpagent_managers', 'snmp_priv_passphrase'));
		$this->assertTrue(SecretPolicy::isSecretColumn('user_domains_ldap', 'specific_password'));
	}

	public function testSecretResolverConfinesFilesRejectsSymlinksAndEnforcesMode(): void {
		$allowedDir = $this->tempDir . '/secrets';
		$outsideDir = $this->tempDir . '/outside';
		mkdir($allowedDir, 0700, true);
		mkdir($outsideDir, 0700, true);

		$goodFile0400 = $allowedDir . '/db_pass_0400';
		file_put_contents($goodFile0400, "top-secret-400\n");
		chmod($goodFile0400, 0400);

		$goodFile0600 = $allowedDir . '/db_pass_0600';
		file_put_contents($goodFile0600, "top-secret-600\n");
		chmod($goodFile0600, 0600);

		$badModeFile = $allowedDir . '/world_readable';
		file_put_contents($badModeFile, "oops\n");
		chmod($badModeFile, 0644);

		$outsideFile = $outsideDir . '/other';
		file_put_contents($outsideFile, "outside-secret\n");
		chmod($outsideFile, 0600);

		$symlinkFile = $allowedDir . '/link_to_outside';
		symlink($outsideFile, $symlinkFile);

		$resolver = new SecretResolver(
			[$allowedDir],
			static fn (string $var): string|false => $var === 'TEST_SECRET_VAR' ? 'env-resolved-val' : false,
			static fn (string $plugin, string $slot): string => $plugin . ':' . $slot . ':vaulted'
		);

		$this->assertSame('env-resolved-val', $resolver->resolve('env:TEST_SECRET_VAR'));
		$this->assertSame('top-secret-400', $resolver->resolve('file:' . $goodFile0400));
		$this->assertSame('top-secret-600', $resolver->resolve('file:' . $goodFile0600));
		$this->assertSame('redfish:bmc1:vaulted', $resolver->resolve('vault:redfish:bmc1'));

		$rejected = [
			'bad_mode'     => 'file:' . $badModeFile,
			'symlink'      => 'file:' . $symlinkFile,
			'outside_dir'  => 'file:' . $outsideFile,
			'traversal'    => 'file:' . $allowedDir . '/../outside/other',
			'redacted'     => '<redacted>',
			'missing_env'  => 'env:DOES_NOT_EXIST_999',
			'invalid_env'  => 'env:123BAD',
		];

		foreach ($rejected as $label => $ref) {
			$threw = false;

			try {
				$resolver->resolve($ref);
			} catch (SecretException) {
				$threw = true;
			}

			$this->assertTrue($threw, 'Expected SecretResolver to reject: ' . $label);
		}
	}

	public function testTemplateBridgeRejectsTraversalSymlinkXxeAndShaMismatch(): void {
		$tplDir = $this->tempDir . '/templates';
		mkdir($tplDir, 0700, true);

		$goodXml = '<cacti><hash_020102abcdef0123456789abcdef0123456789><name>Linux</name></hash_020102abcdef0123456789abcdef0123456789></cacti>';
		file_put_contents($tplDir . '/good.xml', $goodXml);
		$goodSha = hash('sha256', $goodXml);

		$inspected = TemplateBridge::inspectTemplateFile('templates/good.xml', $this->tempDir, $goodSha);
		$this->assertSame($goodSha, $inspected['sha256']);
		$this->assertSame(['abcdef0123456789abcdef0123456789'], TemplateBridge::extractHashes((string) $inspected['xmlContent']));

		$xxeXml = '<!DOCTYPE foo [<!ENTITY x SYSTEM "file:///etc/passwd">]><cacti/>';
		file_put_contents($tplDir . '/xxe.xml', $xxeXml);

		$outside = $this->tempDir . '/outside_tpl.xml';
		file_put_contents($outside, $goodXml);
		symlink($outside, $tplDir . '/sym.xml');

		$badCases = [
			'traversal'    => ['../outside_tpl.xml', null],
			'absolute'     => ['/etc/passwd', null],
			'symlink'      => ['templates/sym.xml', null],
			'xxe'          => ['templates/xxe.xml', null],
			'sha_mismatch' => ['templates/good.xml', str_repeat('0', 64)],
		];

		foreach ($badCases as $label => [$path, $sha]) {
			$threw = false;

			try {
				TemplateBridge::inspectTemplateFile($path, $this->tempDir, $sha);
			} catch (CodecException) {
				$threw = true;
			}

			$this->assertTrue($threw, 'Expected TemplateBridge to reject: ' . $label);
		}
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
