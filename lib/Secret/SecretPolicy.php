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

namespace Somethingwithproof\Casc\Secret;

/**
 * Central security policy governing hard-blocked installation keys, redacted
 * credential columns, and the declarative settings allowlist.
 */
final class SecretPolicy {
	public const REDACTED_MARKER = '<redacted>';

	/**
	 * Installation-local cryptographic keys that must never be exported or
	 * managed via CasC manifests.
	 */
	public const BLOCKED_SETTINGS = [
		'secret_encryption_key',
		'csrf_secret',
		'rsa_private_key',
		'rsa_public_key',
		'rsa_fingerprint',
		'rrdp_fingerprint',
		'rrdp_fingerprint_backup',
	];

	/**
	 * The four Cacti 1.2 settings password keys (textbox_password in
	 * include/global_settings.php) that are always redacted on export and
	 * resolved via SecretResolver on apply.
	 */
	public const SECRET_SETTINGS = [
		'snmp_password',
		'snmp_priv_passphrase',
		'ldap_specific_password',
		'settings_smtp_password',
	];

	/**
	 * Credential columns across Cacti 1.2 tables that must never be exported in
	 * plaintext.
	 */
	public const SECRET_COLUMNS = [
		'host' => [
			'snmp_community',
			'snmp_password',
			'snmp_priv_passphrase',
		],
		'poller' => [
			'dbpass',
		],
		'automation_snmp_items' => [
			'snmp_community',
			'snmp_password',
			'snmp_priv_passphrase',
		],
		'snmpagent_managers' => [
			'snmp_community',
			'snmp_password',
			'snmp_priv_passphrase',
		],
		'user_domains_ldap' => [
			'specific_password',
		],
	];

	/**
	 * Explicit allowlist of declarative Cacti 1.2 settings managed in
	 * spec.settings.values. Runtime counters (stats_*, poller_lastrun,
	 * install_*, *_version) and local binary paths (path_*) are excluded.
	 */
	public const DECLARATIVE_SETTINGS = [
		'poller_enabled',
		'poller_type',
		'poller_interval',
		'cron_interval',
		'concurrent_processes',
		'max_threads',
		'php_servers',
		'script_timeout',
		'max_get_size',
		'snmp_ver',
		'snmp_community',
		'snmp_username',
		'snmp_password',
		'snmp_auth_protocol',
		'snmp_priv_passphrase',
		'snmp_priv_protocol',
		'snmp_context',
		'snmp_engine_id',
		'snmp_port',
		'snmp_timeout',
		'snmp_retries',
		'availability_method',
		'ping_method',
		'ping_port',
		'ping_timeout',
		'ping_retries',
		'ping_failure_count',
		'ping_recovery_count',
		'log_verbosity',
		'log_destination',
		'log_pstats',
		'log_pwarn',
		'log_perror',
		'log_validation',
		'rrdtool_version',
		'default_image_format',
		'default_rra_id',
		'auth_method',
		'auth_cache_enabled',
		'guest_user',
		'user_template',
		'ldap_server',
		'ldap_port',
		'ldap_port_ssl',
		'ldap_version',
		'ldap_encryption',
		'ldap_dn',
		'ldap_mode',
		'ldap_specific_dn',
		'ldap_specific_password',
		'settings_how',
		'settings_from_email',
		'settings_from_name',
		'settings_smtp_host',
		'settings_smtp_port',
		'settings_smtp_username',
		'settings_smtp_password',
		'settings_smtp_secure',
		'settings_smtp_timeout',
		'selective_device_debug',
		'boost_rrd_update_enable',
		'boost_rrd_update_interval',
		'boost_rrd_update_max_records',
		'boost_rrd_update_max_records_per_select',
		'boost_rrd_update_string_length',
		'boost_poller_mem_limit',
		'boost_png_cache_enable',
		'storage_location',
		'rrdp_server',
		'rrdp_port',
		'rrdp_load_balancing',
		'rrdp_server_backup',
		'rrdp_port_backup',
		'automation_graphs_enabled',
		'automation_tree_enabled',
		'automation_email',
	];

	/**
	 * Installation-local path settings exported under reference.paths for
	 * visibility and drift reporting, never applied blindly across hosts.
	 */
	public const REFERENCE_PATH_SETTINGS = [
		'path_rrdtool',
		'path_php_binary',
		'path_snmpwalk',
		'path_snmpget',
		'path_snmpbulkwalk',
		'path_snmpgetnext',
		'path_snmptrap',
		'path_cactilog',
		'path_stderrlog',
		'path_spine',
		'path_spine_config',
	];

	/** @var array<string, true>|null */
	private static ?array $blockedSet = null;

	/** @var array<string, true>|null */
	private static ?array $secretSet = null;

	/** @var array<string, true>|null */
	private static ?array $declarativeSet = null;

	/** @var array<string, true>|null */
	private static ?array $referencePathSet = null;

	/**
	 * Determine whether a setting name is hard-blocked from CasC export and apply.
	 *
	 * @param string $name Cacti setting name.
	 * @return bool True when the setting is hard-blocked.
	 */
	public static function isBlockedSetting(string $name): bool {
		self::$blockedSet ??= array_fill_keys(self::BLOCKED_SETTINGS, true);

		if (isset(self::$blockedSet[$name])) {
			return true;
		}

		$lower = strtolower($name);

		return str_contains($lower, 'secret_encryption_key')
			|| str_contains($lower, 'csrf_secret')
			|| str_starts_with($lower, 'rsa_private');
	}

	/**
	 * Determine whether a setting stores credential material requiring redaction.
	 *
	 * @param string $name Cacti setting name.
	 * @return bool True when the setting is a secret key.
	 */
	public static function isSecretSetting(string $name): bool {
		self::$secretSet ??= array_fill_keys(self::SECRET_SETTINGS, true) + ['snmp_community' => true];

		return isset(self::$secretSet[$name]);
	}

	/**
	 * Determine whether a setting belongs to the declarative settings allowlist.
	 *
	 * @param string $name Cacti setting name.
	 * @return bool True when the setting may be managed in spec.settings.values.
	 */
	public static function isDeclarativeSetting(string $name): bool {
		if (self::isBlockedSetting($name)) {
			return false;
		}

		self::$declarativeSet ??= array_fill_keys(self::DECLARATIVE_SETTINGS, true);

		return isset(self::$declarativeSet[$name]);
	}

	/**
	 * Determine whether a setting is an installation-local reference path.
	 *
	 * @param string $name Cacti setting name.
	 * @return bool True when the setting belongs in reference.paths.
	 */
	public static function isReferencePathSetting(string $name): bool {
		self::$referencePathSet ??= array_fill_keys(self::REFERENCE_PATH_SETTINGS, true);

		return isset(self::$referencePathSet[$name]);
	}

	/**
	 * Determine whether a database table/column pair holds secret credentials.
	 *
	 * @param string $table Cacti table name.
	 * @param string $column Column name within the table.
	 * @return bool True when the column is classified as a secret.
	 */
	public static function isSecretColumn(string $table, string $column): bool {
		return in_array($column, self::SECRET_COLUMNS[$table] ?? [], true);
	}

	/**
	 * Check whether a string uses a typed secret reference prefix (env:, file:, vault:).
	 *
	 * @param string $value Candidate field value.
	 * @return bool True when the value is a typed secret reference.
	 */
	public static function isSecretReference(string $value): bool {
		return str_starts_with($value, 'env:')
			|| str_starts_with($value, 'file:')
			|| str_starts_with($value, 'vault:');
	}

	/**
	 * Redact a secret value for manifest export. Never returns plaintext.
	 *
	 * @param string $logicalKey Logical identifier used to derive env: placeholder names.
	 * @param string $rawValue Raw stored secret value.
	 * @param bool $omitSecrets Whether to omit secret fields entirely from export.
	 * @param bool $usePlaceholderRef Whether to emit env: placeholder names instead of <redacted>.
	 * @return string|null Redacted reference, marker, or null when omitted/empty.
	 */
	public static function exportSecret(string $logicalKey, string $rawValue, bool $omitSecrets = false, bool $usePlaceholderRef = true): ?string {
		if ($rawValue === '' || $omitSecrets) {
			return null;
		}

		if (self::isSecretReference($rawValue)) {
			return $rawValue;
		}

		if (!$usePlaceholderRef) {
			return self::REDACTED_MARKER;
		}

		$normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $logicalKey));
		$normalized = trim($normalized, '_');

		if ($normalized === '') {
			$normalized = 'VALUE';
		}

		return 'env:CACTI_SECRET_' . $normalized;
	}

	/**
	 * Mask any secret field before emitting diffs, logs, or audit records.
	 *
	 * @param string $value Secret value or typed reference.
	 * @return string Empty string, original typed reference, or <redacted> marker.
	 */
	public static function maskForDiff(string $value): string {
		if ($value === '') {
			return '';
		}

		if (self::isSecretReference($value)) {
			return $value;
		}

		return self::REDACTED_MARKER;
	}
}
