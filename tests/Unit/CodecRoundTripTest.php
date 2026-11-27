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
use Somethingwithproof\Casc\Codec\YamlReader;
use Somethingwithproof\Casc\Codec\YamlWriter;
use Somethingwithproof\Casc\Model\CascDocument;

final class CodecRoundTripTest extends TestCase {
	public function testYamlToIrToXmlToIrToYamlCanonicalEquivalence(): void {
		$doc = new CascDocument(
			CascDocument::API_VERSION,
			CascDocument::KIND,
			[
				'name'        => 'prod-us-west',
				'cactiCompat' => '1.2.31',
			],
			[
				'settings' => [
					'allowlistMode' => 'merge',
					'values'        => [
						'poller_interval' => 300,
						'poller_type'     => 2,
						'snmp_password'   => 'env:CACTI_SNMP_PASSWORD',
						'log_pstats'      => 'on',
					],
				],
				'sites' => [
					[
						'name'      => 'sjc1',
						'city'      => 'San Jose',
						'country'   => 'US',
						'latitude'  => 37.3382,
						'longitude' => -121.8863,
						'zoom'      => 12,
						'notes'     => "Line 1\nLine 2 with 'quotes' and : colons",
					],
				],
				'pollers' => [
					[
						'name'      => 'Main Poller',
						'hostname'  => 'cacti-main.internal',
						'disabled'  => false,
						'processes' => 4,
						'threads'   => 8,
					],
				],
				'devices' => [
					[
						'key'            => 'edge-rtr-01.sjc1',
						'hostname'       => '198.51.100.10',
						'description'    => 'Edge Router 01 (SJC1)',
						'site'           => 'sjc1',
						'poller'         => 'Main Poller',
						'disabled'       => false,
						'snmpVersion'    => 3,
						'snmpUsername'   => 'cacti_ro',
						'snmpPassword'   => 'env:EDGE_AUTH',
						'dataQueries'    => ['SNMP - Interface Statistics'],
						'graphTemplates' => ['Device - Uptime'],
					],
				],
				'trees' => [
					[
						'name'     => 'Production Network',
						'enabled'  => true,
						'sortType' => 'natural',
						'items'    => [
							[
								'header'   => 'SJC1',
								'children' => [
									['device' => 'edge-rtr-01.sjc1'],
								],
							],
						],
					],
				],
				'plugins' => [
					'installed' => [
						[
							'slug'    => 'smokeping',
							'enabled' => true,
							'config'  => [
								'freshnessAllowance' => 300,
								'strictMode'         => true,
								'nullableOption'     => null,
							],
						],
					],
				],
			],
			[
				'paths' => [
					'path_rrdtool' => '/usr/bin/rrdtool',
					'path_spine'   => '/usr/local/spine/bin/spine',
				],
			]
		);

		$yaml1 = CascCodec::write(CascCodec::YAML, $doc);
		$ir1   = CascCodec::parse(CascCodec::YAML, $yaml1);
		$xml1  = CascCodec::write(CascCodec::XML, $ir1);
		$ir2   = CascCodec::parse(CascCodec::XML, $xml1);
		$yaml2 = CascCodec::write(CascCodec::YAML, $ir2);
		$xml2  = CascCodec::write(CascCodec::XML, $ir2);

		$this->assertSame($doc->toCanonicalArray(), $ir1->toCanonicalArray());
		$this->assertSame($ir1->toCanonicalArray(), $ir2->toCanonicalArray());
		$this->assertSame($yaml1, $yaml2);
		$this->assertSame($xml1, $xml2);
	}

	public function testJsonFormYamlFallbackDecodesToSameCanonicalIr(): void {
		$jsonYaml = json_encode([
			'apiVersion' => CascDocument::API_VERSION,
			'kind'       => CascDocument::KIND,
			'metadata'   => ['name' => 'json-manifest'],
			'spec'       => [
				'sites' => [
					['name' => 'nyc1', 'city' => 'New York', 'country' => 'US'],
				],
			],
		], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

		$docFromJson = CascCodec::parse(CascCodec::YAML, $jsonYaml);
		$blockYaml   = CascCodec::write(CascCodec::YAML, $docFromJson);
		$docFromYaml = CascCodec::parse(CascCodec::YAML, $blockYaml);

		$this->assertSame($docFromJson->toCanonicalArray(), $docFromYaml->toCanonicalArray());
	}

	public function testYamlWriterEscapesC1NelAndNonCharactersAndRoundTrips(): void {
		$valueWithControls = "hello\xC2\x85world\xC2\x9F\x7F\xE2\x80\xA8line\xE2\x80\xA9para\xEF\xBB\xBFbom\xEF\xBF\xBE\xEF\xBF\xBF";
		$yaml              = YamlWriter::write(['msg' => $valueWithControls]);

		$this->assertStringContainsString('\\u0085', $yaml);
		$this->assertStringContainsString('\\u009F', $yaml);
		$this->assertStringContainsString('\\u007F', $yaml);
		$this->assertStringContainsString('\\u2028', $yaml);
		$this->assertStringContainsString('\\u2029', $yaml);
		$this->assertStringContainsString('\\uFEFF', $yaml);
		$this->assertStringContainsString('\\uFFFE', $yaml);
		$this->assertStringContainsString('\\uFFFF', $yaml);

		$parsed = YamlReader::parse($yaml);
		$this->assertSame($valueWithControls, $parsed->data['msg']);
	}

	public function testLiteralBlocksAndAmbiguousQuotedStringsRoundTrip(): void {
		$yaml = <<<'YAML'
---
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
metadata:
  name: 'yes'
  note: |-
    first line
    second line
spec:
  sites:
    - name: '007'
      notes: |
        alpha
        beta
YAML;

		$doc = CascCodec::parse(CascCodec::YAML, $yaml);
		$this->assertSame('yes', $doc->metadata['name']);
		$this->assertSame("first line\nsecond line", $doc->metadata['note']);
		$this->assertSame('007', $doc->spec['sites'][0]['name']);
		$this->assertSame("alpha\nbeta\n", $doc->spec['sites'][0]['notes']);

		$xml       = CascCodec::write(CascCodec::XML, $doc);
		$reparsed  = CascCodec::parse(CascCodec::XML, $xml);
		$this->assertSame($doc->toCanonicalArray(), $reparsed->toCanonicalArray());
	}

	public function testXmlEnvelopeHasCanonicalRootAttributes(): void {
		$doc = new CascDocument(CascDocument::API_VERSION, CascDocument::KIND, ['name' => 'demo'], []);
		$xml = XmlCodec::write($doc->toCanonicalArray());

		$this->assertStringContainsString('<cacti-casc apiVersion="cacti.net/v1alpha1" kind="CactiConfig">', $xml);
	}
}
