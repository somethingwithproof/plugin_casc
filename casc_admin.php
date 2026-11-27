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
$casc_user_id = isset($_SESSION['sess_user_id']) && is_numeric($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : 0;
$casc_actor   = $casc_user_id > 0 ? 'user:' . $casc_user_id : 'web';

$casc_plan   = null;
$casc_errors = array();

if ($casc_action === 'plan') {
	unset($_SESSION['casc_pending_plan']);
	$casc_file = isset($_FILES['manifest_file']) && is_array($_FILES['manifest_file']) ? $_FILES['manifest_file'] : array();

	if (($casc_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($casc_file['tmp_name'] ?? null) || !is_uploaded_file($casc_file['tmp_name'])) {
		$casc_errors[] = __('No manifest file was uploaded.', 'casc');
	} else {
		try {
			$casc_raw    = \Somethingwithproof\Casc\Codec\CascCodec::readFile($casc_file['tmp_name']);
			$casc_format = \Somethingwithproof\Casc\Codec\CascCodec::detectFormatFromContent($casc_raw);
			$casc_doc    = \Somethingwithproof\Casc\Codec\CascCodec::parse($casc_format, $casc_raw);
			$casc_plan   = $casc_service->plan($casc_doc, array(), $casc_actor, true);

			if (!$casc_plan->hasErrors() && $casc_plan->hasChanges()) {
				$_SESSION['casc_pending_plan'] = array(
					'user'   => $casc_user_id,
					'format' => $casc_format,
					'text'   => $casc_raw,
					'hash'   => $casc_plan->planHash,
				);
			}
		} catch (\Somethingwithproof\Casc\Codec\CodecException $e) {
			$casc_errors = $e->lines();
		}
	}
} elseif ($casc_action === 'apply') {
	$casc_pending   = $_SESSION['casc_pending_plan'] ?? null;
	$casc_submitted = isset_request_var('plan_hash') ? (string) get_nfilter_request_var('plan_hash') : '';

	unset($_SESSION['casc_pending_plan']);

	if (!is_array($casc_pending) || ($casc_pending['user'] ?? -1) !== $casc_user_id || !hash_equals((string) ($casc_pending['hash'] ?? ''), $casc_submitted)) {
		$casc_errors[] = __('No matching pending plan was found in your session. Re-upload and plan again.', 'casc');
	} else {
		try {
			$casc_doc    = \Somethingwithproof\Casc\Codec\CascCodec::parse((string) $casc_pending['format'], (string) $casc_pending['text']);
			$casc_result = $casc_service->apply($casc_doc, $casc_submitted, $casc_actor, 'web');

			if ($casc_result->succeeded) {
				if (function_exists('raise_message')) {
					raise_message('casc_apply', __('Applied CasC plan %s (%d actions).', substr($casc_result->planHash, 0, 12), count($casc_result->applied), 'casc'), MESSAGE_LEVEL_INFO);
				}
			} else {
				$casc_errors = $casc_result->errors;
			}
		} catch (\Somethingwithproof\Casc\Codec\CodecException $e) {
			$casc_errors = $e->lines();
		}
	}
}

top_header();
html_start_box(__('CasC Plan & Apply (Administrator)', 'casc'), '100%', false, 3, 'center', '');
print '<tr><td>';
print '<form method="post" action="casc_admin.php" enctype="multipart/form-data">'
	. '<input type="hidden" name="action" value="plan">'
	. '<label for="manifest_file">' . html_escape(__('Upload YAML or XML Manifest:', 'casc')) . ' </label>'
	. '<input type="file" id="manifest_file" name="manifest_file" accept=".yaml,.yml,.xml"> '
	. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Compute Plan', 'casc')) . '</button>'
	. '</form>';
print \Somethingwithproof\Casc\Ui\PagesRenderer::renderPlanPreview($casc_plan, $casc_errors, true);
print '</td></tr>';
html_end_box();
bottom_footer();
