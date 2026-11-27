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

namespace Somethingwithproof\Casc\Ui;

use Somethingwithproof\Casc\Engine\Plan;
use Somethingwithproof\Casc\Extension\PluginProviderDescriptor;

/**
 * HTML-escaped Cacti Console renderers for casc_view.php, casc_operator.php,
 * and casc_admin.php.
 */
final class PagesRenderer {
	/**
	 * @param list<array<string, mixed>> $audits
	 * @param list<PluginProviderDescriptor> $providers
	 * @param list<array{slug: string, phase: string, reason: string}> $faults
	 */
	public static function renderAuditAndProviders(array $audits, array $providers, array $faults): string {
		$html = '<table class="cactiTable"><thead><tr>'
			. '<th>Time (UTC)</th><th>Actor</th><th>Source</th><th>Action</th><th>Status</th><th>Plan Hash</th><th>Changes (+/~/ -)</th>'
			. '</tr></thead><tbody>';

		if ($audits === []) {
			$html .= '<tr><td colspan="7">No CasC audit entries recorded yet.</td></tr>';
		} else {
			foreach ($audits as $row) {
				$ts      = is_numeric($row['occurred_at'] ?? null) ? gmdate('Y-m-d H:i:s', (int) $row['occurred_at']) : '';
				$actor   = is_scalar($row['actor'] ?? null) ? (string) $row['actor'] : '';
				$source  = is_scalar($row['source'] ?? null) ? (string) $row['source'] : '';
				$action  = is_scalar($row['action'] ?? null) ? (string) $row['action'] : '';
				$status  = is_scalar($row['status'] ?? null) ? (string) $row['status'] : '';
				$hash    = is_scalar($row['plan_hash'] ?? null) ? substr((string) $row['plan_hash'], 0, 16) : '';
				$created = is_numeric($row['created_count'] ?? null) ? (int) $row['created_count'] : 0;
				$updated = is_numeric($row['updated_count'] ?? null) ? (int) $row['updated_count'] : 0;
				$deleted = is_numeric($row['deleted_count'] ?? null) ? (int) $row['deleted_count'] : 0;

				$html .= '<tr>'
					. '<td>' . html_escape($ts) . '</td>'
					. '<td>' . html_escape($actor) . '</td>'
					. '<td>' . html_escape($source) . '</td>'
					. '<td>' . html_escape($action) . '</td>'
					. '<td>' . html_escape($status) . '</td>'
					. '<td><code>' . html_escape($hash) . '</code></td>'
					. '<td>' . html_escape(sprintf('+%d / ~%d / -%d', $created, $updated, $deleted)) . '</td>'
					. '</tr>';
			}
		}

		$html .= '</tbody></table>';

		$html .= '<h3>Registered Plugin CasC Providers</h3>';

		if ($providers === []) {
			$html .= '<p>No sibling plugins have registered a <code>casc_register_providers</code> handler.</p>';
		} else {
			$html .= '<ul>';

			foreach ($providers as $provider) {
				$html .= '<li><code>' . html_escape($provider->slug) . '</code> (schema v' . html_escape($provider->schemaVersion) . ')</li>';
			}

			$html .= '</ul>';
		}

		if ($faults !== []) {
			$html .= '<h3>Isolated Provider Faults</h3><ul>';

			foreach ($faults as $fault) {
				$html .= '<li><code>' . html_escape($fault['slug']) . '</code> [' . html_escape($fault['phase']) . ']: ' . html_escape($fault['reason']) . '</li>';
			}

			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * @param list<string> $errors
	 */
	public static function renderPlanPreview(?Plan $plan, array $errors, bool $allowApply = false): string {
		$html = '';

		if ($errors !== []) {
			$html .= '<div class="cactiMessage error"><ul>';

			foreach ($errors as $error) {
				$html .= '<li>' . html_escape($error) . '</li>';
			}

			$html .= '</ul></div>';
		}

		if ($plan === null) {
			return $html;
		}

		$html .= '<pre>' . html_escape($plan->formatText()) . '</pre>';

		if ($allowApply && !$plan->hasErrors() && $plan->hasChanges()) {
			$html .= '<form method="post" action="casc_admin.php">'
				. '<input type="hidden" name="action" value="apply">'
				. '<input type="hidden" name="plan_hash" value="' . html_escape($plan->planHash) . '">'
				. '<button type="submit" class="ui-button ui-corner-all ui-widget">Apply Planned Changes (' . html_escape(substr($plan->planHash, 0, 12)) . ')</button>'
				. '</form>';
		}

		return $html;
	}
}
