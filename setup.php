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

/*
 * Cacti includes setup.php on every request regardless of PHP version. Keep
 * this file PHP 8.0 parseable so sites running PHP 8.0 or 8.1 receive a clean
 * refusal instead of a fatal parse error.
 */

/**
 * Determine whether the active PHP runtime meets the PHP 8.2 minimum floor.
 *
 * @return bool True when PHP_VERSION is 8.2.0 or newer.
 */
function casc_php_supported(): bool {
	return version_compare(PHP_VERSION, '8.2.0', '>=');
}

/**
 * Register the zero-dependency PSR-4 autoloader when running on a supported PHP version.
 *
 * @return bool True when plugin_casc booted successfully.
 */
function casc_boot(): bool {
	if (!casc_php_supported()) {
		return false;
	}

	require_once(__DIR__ . '/include/autoload.php');

	return true;
}

/**
 * Emit a clear runtime refusal message when PHP is older than 8.2.0.
 *
 * @return bool True when execution was refused due to unsupported PHP.
 */
function casc_refuse_unsupported_php(): bool {
	if (casc_php_supported()) {
		return false;
	}

	$message = __('The CasC plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.', '8.2.0', PHP_VERSION, 'casc');

	cacti_log('ERROR: ' . $message, false, 'CASC');

	if (PHP_SAPI === 'cli') {
		fwrite(STDERR, $message . PHP_EOL);
	} elseif (function_exists('raise_message')) {
		raise_message('casc_php', $message, MESSAGE_LEVEL_ERROR);
	}

	return true;
}

/**
 * Parse and return plugin metadata from the INFO file for Cacti's plugin manager.
 *
 * @return array<string, string> Metadata key/value pairs from the [info] section.
 */
function plugin_casc_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	if (!is_array($info) || !isset($info['info']) || !is_array($info['info'])) {
		return array();
	}

	$version = array();

	foreach ($info['info'] as $key => $value) {
		$version[(string) $key] = is_scalar($value) ? (string) $value : '';
	}

	return $version;
}

/**
 * Cacti plugin install entry point.
 *
 * @return void
 */
function plugin_casc_install(): void {
	if (casc_refuse_unsupported_php()) {
		return;
	}

	plugin_casc_upgrade();
}

/**
 * Cacti plugin configuration check entry point.
 *
 * @return bool True when the plugin is ready and upgraded.
 */
function plugin_casc_check_config(): bool {
	if (casc_refuse_unsupported_php()) {
		return false;
	}

	return plugin_casc_upgrade();
}

/**
 * Perform idempotent schema migration, hook registration, and version recording.
 *
 * @return bool True when schema and hooks are current.
 */
function plugin_casc_upgrade(): bool {
	if (!casc_boot()) {
		return false;
	}

	$info   = plugin_casc_version();
	$schema = new \Somethingwithproof\Casc\Schema(false);

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === ($info['version'] ?? '') && $schema->hooksRegistered()) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	casc_setup_hooks();
	$schema->recordPluginVersion($info['version'] ?? '0.1.0', $info['author'] ?? '', $info['homepage'] ?? '');

	return true;
}

/**
 * Register hooks and 3-tier RBAC realms.
 *
 * Kept procedural with *_setup_* naming because Cacti's
 * api_plugin_register_hook() and api_plugin_register_realm() validate the
 * caller's function name.
 *
 * @return void
 */
function casc_setup_hooks(): void {
	global $config;

	if (!function_exists('replicate_out') && isset($config['library_path']) && is_file($config['library_path'] . '/poller.php')) {
		include_once($config['library_path'] . '/poller.php');
	}

	$enabled = (new \Somethingwithproof\Casc\Schema(false))->pluginEnabled();

	foreach (\Somethingwithproof\Casc\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('casc', $hook, $function, 'setup.php', $enabled);
	}

	foreach (\Somethingwithproof\Casc\Pages::realms() as $realm) {
		api_plugin_register_realm('casc', implode(',', $realm['files']), $realm['name'], $realm['default']);
	}
}

/**
 * Cacti plugin uninstall entry point with optional audit table retention.
 *
 * @return void
 */
function plugin_casc_uninstall(): void {
	if (!casc_boot()) {
		return;
	}

	$schema = new \Somethingwithproof\Casc\Schema(false);

	if (read_config_option('casc_uninstall_keep_tables') == 'on') {
		$schema->untrackTables();
		cacti_log('NOTE: Uninstall kept plugin_casc tables as configured', false, 'CASC');
	} else {
		$schema->forgetVersion();
	}
}

/**
 * Load typed plugin_casc settings from Cacti's settings table.
 *
 * @return \Somethingwithproof\Casc\Settings Loaded settings value object.
 */
function casc_settings(): \Somethingwithproof\Casc\Settings {
	$row = array();

	foreach (\Somethingwithproof\Casc\Settings::NAMES as $name) {
		$row[$name] = read_config_option($name);
	}

	return \Somethingwithproof\Casc\Settings::fromRow($row);
}

/**
 * Fetch enabled Cacti plugin hook rows for a named plugin_casc extension hook.
 *
 * @param string $hookName Extension hook name stored in plugin_hooks.
 * @return list<array{name: string, file: string, function: string}> Enabled hook entries.
 */
function casc_fetch_extension_hooks(string $hookName): array {
	if (!function_exists('db_fetch_assoc_prepared')) {
		return array();
	}

	$rows = db_fetch_assoc_prepared(
		'SELECT h.name, h.file, h.function FROM plugin_hooks AS h INNER JOIN plugin_config AS c ON c.directory = h.name WHERE h.hook = ? AND h.status = 1 AND c.status = 1 ORDER BY h.name',
		array($hookName)
	);

	if (!is_array($rows)) {
		return array();
	}

	$hooks = array();

	foreach ($rows as $row) {
		if (is_array($row) && is_string($row['name'] ?? null) && is_string($row['file'] ?? null) && is_string($row['function'] ?? null)) {
			$hooks[] = array(
				'name'     => $row['name'],
				'file'     => $row['file'],
				'function' => $row['function'],
			);
		}
	}

	return $hooks;
}

/**
 * Build a safe file path resolver closure for plugin extension hook files.
 *
 * @return callable(string, string): (string|false) Resolver mapping (slug, file) to a path.
 */
function casc_plugin_file_resolver(): callable {
	global $config;

	$basePath = is_string($config['base_path'] ?? null) ? $config['base_path'] : '';

	return static function (string $slug, string $file) use ($basePath) {
		if (function_exists('cacti_plugin_path')) {
			return cacti_plugin_path($slug, $file);
		}

		if ($basePath === '') {
			return false;
		}

		return $basePath . '/plugins/' . $slug . '/' . $file;
	};
}

/**
 * Discover and load fault-isolated sibling plugin providers registered on
 * casc_register_providers.
 *
 * @return \Somethingwithproof\Casc\Extension\ProviderRegistry Populated provider registry.
 */
function casc_provider_registry(): \Somethingwithproof\Casc\Extension\ProviderRegistry {
	$registry = new \Somethingwithproof\Casc\Extension\ProviderRegistry();
	$hooks    = casc_fetch_extension_hooks('casc_register_providers');

	if ($hooks === array()) {
		return $registry;
	}

	$registry->dispatchHooks($hooks, casc_plugin_file_resolver());

	return $registry;
}

/**
 * Discover fault-isolated YAML template compilers registered on
 * casc_register_template_compilers (e.g. optional plugin_yaml).
 *
 * @return list<callable(string, array<string, mixed>): mixed> Registered compiler callbacks.
 */
function casc_template_compilers(): array {
	$hooks = casc_fetch_extension_hooks('casc_register_template_compilers');

	if ($hooks === array()) {
		return array();
	}

	return \Somethingwithproof\Casc\Codec\TemplateBridge::dispatchCompilerHooks($hooks, casc_plugin_file_resolver());
}

/**
 * Construct the fully wired CasC orchestration service for CLI, web, and hooks.
 *
 * @return \Somethingwithproof\Casc\Engine\CascService Active CasC service facade.
 */
function casc_service(): \Somethingwithproof\Casc\Engine\CascService {
	global $config;

	$settings    = casc_settings();
	$libraryPath = is_string($config['library_path'] ?? null) ? $config['library_path'] : '';
	$repo        = new \Somethingwithproof\Casc\Repository\CactiStateRepository($libraryPath, false);
	$secrets     = new \Somethingwithproof\Casc\Secret\SecretResolver($settings->secretDirs);
	$registry    = casc_provider_registry();
	$compilers   = casc_template_compilers();
	$info        = plugin_casc_version();

	return new \Somethingwithproof\Casc\Engine\CascService(
		$repo,
		$secrets,
		$registry,
		$info['compat'] ?? '1.2.31',
		$compilers
	);
}

/**
 * Cacti config_arrays hook: register Console menu entries and trigger deferred upgrades.
 *
 * @return void
 */
function casc_config_arrays(): void {
	global $menu;

	if (!casc_boot()) {
		return;
	}

	foreach (\Somethingwithproof\Casc\Pages::menu() as $file => $title) {
		$menu[__('Import/Export')]['plugins/casc/' . $file] = $title;
	}

	if (in_array(get_current_page(), \Somethingwithproof\Casc\Pages::upgradeTriggers(), true)) {
		plugin_casc_upgrade();
	}
}

/**
 * Cacti config_settings hook: expose the CasC settings tab to CasC Admin users.
 *
 * @return void
 */
function casc_config_settings(): void {
	global $tabs, $settings;

	if (PHP_SAPI !== 'cli' && !api_plugin_user_realm_auth(\Somethingwithproof\Casc\Pages::ADMIN)) {
		return;
	}

	if (!casc_boot()) {
		return;
	}

	$tabs['casc']     = __('CasC', 'casc');
	$settings['casc'] = \Somethingwithproof\Casc\Settings::form();
}

/**
 * Cacti draw_navigation_text hook: register breadcrumb navigation entries.
 *
 * @param array<string, mixed> $nav Existing Cacti navigation entries.
 * @return array<string, mixed> Updated navigation entries including CasC pages.
 */
function casc_draw_navigation_text($nav) {
	global $current_user;

	if (!casc_boot()) {
		return $nav;
	}

	if (get_current_page() === 'settings.php' && (PHP_SAPI === 'cli' || ($current_user['enabled'] ?? '') === 'on')) {
		casc_config_settings();
	}

	return array_merge($nav, \Somethingwithproof\Casc\Pages::navigation());
}

/**
 * Resolve 3-tier realm inheritance: Admin > Operator > View.
 *
 * @return void
 */
function casc_auth_alternate_realms(): void {
	global $user_auth_realm_filenames;

	static $deferred = array();

	foreach ($deferred as $file => $realm) {
		$user_auth_realm_filenames[$file] = $realm;
	}
	$deferred = array();

	if (!api_plugin_is_enabled('casc')) {
		return;
	}

	if (empty($_SESSION['sess_user_id'])) {
		$page = get_current_page();

		if (in_array($page, array('casc_view.php', 'casc_operator.php'), true) && isset($user_auth_realm_filenames[$page])) {
			$deferred[$page]                  = $user_auth_realm_filenames[$page];
			$user_auth_realm_filenames[$page] = -1;
		}

		return;
	}

	$adminRealm    = $user_auth_realm_filenames['casc_admin.php'] ?? 0;
	$operatorRealm = $user_auth_realm_filenames['casc_operator.php'] ?? 0;

	if ($adminRealm > 0 && is_realm_allowed($adminRealm)) {
		$user_auth_realm_filenames['casc_operator.php'] = $adminRealm;
		$user_auth_realm_filenames['casc_view.php']     = $adminRealm;
	} elseif ($operatorRealm > 0 && is_realm_allowed($operatorRealm)) {
		$user_auth_realm_filenames['casc_view.php'] = $operatorRealm;
	}
}

/**
 * Verify whether the current user is authorized for the active CasC Console page.
 *
 * @return bool True when access is allowed.
 */
function casc_page_allowed(): bool {
	global $user_auth_realm_filenames;

	casc_auth_alternate_realms();
	$page  = get_current_page();
	$realm = $user_auth_realm_filenames[$page] ?? 0;

	return $realm > 0 && is_realm_allowed($realm);
}

/**
 * Guard CasC Console pages for PHP version floor, plugin enablement, and RBAC realm.
 *
 * @return bool True when the page may proceed rendering.
 */
function casc_page_start(): bool {
	if (casc_boot() && api_plugin_is_enabled('casc')) {
		if (casc_page_allowed()) {
			return true;
		}

		http_response_code(403);

		return false;
	}

	top_header();
	html_start_box(__('Configuration as Code', 'casc'), '100%', false, 3, 'center', '');
	print '<tr><td>' . html_escape(casc_php_supported() ? __('The CasC plugin is not enabled.', 'casc') : __('The CasC plugin requires PHP %s or later.', '8.2.0', 'casc')) . '</td></tr>';
	html_end_box();
	bottom_footer();

	return false;
}

/**
 * plugin_mcp abilities_register hook: register read-only CasC status and drift
 * abilities.
 *
 * @param mixed $registrar plugin_mcp AbilitiesRegistrar instance.
 * @return void
 */
function casc_abilities_register($registrar): void {
	if (!casc_boot() || !is_object($registrar)) {
		return;
	}

	\Somethingwithproof\Casc\Integration\McpAbilities::register(
		$registrar,
		static function (): \Somethingwithproof\Casc\Engine\CascService {
			return casc_service();
		},
		static function (): string {
			return casc_settings()->baselineFile;
		},
		static function (int $userId): bool {
			return \Somethingwithproof\Casc\Integration\McpAbilities::userHasViewRealm($userId);
		}
	);
}

/**
 * plugin_doctor doctor_register_checks hook: contribute CasC diagnostic checks
 * matching the plugin_doctor contract for both mode='list' and mode='run'.
 *
 * @param mixed $context Hook context array containing mode ('run'|'list') and plugin slug.
 * @return list<array<string, mixed>> Structured check descriptors.
 */
function casc_doctor_register_checks($context = array()): array {
	if (!casc_boot()) {
		return array();
	}

	$normalized = array();

	if (is_array($context)) {
		foreach ($context as $key => $value) {
			$normalized[(string) $key] = $value;
		}
	}

	return \Somethingwithproof\Casc\Integration\DoctorChecks::build(
		static function (): \Somethingwithproof\Casc\Engine\CascService {
			return casc_service();
		},
		static function (): \Somethingwithproof\Casc\Settings {
			return casc_settings();
		},
		$normalized
	);
}

/**
 * plugin_doctor doctor_register_debug_info hook: contribute Site Health style
 * runtime metadata with zero secret values.
 *
 * @param mixed $context Optional hook context passed by plugin_doctor.
 * @return list<array<string, mixed>> Debug info section descriptors.
 */
function casc_doctor_register_debug_info($context = array()): array {
	if (!casc_boot()) {
		return array();
	}

	$info = plugin_casc_version();

	return \Somethingwithproof\Casc\Integration\DoctorChecks::debugInfo(
		static function (): \Somethingwithproof\Casc\Engine\CascService {
			return casc_service();
		},
		static function (): \Somethingwithproof\Casc\Settings {
			return casc_settings();
		},
		$info['version'] ?? '0.1.0'
	);
}

/**
 * plugin_api api_register_resources hook: register read and plan CasC API
 * resource descriptors referencing schema/casc.schema.yaml.
 *
 * @param mixed $resources Existing resource list passed through the hook.
 * @return list<array<string, mixed>> Combined API resource descriptors.
 */
function casc_api_register_resources($resources = array()): array {
	if (!casc_boot()) {
		return array();
	}

	return \Somethingwithproof\Casc\Integration\ApiResources::register($resources);
}

/**
 * casc_describe_schema hook: return live JSON Schema Draft 2020-12 metadata
 * across all core and registered sibling plugin CasC providers.
 *
 * @param mixed $context Optional hook context.
 * @return array<string, mixed> JSON Schema Draft 2020-12 document.
 */
function casc_describe_schema($context = array()): array {
	if (!casc_boot()) {
		return array();
	}

	return casc_service()->describeSchema();
}
