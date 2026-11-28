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

$GLOBALS['__casc_log']     = [];
$GLOBALS['__casc_options'] = [];
$GLOBALS['__casc_mock']    = [];

if (!defined('MESSAGE_LEVEL_ERROR')) {
	define('MESSAGE_LEVEL_ERROR', 2);
}

if (!function_exists('__')) {
	function __() {
		$args = func_get_args();

		if (count($args) === 1 || preg_match('/%(%|c|[-0-9.]*[bdeEfFgGhHosuxX])/', (string) $args[0]) !== 1) {
			return (string) $args[0];
		}

		return call_user_func_array('sprintf', $args);
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
		$GLOBALS['__casc_log'][] = ['message' => (string) $string, 'environ' => (string) $environ];

		return true;
	}
}

if (!function_exists('html_escape')) {
	function html_escape($string) {
		return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8', false);
	}
}

if (!function_exists('read_config_option')) {
	function read_config_option($config_name, $force = false) {
		return $GLOBALS['__casc_options'][$config_name] ?? '';
	}
}

if (!function_exists('db_begin_transaction')) {
	function db_begin_transaction($db_conn = false) {
		if (isset($GLOBALS['__casc_mock']['db_begin_transaction']) && is_callable($GLOBALS['__casc_mock']['db_begin_transaction'])) {
			return ($GLOBALS['__casc_mock']['db_begin_transaction'])($db_conn);
		}

		return true;
	}
}

if (!function_exists('db_commit_transaction')) {
	function db_commit_transaction($db_conn = false) {
		if (isset($GLOBALS['__casc_mock']['db_commit_transaction']) && is_callable($GLOBALS['__casc_mock']['db_commit_transaction'])) {
			return ($GLOBALS['__casc_mock']['db_commit_transaction'])($db_conn);
		}

		return true;
	}
}

if (!function_exists('db_rollback_transaction')) {
	function db_rollback_transaction($db_conn = false) {
		if (isset($GLOBALS['__casc_mock']['db_rollback_transaction']) && is_callable($GLOBALS['__casc_mock']['db_rollback_transaction'])) {
			return ($GLOBALS['__casc_mock']['db_rollback_transaction'])($db_conn);
		}

		return true;
	}
}

if (!function_exists('db_fetch_assoc_prepared')) {
	function db_fetch_assoc_prepared($sql, $params = [], $log = true, $db_conn = false) {
		if (isset($GLOBALS['__casc_mock']['db_fetch_assoc_prepared']) && is_callable($GLOBALS['__casc_mock']['db_fetch_assoc_prepared'])) {
			return ($GLOBALS['__casc_mock']['db_fetch_assoc_prepared'])($sql, $params, $log, $db_conn);
		}

		return [];
	}
}

if (!function_exists('db_fetch_cell_prepared')) {
	function db_fetch_cell_prepared($sql, $params = [], $col_name = '', $log = true, $db_conn = false) {
		if (isset($GLOBALS['__casc_mock']['db_fetch_cell_prepared']) && is_callable($GLOBALS['__casc_mock']['db_fetch_cell_prepared'])) {
			return ($GLOBALS['__casc_mock']['db_fetch_cell_prepared'])($sql, $params, $col_name, $log, $db_conn);
		}

		return '';
	}
}

if (!function_exists('db_execute_prepared')) {
	function db_execute_prepared($sql, $params = [], $log = true, $db_conn = false) {
		if (isset($GLOBALS['__casc_mock']['db_execute_prepared']) && is_callable($GLOBALS['__casc_mock']['db_execute_prepared'])) {
			return ($GLOBALS['__casc_mock']['db_execute_prepared'])($sql, $params, $log, $db_conn);
		}

		return true;
	}
}

if (!function_exists('db_fetch_insert_id')) {
	function db_fetch_insert_id($db_conn = false) {
		if (isset($GLOBALS['__casc_mock']['db_fetch_insert_id']) && is_callable($GLOBALS['__casc_mock']['db_fetch_insert_id'])) {
			return ($GLOBALS['__casc_mock']['db_fetch_insert_id'])($db_conn);
		}

		return 1;
	}
}

if (!function_exists('api_plugin_db_table_create')) {
	function api_plugin_db_table_create($plugin, $table, $data) {
		if (isset($GLOBALS['__casc_mock']['api_plugin_db_table_create']) && is_callable($GLOBALS['__casc_mock']['api_plugin_db_table_create'])) {
			return ($GLOBALS['__casc_mock']['api_plugin_db_table_create'])($plugin, $table, $data);
		}

		return true;
	}
}

if (!function_exists('api_plugin_register_hook')) {
	function api_plugin_register_hook($plugin, $hook, $function, $file, $enable = false) {
		if (isset($GLOBALS['__casc_mock']['api_plugin_register_hook']) && is_callable($GLOBALS['__casc_mock']['api_plugin_register_hook'])) {
			return ($GLOBALS['__casc_mock']['api_plugin_register_hook'])($plugin, $hook, $function, $file, $enable);
		}

		return true;
	}
}

if (!function_exists('api_plugin_register_realm')) {
	function api_plugin_register_realm($plugin, $file, $display, $admin = false) {
		if (isset($GLOBALS['__casc_mock']['api_plugin_register_realm']) && is_callable($GLOBALS['__casc_mock']['api_plugin_register_realm'])) {
			return ($GLOBALS['__casc_mock']['api_plugin_register_realm'])($plugin, $file, $display, $admin);
		}

		return true;
	}
}

if (!function_exists('api_plugin_is_enabled')) {
	function api_plugin_is_enabled($plugin) {
		if (isset($GLOBALS['__casc_mock']['api_plugin_is_enabled']) && is_callable($GLOBALS['__casc_mock']['api_plugin_is_enabled'])) {
			return (bool) ($GLOBALS['__casc_mock']['api_plugin_is_enabled'])($plugin);
		}

		return true;
	}
}

if (!function_exists('api_plugin_user_realm_auth')) {
	function api_plugin_user_realm_auth($filename = '') {
		if (isset($GLOBALS['__casc_mock']['api_plugin_user_realm_auth']) && is_callable($GLOBALS['__casc_mock']['api_plugin_user_realm_auth'])) {
			return (bool) ($GLOBALS['__casc_mock']['api_plugin_user_realm_auth'])($filename);
		}

		return true;
	}
}

if (!function_exists('api_plugin_installed')) {
	function api_plugin_installed($plugin) {
		if (isset($GLOBALS['__casc_mock']['api_plugin_installed']) && is_callable($GLOBALS['__casc_mock']['api_plugin_installed'])) {
			return (bool) ($GLOBALS['__casc_mock']['api_plugin_installed'])($plugin);
		}

		return false;
	}
}

if (!function_exists('api_plugin_install')) {
	function api_plugin_install($plugin) {
		if (isset($GLOBALS['__casc_mock']['api_plugin_install']) && is_callable($GLOBALS['__casc_mock']['api_plugin_install'])) {
			return ($GLOBALS['__casc_mock']['api_plugin_install'])($plugin);
		}

		return true;
	}
}

if (!function_exists('api_plugin_enable')) {
	function api_plugin_enable($plugin) {
		if (isset($GLOBALS['__casc_mock']['api_plugin_enable']) && is_callable($GLOBALS['__casc_mock']['api_plugin_enable'])) {
			return ($GLOBALS['__casc_mock']['api_plugin_enable'])($plugin);
		}

		return true;
	}
}

if (!function_exists('api_plugin_disable')) {
	function api_plugin_disable($plugin) {
		if (isset($GLOBALS['__casc_mock']['api_plugin_disable']) && is_callable($GLOBALS['__casc_mock']['api_plugin_disable'])) {
			return ($GLOBALS['__casc_mock']['api_plugin_disable'])($plugin);
		}

		return true;
	}
}

if (!function_exists('api_device_save')) {
	function api_device_save(...$args) {
		if (isset($GLOBALS['__casc_mock']['api_device_save']) && is_callable($GLOBALS['__casc_mock']['api_device_save'])) {
			return ($GLOBALS['__casc_mock']['api_device_save'])(...$args);
		}

		return 1;
	}
}

if (!function_exists('api_device_remove')) {
	function api_device_remove($host_id) {
		if (isset($GLOBALS['__casc_mock']['api_device_remove']) && is_callable($GLOBALS['__casc_mock']['api_device_remove'])) {
			return ($GLOBALS['__casc_mock']['api_device_remove'])($host_id);
		}

		return true;
	}
}

if (!function_exists('import_xml_data')) {
	function import_xml_data(&$xml_data, $import_as_new, $profile_id, $remove_orphans = false, $replace_svalues = false) {
		if (isset($GLOBALS['__casc_mock']['import_xml_data']) && is_callable($GLOBALS['__casc_mock']['import_xml_data'])) {
			return ($GLOBALS['__casc_mock']['import_xml_data'])($xml_data, $import_as_new, $profile_id, $remove_orphans, $replace_svalues);
		}

		return ['ok' => true];
	}
}

if (!function_exists('import_package')) {
	function import_package($xmlfile, $profile_id = 1, $remove_orphans = false, $replace_svalues = false) {
		if (isset($GLOBALS['__casc_mock']['import_package']) && is_callable($GLOBALS['__casc_mock']['import_package'])) {
			return ($GLOBALS['__casc_mock']['import_package'])($xmlfile, $profile_id, $remove_orphans, $replace_svalues);
		}

		return ['ok' => true];
	}
}

if (!function_exists('get_item_xml')) {
	function get_item_xml($type, $id, $follow_dependencies = true) {
		if (isset($GLOBALS['__casc_mock']['get_item_xml']) && is_callable($GLOBALS['__casc_mock']['get_item_xml'])) {
			return ($GLOBALS['__casc_mock']['get_item_xml'])($type, $id, $follow_dependencies);
		}

		return '';
	}
}

if (!function_exists('replicate_out')) {
	function replicate_out($poller_id = 1, $class = 'all') {
		if (isset($GLOBALS['__casc_mock']['replicate_out']) && is_callable($GLOBALS['__casc_mock']['replicate_out'])) {
			return ($GLOBALS['__casc_mock']['replicate_out'])($poller_id, $class);
		}

		return true;
	}
}

if (!function_exists('update_poller_cache')) {
	function update_poller_cache($data_source_id, $commit = false) {
		if (isset($GLOBALS['__casc_mock']['update_poller_cache']) && is_callable($GLOBALS['__casc_mock']['update_poller_cache'])) {
			return ($GLOBALS['__casc_mock']['update_poller_cache'])($data_source_id, $commit);
		}

		return true;
	}
}

if (!function_exists('repopulate_poller_cache')) {
	function repopulate_poller_cache() {
		if (isset($GLOBALS['__casc_mock']['repopulate_poller_cache']) && is_callable($GLOBALS['__casc_mock']['repopulate_poller_cache'])) {
			return ($GLOBALS['__casc_mock']['repopulate_poller_cache'])();
		}

		return true;
	}
}

if (!function_exists('get_current_page')) {
	function get_current_page() {
		if (isset($GLOBALS['__casc_mock']['get_current_page']) && is_callable($GLOBALS['__casc_mock']['get_current_page'])) {
			return (string) ($GLOBALS['__casc_mock']['get_current_page'])();
		}

		return '';
	}
}

if (!function_exists('is_realm_allowed')) {
	function is_realm_allowed($realm_id, $user_id = 0) {
		if (isset($GLOBALS['__casc_mock']['is_realm_allowed']) && is_callable($GLOBALS['__casc_mock']['is_realm_allowed'])) {
			return (bool) ($GLOBALS['__casc_mock']['is_realm_allowed'])($realm_id, $user_id);
		}

		return false;
	}
}

if (!function_exists('top_header')) {
	function top_header() {
		$GLOBALS['__casc_mock']['rendered_header'] = true;
	}
}

if (!function_exists('html_start_box')) {
	function html_start_box($title, $size, $div, $cell_padding, $align, $add_text) {
		$GLOBALS['__casc_mock']['rendered_box'] = (string) $title;
	}
}

if (!function_exists('html_end_box')) {
	function html_end_box() {
		$GLOBALS['__casc_mock']['rendered_box_end'] = true;
	}
}

if (!function_exists('bottom_footer')) {
	function bottom_footer() {
		$GLOBALS['__casc_mock']['rendered_footer'] = true;
	}
}

$GLOBALS['config'] = [
	'base_path'    => dirname(__DIR__, 3),
	'library_path' => dirname(__DIR__, 3) . '/lib',
	'poller_id'    => 1,
];

require_once dirname(__DIR__) . '/include/autoload.php';
require_once dirname(__DIR__) . '/setup.php';
