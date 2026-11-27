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

$casc_service   = casc_service();
$casc_audits    = $casc_service->repository()->getRecentAudits(50);
$casc_providers = $casc_service->pluginRegistry()->all();
$casc_faults    = $casc_service->pluginRegistry()->faults();

top_header();
html_start_box(__('Configuration as Code Status & Audit History', 'casc'), '100%', false, 3, 'center', '');
print '<tr><td>' . \Somethingwithproof\Casc\Ui\PagesRenderer::renderAuditAndProviders($casc_audits, $casc_providers, $casc_faults) . '</td></tr>';
html_end_box();
bottom_footer();
