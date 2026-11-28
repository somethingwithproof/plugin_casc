#!/bin/sh
# SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
# SPDX-License-Identifier: GPL-2.0-or-later
#
# This file is part of plugin_casc, a plugin for Cacti.
# https://github.com/somethingwithproof/plugin_casc
#
# This program is free software; you can redistribute it and/or modify it
# under the terms of the GNU General Public License as published by the Free
# Software Foundation; either version 2 of the License, or (at your option)
# any later version.
#
# This program is distributed in the hope that it will be useful, but WITHOUT
# ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
# FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
# more details.
#
# Regenerate locales/po/cacti.pot from all runtime PHP files (excluding tests/
# and vendor/), merge it into each catalog locales/po/<locale>.po, and compile
# each catalog to locales/LC_MESSAGES/<locale>.mo, where Cacti loads plugin
# translations.
#
# With --check, change nothing and fail when the template is out of date, a
# catalog misses or has a fuzzy or untranslated entry, a format string does not
# match, a positional placeholder is used, or a compiled catalog differs from
# its source.

set -eu

BASE_PATH=$(cd "$(dirname "$0")/.." && pwd)
POT=locales/po/cacti.pot

cd "$BASE_PATH"
mkdir -p locales/po

command -v xgettext >/dev/null 2>&1 || { echo "ERROR: Unable to locate xgettext (install GNU gettext)" >&2; exit 1; }
command -v msgfmt >/dev/null 2>&1 || { echo "ERROR: Unable to locate msgfmt (install GNU gettext)" >&2; exit 1; }
command -v msgmerge >/dev/null 2>&1 || { echo "ERROR: Unable to locate msgmerge (install GNU gettext)" >&2; exit 1; }

VERSION=$(sed -n 's/^version *= *//p' INFO)

generate() {
	out=$1
	filelist=$(mktemp)
	find . -name '*.php' \
		-not -path './tests/*' \
		-not -path './vendor/*' \
		-not -path './.git/*' \
		-not -path './cacti/*' | sed 's|^\./||' | sort > "$filelist"

	xgettext --no-wrap --add-location=file --from-code=UTF-8 --copyright-holder="Thomas Vincent" \
		--package-name="casc" --package-version="$VERSION" --msgid-bugs-address="https://github.com/somethingwithproof/plugin_casc/issues" \
		-F -L PHP -k__gettext -k__ -k__n:1,2 -k__x:1c,2 -k__xn:1c,2,3 -k__esc -k__esc_n:1,2 -k__esc_x:1c,2 -k__esc_xn:1c,2,3 -k__date \
		-o "$out" -f "$filelist"

	rm -f "$filelist"

	sed -e 's/FULL NAME <EMAIL@ADDRESS>/Thomas Vincent <thomasvincent@gmail.com>/' \
		-e 's/LANGUAGE <LL@li.org>/Thomas Vincent <thomasvincent@gmail.com>/' \
		-e 's/CHARSET/UTF-8/' \
		-e '/^"POT-Creation-Date:/d' "$out" > "$out.tmp" && mv "$out.tmp" "$out"
}

if [ "${1:-}" = "--check" ]; then
	command -v msgcmp >/dev/null 2>&1 || { echo "ERROR: Unable to locate msgcmp (install GNU gettext)" >&2; exit 1; }
	command -v msgattrib >/dev/null 2>&1 || { echo "ERROR: Unable to locate msgattrib (install GNU gettext)" >&2; exit 1; }
	command -v msgunfmt >/dev/null 2>&1 || { echo "ERROR: Unable to locate msgunfmt (install GNU gettext)" >&2; exit 1; }

	tmp=$(mktemp)
	generate "$tmp"

	if ! diff -u "$POT" "$tmp"; then
		rm -f "$tmp"
		echo "ERROR: $POT is out of date; run locales/build_gettext.sh and commit the result" >&2
		exit 1
	fi

	rm -f "$tmp"
	echo "$POT is up to date"

	rc=0
	po_count=0

	for po in locales/po/*.po; do
		[ -f "$po" ] || continue
		po_count=$((po_count + 1))
		locale=$(basename "$po" .po)
		mo=locales/LC_MESSAGES/$locale.mo

		msgfmt --check -o /dev/null "$po" || { echo "ERROR: $po does not compile" >&2; rc=1; continue; }
		msgcmp "$po" "$POT" || { echo "ERROR: $po misses entries of $POT; run locales/build_gettext.sh" >&2; rc=1; }
		[ -z "$(msgattrib --only-fuzzy --no-obsolete "$po")" ] || { echo "ERROR: $po has fuzzy entries" >&2; rc=1; }
		[ -z "$(msgattrib --untranslated --no-obsolete "$po")" ] || { echo "ERROR: $po has untranslated entries" >&2; rc=1; }

		if msgattrib --no-obsolete --no-wrap "$po" | grep -E '^msgstr' | grep -qE '%[0-9]+\$'; then
			echo "ERROR: $po uses a positional placeholder such as %1\$d, which Cacti prints literally" >&2
			rc=1
		fi

		if [ ! -f "$mo" ] || [ "$(msgunfmt "$mo")" != "$(msgfmt -o - "$po" | msgunfmt)" ]; then
			echo "ERROR: $mo is missing or out of date; run locales/build_gettext.sh" >&2
			rc=1
		fi
	done

	if [ "$po_count" -eq 0 ]; then
		echo "ERROR: No .po catalogs found in locales/po/" >&2
		rc=1
	fi

	for mo in locales/LC_MESSAGES/*.mo; do
		[ -f "$mo" ] || continue
		locale=$(basename "$mo" .mo)
		if [ ! -f "locales/po/$locale.po" ]; then
			echo "ERROR: Orphan compiled catalog $mo without locales/po/$locale.po" >&2
			rc=1
		fi
	done

	[ "$rc" -eq 0 ] && echo "All $po_count catalogs are complete and compiled"
	exit "$rc"
fi

generate "$POT"
echo "Updated $POT"

mkdir -p locales/LC_MESSAGES

for po in locales/po/*.po; do
	[ -f "$po" ] || continue
	msgmerge --quiet --update --no-wrap --backup=none "$po" "$POT"
	msgfmt --check -o "locales/LC_MESSAGES/$(basename "$po" .po).mo" "$po"
	echo "Compiled $po"
done
