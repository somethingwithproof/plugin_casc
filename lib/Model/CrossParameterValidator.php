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

namespace Somethingwithproof\Casc\Model;

use Somethingwithproof\Casc\Provider\DevicesProvider;
use Somethingwithproof\Casc\Repository\StateRepository;

/**
 * Cross-parameter configuration invariant validator grounded in Yin et al.
 * (ACM SOSP 2011, "An Empirical Study on Configuration Errors in Commercial
 * and Open Source Systems").
 *
 * Enforces coupled parameter constraints, inter-resource reference integrity,
 * and natural-key uniqueness across manifest sections during both validate
 * and plan phases before any mutation is planned or applied.
 */
final class CrossParameterValidator {
	private const NONE_PROTOCOLS = [
		''       => true,
		'none'   => true,
		'[none]' => true,
	];

	/**
	 * Validate cross-parameter invariants and return flat diagnostic messages.
	 *
	 * @param CascDocument $document Parsed CasC manifest document.
	 * @param StateRepository|null $repo Optional live repository for cross-checking existing sites, pollers, and devices.
	 * @return list<string> List of invariant violation messages.
	 */
	public static function validate(CascDocument $document, ?StateRepository $repo = null): array {
		return array_map(
			static fn (array $item): string => $item['message'],
			self::validateDetailed($document, $repo)
		);
	}

	/**
	 * Validate cross-parameter invariants and return domain-scoped violations.
	 *
	 * @param CascDocument $document Parsed CasC manifest document.
	 * @param StateRepository|null $repo Optional live repository for cross-checking existing sites, pollers, and devices.
	 * @return list<array{domain: string, key: string, message: string}> Structured violations with domain, key, and message.
	 */
	public static function validateDetailed(CascDocument $document, ?StateRepository $repo = null): array {
		$violations = [];

		$manifestSites   = self::collectUniqueNamedKeys($document->spec['sites'] ?? null, 'sites', $violations);
		$manifestPollers = self::collectUniqueNamedKeys($document->spec['pollers'] ?? null, 'pollers', $violations);
		self::collectUniqueNamedKeys($document->spec['trees'] ?? null, 'trees', $violations);

		$devices = $document->spec['devices'] ?? null;

		if (!is_array($devices) || $devices === [] || !array_is_list($devices)) {
			return $violations;
		}

		$knownSites   = $manifestSites;
		$knownPollers = $manifestPollers;

		self::validateDevices($devices, $knownSites, $knownPollers, $repo, $violations);

		return $violations;
	}

	/**
	 * Collect unique natural names in a list domain and record duplicate violations.
	 *
	 * @param mixed $entries Candidate domain entry list.
	 * @param string $domain Domain name (sites, pollers, or trees).
	 * @param list<array{domain: string, key: string, message: string}> $violations Accumulator for violations.
	 * @return array<string, true> Set of non-empty trimmed names declared in the domain.
	 */
	private static function collectUniqueNamedKeys(mixed $entries, string $domain, array &$violations): array {
		$seen = [];

		if (!is_array($entries) || ($entries !== [] && !array_is_list($entries))) {
			return $seen;
		}

		$singular = rtrim($domain, 's');

		foreach ($entries as $entry) {
			if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
				continue;
			}

			$name = trim($entry['name']);

			if ($name === '') {
				continue;
			}

			if (isset($seen[$name])) {
				$violations[] = [
					'domain'  => $domain,
					'key'     => $name,
					'message' => sprintf('Duplicate %s natural key "%s"', $singular, $name),
				];

				continue;
			}

			$seen[$name] = true;
		}

		return $seen;
	}

	/**
	 * Validate device natural-key uniqueness, site/poller referential integrity,
	 * and SNMP version/protocol/credential coupling invariants.
	 *
	 * @param mixed $devices Candidate devices list from spec.devices.
	 * @param array<string, true> $knownSites Set of valid site names (loaded lazily from $repo when needed).
	 * @param array<string, true> $knownPollers Set of valid poller names (loaded lazily from $repo when needed).
	 * @param StateRepository|null $repo Optional live repository for lazy lookups.
	 * @param list<array{domain: string, key: string, message: string}> $violations Accumulator for violations.
	 * @return void
	 */
	private static function validateDevices(
		mixed $devices,
		array $knownSites,
		array $knownPollers,
		?StateRepository $repo,
		array &$violations,
	): void {
		if (!is_array($devices) || ($devices !== [] && !array_is_list($devices))) {
			return;
		}

		$sitesLoaded     = false;
		$pollersLoaded   = false;
		/** @var array<string, array<string, mixed>>|null $existingDevices */
		$existingDevices = null;
		$seenKeys        = [];
		$seenHostDescs   = [];

		foreach ($devices as $idx => $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$key      = DevicesProvider::naturalKeyFromEntry($entry);
			$label    = $key !== '' ? $key : sprintf('devices[%d]', (int) $idx);
			$hostname = is_string($entry['hostname'] ?? null) ? trim($entry['hostname']) : '';
			$desc     = is_string($entry['description'] ?? null) ? trim($entry['description']) : '';

			if ($key !== '') {
				if (isset($seenKeys[$key])) {
					$violations[] = [
						'domain'  => 'devices',
						'key'     => $key,
						'message' => sprintf('Duplicate device natural key "%s"', $key),
					];
				} else {
					$seenKeys[$key] = true;
				}
			}

			if ($hostname !== '' && $desc !== '') {
				$hostDesc = strtolower($hostname) . '::' . $desc;

				if (isset($seenHostDescs[$hostDesc])) {
					$violations[] = [
						'domain'  => 'devices',
						'key'     => $label,
						'message' => sprintf('Duplicate device hostname/description pair "%s::%s"', $hostname, $desc),
					];
				} else {
					$seenHostDescs[$hostDesc] = true;
				}
			}

			if (isset($entry['site']) && is_string($entry['site'])) {
				$siteName = trim($entry['site']);

				if ($siteName !== '' && !isset($knownSites[$siteName])) {
					if (!$sitesLoaded && $repo !== null) {
						$sitesLoaded = true;

						foreach ($repo->getSites() as $site) {
							$sName = is_scalar($site['name'] ?? null) ? trim((string) $site['name']) : '';

							if ($sName !== '') {
								$knownSites[$sName] = true;
							}
						}
					}

					if (!isset($knownSites[$siteName])) {
						$violations[] = [
							'domain'  => 'devices',
							'key'     => $label,
							'message' => sprintf('Device "%s" references unknown site "%s"', $label, $siteName),
						];
					}
				}
			}

			if (isset($entry['poller']) && is_string($entry['poller'])) {
				$pollerName = trim($entry['poller']);

				if ($pollerName !== '' && !isset($knownPollers[$pollerName])) {
					if (!$pollersLoaded && $repo !== null) {
						$pollersLoaded = true;

						foreach ($repo->getPollers() as $poller) {
							$pName = is_scalar($poller['name'] ?? null) ? trim((string) $poller['name']) : '';

							if ($pName !== '') {
								$knownPollers[$pName] = true;
							}
						}
					}

					if (!isset($knownPollers[$pollerName])) {
						$violations[] = [
							'domain'  => 'devices',
							'key'     => $label,
							'message' => sprintf('Device "%s" references unknown poller "%s"', $label, $pollerName),
						];
					}
				}
			}

			self::validateDeviceSnmp($entry, $key, $label, $repo, $existingDevices, $violations);
		}
	}

	/**
	 * Enforce SNMP version, protocol, and credential cross-parameter invariants for a device.
	 *
	 * @param array<array-key, mixed> $entry Device manifest entry.
	 * @param string $key Device natural key.
	 * @param string $label Device natural key or index label for diagnostics.
	 * @param StateRepository|null $repo Optional live repository for lazy existing-device lookup.
	 * @param array<string, array<string, mixed>>|null $existingDevices Lazily indexed live devices map.
	 * @param list<array{domain: string, key: string, message: string}> $violations Accumulator for violations.
	 * @return void
	 */
	private static function validateDeviceSnmp(
		array $entry,
		string $key,
		string $label,
		?StateRepository $repo,
		?array &$existingDevices,
		array &$violations,
	): void {
		$hasVersion = array_key_exists('snmpVersion', $entry);
		$rawVersion = $entry['snmpVersion'] ?? null;

		if ($hasVersion && !is_int($rawVersion) && !is_numeric($rawVersion)) {
			$violations[] = [
				'domain'  => 'devices',
				'key'     => $label,
				'message' => sprintf('Device "%s" snmpVersion must be 0, 1, 2, or 3', $label),
			];

			return;
		}

		$version = $hasVersion && is_numeric($rawVersion) ? (int) $rawVersion : null;

		if ($version !== null && ($version < 0 || $version > 3)) {
			$violations[] = [
				'domain'  => 'devices',
				'key'     => $label,
				'message' => sprintf('Device "%s" snmpVersion %d is invalid; expected 0, 1, 2, or 3', $label, $version),
			];

			return;
		}

		$community      = self::trimmedString($entry['snmpCommunity'] ?? null);
		$username       = self::trimmedString($entry['snmpUsername'] ?? null);
		$password       = self::trimmedString($entry['snmpPassword'] ?? null);
		$authProtocol   = self::trimmedString($entry['snmpAuthProtocol'] ?? null);
		$privProtocol   = self::trimmedString($entry['snmpPrivProtocol'] ?? null);
		$privPassphrase = self::trimmedString($entry['snmpPrivPassphrase'] ?? null);
		$context        = self::trimmedString($entry['snmpContext'] ?? null);
		$engineId       = self::trimmedString($entry['snmpEngineId'] ?? null);

		$hasActiveAuth = !isset(self::NONE_PROTOCOLS[strtolower($authProtocol)]);
		$hasActivePriv = !isset(self::NONE_PROTOCOLS[strtolower($privProtocol)]);

		if ($version === 3) {
			if ($username === '') {
				$violations[] = [
					'domain'  => 'devices',
					'key'     => $label,
					'message' => sprintf('Device "%s" with snmpVersion 3 requires non-empty snmpUsername', $label),
				];
			}

			if ($community !== '') {
				$violations[] = [
					'domain'  => 'devices',
					'key'     => $label,
					'message' => sprintf('Device "%s" with snmpVersion 3 must not set SNMPv1/v2 snmpCommunity', $label),
				];
			}

			if ($hasActiveAuth && $password === '') {
				$existing        = self::lookupExistingDevice($key, $repo, $existingDevices);
				$existingHasPass = $existing !== null && self::trimmedString($existing['snmp_password'] ?? null) !== '';

				if (array_key_exists('snmpPassword', $entry) || !$existingHasPass) {
					$violations[] = [
						'domain'  => 'devices',
						'key'     => $label,
						'message' => sprintf('Device "%s" sets snmpAuthProtocol "%s" and requires snmpPassword', $label, $authProtocol),
					];
				}
			}

			if ($hasActivePriv) {
				if (!$hasActiveAuth) {
					$violations[] = [
						'domain'  => 'devices',
						'key'     => $label,
						'message' => sprintf('Device "%s" sets snmpPrivProtocol "%s" without an active snmpAuthProtocol (SNMPv3 authPriv requires authentication)', $label, $privProtocol),
					];
				}

				if ($privPassphrase === '') {
					$existing        = self::lookupExistingDevice($key, $repo, $existingDevices);
					$existingHasPriv = $existing !== null && self::trimmedString($existing['snmp_priv_passphrase'] ?? null) !== '';

					if (array_key_exists('snmpPrivPassphrase', $entry) || !$existingHasPriv) {
						$violations[] = [
							'domain'  => 'devices',
							'key'     => $label,
							'message' => sprintf('Device "%s" sets snmpPrivProtocol "%s" and requires snmpPrivPassphrase', $label, $privProtocol),
						];
					}
				}
			} elseif ($privPassphrase !== '') {
				$violations[] = [
					'domain'  => 'devices',
					'key'     => $label,
					'message' => sprintf('Device "%s" sets snmpPrivPassphrase without an active snmpPrivProtocol', $label),
				];
			}

			return;
		}

		if ($version === 1 || $version === 2) {
			if ($community === '') {
				$existing             = self::lookupExistingDevice($key, $repo, $existingDevices);
				$existingHasCommunity = $existing !== null && self::trimmedString($existing['snmp_community'] ?? null) !== '';

				if (array_key_exists('snmpCommunity', $entry) || !$existingHasCommunity) {
					$violations[] = [
						'domain'  => 'devices',
						'key'     => $label,
						'message' => sprintf('Device "%s" with snmpVersion %d requires non-empty snmpCommunity', $label, $version),
					];
				}
			}

			if ($username !== '') {
				$violations[] = ['domain' => 'devices', 'key' => $label, 'message' => sprintf('Device "%s" with snmpVersion %d must not set SNMPv3-only field "snmpUsername"', $label, $version)];
			}

			if ($password !== '') {
				$violations[] = ['domain' => 'devices', 'key' => $label, 'message' => sprintf('Device "%s" with snmpVersion %d must not set SNMPv3-only field "snmpPassword"', $label, $version)];
			}

			if ($hasActiveAuth) {
				$violations[] = ['domain' => 'devices', 'key' => $label, 'message' => sprintf('Device "%s" with snmpVersion %d must not set SNMPv3-only field "snmpAuthProtocol"', $label, $version)];
			}

			if ($hasActivePriv) {
				$violations[] = ['domain' => 'devices', 'key' => $label, 'message' => sprintf('Device "%s" with snmpVersion %d must not set SNMPv3-only field "snmpPrivProtocol"', $label, $version)];
			}

			if ($privPassphrase !== '') {
				$violations[] = ['domain' => 'devices', 'key' => $label, 'message' => sprintf('Device "%s" with snmpVersion %d must not set SNMPv3-only field "snmpPrivPassphrase"', $label, $version)];
			}

			if ($context !== '') {
				$violations[] = ['domain' => 'devices', 'key' => $label, 'message' => sprintf('Device "%s" with snmpVersion %d must not set SNMPv3-only field "snmpContext"', $label, $version)];
			}

			if ($engineId !== '') {
				$violations[] = ['domain' => 'devices', 'key' => $label, 'message' => sprintf('Device "%s" with snmpVersion %d must not set SNMPv3-only field "snmpEngineId"', $label, $version)];
			}
		}
	}

	/**
	 * Lazily index live devices from $repo only when a partial device manifest omits a credential.
	 *
	 * @param string $key Device natural key.
	 * @param StateRepository|null $repo Optional live repository.
	 * @param array<string, array<string, mixed>>|null $existingDevices Lazy device index cache.
	 * @return array<string, mixed>|null Existing live device row or null.
	 */
	private static function lookupExistingDevice(string $key, ?StateRepository $repo, ?array &$existingDevices): ?array {
		if ($key === '' || $repo === null) {
			return null;
		}

		if ($existingDevices === null) {
			$existingDevices = [];

			foreach ($repo->getDevices() as $device) {
				$existingDevices[DevicesProvider::naturalKeyFromRow($device)] = $device;
			}
		}

		return $existingDevices[$key] ?? null;
	}

	/**
	 * Convert a scalar manifest value into a trimmed string.
	 *
	 * @param mixed $value Candidate field value.
	 * @return string Trimmed string value or empty string for non-scalars.
	 */
	private static function trimmedString(mixed $value): string {
		return is_scalar($value) ? trim((string) $value) : '';
	}
}
