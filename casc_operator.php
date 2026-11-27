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

chdir('../../');
include('./include/auth.php');
include_once('./plugins/casc/setup.php');

if (!casc_page_start()) {
	exit;
}

$casc_service = casc_service();
$casc_post    = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$casc_action  = $casc_post && isset_request_var('action') ? (string) get_nfilter_request_var('action') : '';
$casc_format  = isset_request_var('format') ? (string) get_nfilter_request_var('format') : \Somethingwithproof\Casc\Codec\CascCodec::YAML;

if (!\Somethingwithproof\Casc\Codec\CascCodec::isFormat($casc_format)) {
	$casc_format = \Somethingwithproof\Casc\Codec\CascCodec::YAML;
}

if ($casc_action === 'export') {
	$casc_text = $casc_service->export($casc_format);

	header('Content-Type: ' . ($casc_format === 'xml' ? 'application/xml' : 'application/yaml') . '; charset=UTF-8');
	header('Content-Disposition: attachment; filename="cacti-casc.' . $casc_format . '"');
	header('X-Content-Type-Options: nosniff');
	header('Content-Length: ' . strlen($casc_text));

	print $casc_text;
	exit;
}

$casc_plan   = null;
$casc_errors = array();

if ($casc_action === 'plan') {
	$casc_file = isset($_FILES['manifest_file']) && is_array($_FILES['manifest_file']) ? $_FILES['manifest_file'] : array();

	if (($casc_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($casc_file['tmp_name'] ?? null) || !is_uploaded_file($casc_file['tmp_name'])) {
		$casc_errors[] = __('No manifest file was uploaded.', 'casc');
	} else {
		try {
			$casc_raw    = \Somethingwithproof\Casc\Codec\CascCodec::readFile($casc_file['tmp_name']);
			$casc_format = \Somethingwithproof\Casc\Codec\CascCodec::detectFormatFromContent($casc_raw);
			$casc_doc    = \Somethingwithproof\Casc\Codec\CascCodec::parse($casc_format, $casc_raw);
			$casc_actor  = is_string($_SESSION['sess_user_id'] ?? null) || is_int($_SESSION['sess_user_id'] ?? null) ? 'user:' . (string) $_SESSION['sess_user_id'] : 'web';
			$casc_plan   = $casc_service->plan($casc_doc, array(), $casc_actor, false);
		} catch (\Somethingwithproof\Casc\Codec\CodecException $e) {
			$casc_errors = $e->lines();
		}
	}
}

top_header();
html_start_box(__('CasC Export & Drift Check (Read-Only)', 'casc'), '100%', false, 3, 'center', '');
print '<tr><td>';
print '<form method="post" action="casc_operator.php">'
	. '<input type="hidden" name="action" value="export">'
	. '<label for="casc_format">' . html_escape(__('Export Format:', 'casc')) . ' </label>'
	. '<select id="casc_format" name="format">'
	. '<option value="yaml">YAML 1.2</option>'
	. '<option value="xml">Canonical XML</option>'
	. '</select> '
	. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Export Redacted Manifest', 'casc')) . '</button>'
	. '</form><hr>';
print '<form method="post" action="casc_operator.php" enctype="multipart/form-data">'
	. '<input type="hidden" name="action" value="plan">'
	. '<label for="manifest_file">' . html_escape(__('Check Drift / Dry-Run Plan:', 'casc')) . ' </label>'
	. '<input type="file" id="manifest_file" name="manifest_file" accept=".yaml,.yml,.xml"> '
	. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Validate & Plan', 'casc')) . '</button>'
	. '</form>';
print \Somethingwithproof\Casc\Ui\PagesRenderer::renderPlanPreview($casc_plan, $casc_errors, false);
print '</td></tr>';
html_end_box();
bottom_footer();
