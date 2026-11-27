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

namespace Somethingwithproof\Casc\Codec;

use Somethingwithproof\Casc\Model\CascDocument;
use Somethingwithproof\Casc\Model\DocumentSchema;

/**
 * Unified dual-format codec (YAML 1.2 + canonical XML) into CascDocument IR.
 */
final class CascCodec {
	public const YAML      = 'yaml';
	public const XML       = 'xml';
	public const MAX_BYTES = YamlReader::MAX_BYTES;

	/**
	 * Check whether a format name is a supported manifest codec format.
	 *
	 * @param string $format Candidate format identifier.
	 * @return bool True for 'yaml' or 'xml'.
	 */
	public static function isFormat(string $format): bool {
		return $format === self::YAML || $format === self::XML;
	}

	/**
	 * Infer manifest serialization format from a filename extension.
	 *
	 * @param string $path Manifest file path.
	 * @return string Detected format ('xml' or 'yaml').
	 */
	public static function detectFormatFromPath(string $path): string {
		$lower = strtolower($path);

		if (str_ends_with($lower, '.xml')) {
			return self::XML;
		}

		return self::YAML;
	}

	/**
	 * Infer manifest serialization format from leading content bytes.
	 *
	 * @param string $text Raw manifest document text.
	 * @return string Detected format ('xml' or 'yaml').
	 */
	public static function detectFormatFromContent(string $text): string {
		$trimmed = ltrim($text, "\xEF\xBB\xBF \t\r\n");

		if (str_starts_with($trimmed, '<')) {
			return self::XML;
		}

		return self::YAML;
	}

	/**
	 * Decode YAML or XML manifest text into a validated CascDocument IR.
	 *
	 * @param string $format Source format ('yaml' or 'xml').
	 * @param string $text Raw manifest text.
	 * @return CascDocument Validated manifest document.
	 */
	public static function parse(string $format, string $text): CascDocument {
		$tree = match ($format) {
			self::YAML => YamlReader::parse($text, self::MAX_BYTES),
			self::XML  => XmlCodec::parse($text, self::MAX_BYTES),
			default    => throw new CodecException(sprintf('Unknown format "%s"; use yaml or xml', $format)),
		};

		return DocumentSchema::build($tree);
	}

	/**
	 * Serialize a CascDocument IR into canonical YAML or XML text.
	 *
	 * @param string $format Target format ('yaml' or 'xml').
	 * @param CascDocument $document Canonical document IR.
	 * @return string Serialized manifest string.
	 */
	public static function write(string $format, CascDocument $document): string {
		$data = $document->toCanonicalArray();

		return match ($format) {
			self::YAML => YamlWriter::write($data, YamlWriter::MAX_DEPTH, false),
			self::XML  => XmlCodec::write($data),
			default    => throw new CodecException(sprintf('Unknown format "%s"; use yaml or xml', $format)),
		};
	}

	/**
	 * Read a manifest file from disk enforcing readability and MAX_BYTES limits.
	 *
	 * @param string $path Filesystem path to read.
	 * @return string Raw file contents within MAX_BYTES.
	 */
	public static function readFile(string $path): string {
		if (!is_file($path) || !is_readable($path)) {
			throw new CodecException(sprintf('Cannot read file "%s"', $path));
		}

		$size = filesize($path);

		if ($size === false || $size > self::MAX_BYTES) {
			throw new CodecException(sprintf('"%s" exceeds the %d byte limit', $path, self::MAX_BYTES));
		}

		$text = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);

		if ($text === false || strlen($text) > self::MAX_BYTES) {
			throw new CodecException(sprintf('Cannot read "%s" within the %d byte limit', $path, self::MAX_BYTES));
		}

		return $text;
	}

	/**
	 * Read manifest contents from an open PHP stream within MAX_BYTES limits.
	 *
	 * @param resource $stream Open readable stream resource.
	 * @return string Raw stream contents within MAX_BYTES.
	 */
	public static function readStream($stream): string {
		$text = stream_get_contents($stream, self::MAX_BYTES + 1);

		if ($text === false || strlen($text) > self::MAX_BYTES) {
			throw new CodecException(sprintf('Input exceeds the %d byte limit', self::MAX_BYTES));
		}

		return $text;
	}
}
