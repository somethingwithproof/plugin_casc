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

/**
 * Hardened canonical XML envelope codec mapped 1:1 to the CascDocument IR.
 *
 * Rejects DOCTYPE and ENTITY declarations before libxml parses the text, loads
 * with LIBXML_NONET | LIBXML_NOBLANKS, verifies no doctype node exists, bounds
 * size and nesting depth, and preserves exact scalar and container types so
 * YAML -> IR -> XML -> IR -> YAML round-trips without loss.
 */
final class XmlCodec {
	public const MAX_BYTES = YamlReader::MAX_BYTES;
	public const MAX_DEPTH = YamlReader::MAX_DEPTH;
	public const ROOT      = 'cacti-casc';

	/** @var array<string, int> */
	private array $lineMap = [];

	/**
	 * Initialize an XML codec decoder bound to a maximum element nesting depth.
	 *
	 * @param int $maxDepth Maximum permitted element nesting depth.
	 * @param bool $fullLineMap Whether to populate lineMap for every nested path (true) or only schema paths (false).
	 */
	private function __construct(
		private readonly int $maxDepth,
		private readonly bool $fullLineMap = true,
	) {
	}

	/**
	 * Parse hardened canonical CasC XML into a line-indexed ParsedTree.
	 *
	 * @param string $text Raw XML document text.
	 * @param int $maxBytes Maximum allowed byte length.
	 * @param int $maxDepth Maximum allowed element nesting depth.
	 * @param bool $fullLineMap Whether to populate lineMap for every nested path (true) or only schema paths (false).
	 * @return ParsedTree Decoded tree with source line mapping.
	 */
	public static function parse(string $text, int $maxBytes = self::MAX_BYTES, int $maxDepth = self::MAX_DEPTH, bool $fullLineMap = true): ParsedTree {
		if ($maxDepth < 1) {
			throw new CodecException('Maximum nesting depth must be at least 1');
		}

		if (strlen($text) > $maxBytes) {
			throw new CodecException(sprintf('Document exceeds the %d byte limit', $maxBytes));
		}

		if (preg_match('//u', $text) !== 1) {
			throw new CodecException('Document is not valid UTF-8');
		}

		// Refuse DTD and entity declarations before libxml parses the buffer.
		if (preg_match('/<!(DOCTYPE|ENTITY)\b/i', $text) === 1) {
			throw new CodecException('DOCTYPE and ENTITY declarations are not allowed');
		}

		$previous = libxml_use_internal_errors(true);

		try {
			$dom = new \DOMDocument();

			if (!$dom->loadXML($text, LIBXML_NONET | LIBXML_NOBLANKS)) {
				$error = libxml_get_last_error();

				throw new CodecException(
					trim($error === false ? 'XML is not well formed' : $error->message),
					$error === false || $error->line < 1 ? null : $error->line
				);
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if ($dom->doctype !== null) {
			throw new CodecException('A DOCTYPE declaration is not allowed');
		}

		$root = $dom->documentElement;

		if ($root === null || $root->nodeName !== self::ROOT) {
			throw new CodecException(sprintf('Root element must be <%s>', self::ROOT), $root?->getLineNo());
		}

		return (new self($maxDepth, $fullLineMap))->decodeRoot($root);
	}

	/**
	 * Serialize a canonical CascDocument array into formatted canonical XML.
	 *
	 * @param array<string, mixed> $data Canonical CascDocument array.
	 * @param int $maxDepth Maximum allowed element nesting depth.
	 * @return string Canonical XML text.
	 */
	public static function write(array $data, int $maxDepth = self::MAX_DEPTH): string {
		$apiVersion = is_string($data['apiVersion'] ?? null) ? $data['apiVersion'] : CascDocument::API_VERSION;
		$kind       = is_string($data['kind'] ?? null) ? $data['kind'] : CascDocument::KIND;

		self::assertXml10Text($apiVersion);
		self::assertXml10Text($kind);

		$lines = [
			'<?xml version="1.0" encoding="UTF-8"?>',
			sprintf(
				'<%s apiVersion="%s" kind="%s">',
				self::ROOT,
				self::escapeAttr($apiVersion),
				self::escapeAttr($kind)
			),
		];

		$hasSections = false;

		foreach (['metadata', 'spec', 'reference'] as $section) {
			if (!isset($data[$section]) || !is_array($data[$section]) || $data[$section] === []) {
				continue;
			}

			$hasSections = true;
			$lines[]     = '  <' . $section . '>';
			self::writeMapEntries($lines, $data[$section], 2, $maxDepth);
			$lines[] = '  </' . $section . '>';
		}

		if (!$hasSections) {
			$lines[1] = sprintf(
				'<%s apiVersion="%s" kind="%s"/>',
				self::ROOT,
				self::escapeAttr($apiVersion),
				self::escapeAttr($kind)
			);
		} else {
			$lines[] = '</' . self::ROOT . '>';
		}

		return implode("\n", $lines) . "\n";
	}

	/**
	 * Append sorted <entry> lines for a map container onto $lines.
	 *
	 * @param list<string> $lines Output XML line accumulator.
	 * @param array<array-key, mixed> $map Associative map to serialize.
	 * @param int $depth Current XML nesting depth.
	 * @param int $maxDepth Maximum allowed XML nesting depth.
	 * @return void
	 */
	private static function writeMapEntries(array &$lines, array $map, int $depth, int $maxDepth): void {
		if ($depth > $maxDepth) {
			throw new CodecException(sprintf('Nesting is deeper than %d levels', $maxDepth));
		}

		ksort($map, SORT_STRING);

		$pad = str_repeat('  ', $depth);

		foreach ($map as $key => $value) {
			$keyStr = (string) $key;
			self::assertXml10Text($keyStr);
			self::writeTypedElement($lines, 'entry', ' key="' . self::escapeAttr($keyStr) . '"', $value, $depth + 1, $maxDepth, $pad);
		}
	}

	/**
	 * Append a typed <entry> or <item> element line block onto $lines.
	 *
	 * @param list<string> $lines Output XML line accumulator.
	 * @param string $tag Element tag name ('entry' or 'item').
	 * @param string $extraAttrs Pre-escaped leading attribute fragment (e.g. ' key="..."').
	 * @param mixed $value Scalar, null, list, or map value.
	 * @param int $depth Current XML nesting depth.
	 * @param int $maxDepth Maximum allowed XML nesting depth.
	 * @param string $pad Indentation string for this element.
	 * @return void
	 */
	private static function writeTypedElement(
		array &$lines,
		string $tag,
		string $extraAttrs,
		mixed $value,
		int $depth,
		int $maxDepth,
		string $pad,
	): void {
		if ($depth > $maxDepth) {
			throw new CodecException(sprintf('Nesting is deeper than %d levels', $maxDepth));
		}

		if ($value === null) {
			$lines[] = $pad . '<' . $tag . $extraAttrs . ' type="null"/>';

			return;
		}

		if (is_bool($value)) {
			$lines[] = $pad . '<' . $tag . $extraAttrs . ' type="bool">' . ($value ? 'true' : 'false') . '</' . $tag . '>';

			return;
		}

		if (is_int($value)) {
			$lines[] = $pad . '<' . $tag . $extraAttrs . ' type="int">' . $value . '</' . $tag . '>';

			return;
		}

		if (is_float($value)) {
			if (!is_finite($value)) {
				throw new CodecException('Non-finite floats cannot be written to XML');
			}

			$lines[] = $pad . '<' . $tag . $extraAttrs . ' type="float">' . json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . '</' . $tag . '>';

			return;
		}

		if (is_string($value)) {
			self::assertXml10Text($value);
			$lines[] = $pad . '<' . $tag . $extraAttrs . ' type="string">' . self::escapeText($value) . '</' . $tag . '>';

			return;
		}

		if (!is_array($value)) {
			throw new CodecException('Unsupported value type ' . get_debug_type($value));
		}

		if ($value === []) {
			throw new CodecException('Empty collections must be omitted before serialization');
		}

		if (array_is_list($value)) {
			$lines[]  = $pad . '<' . $tag . $extraAttrs . ' type="list">';
			$childPad = $pad . '  ';

			foreach ($value as $item) {
				self::writeTypedElement($lines, 'item', '', $item, $depth + 1, $maxDepth, $childPad);
			}

			$lines[] = $pad . '</' . $tag . '>';

			return;
		}

		$lines[] = $pad . '<' . $tag . $extraAttrs . ' type="map">';
		self::writeMapEntries($lines, $value, $depth, $maxDepth);
		$lines[] = $pad . '</' . $tag . '>';
	}

	/**
	 * Escape XML 1.0 attribute values including carriage return, newline, and tab.
	 *
	 * @param string $text Legal XML 1.0 text.
	 * @return string Escaped XML attribute payload.
	 */
	private static function escapeAttr(string $text): string {
		$escaped = htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');

		if (strpbrk($escaped, "\r\n\t") !== false) {
			return strtr($escaped, ["\r" => '&#13;', "\n" => '&#10;', "\t" => '&#9;']);
		}

		return $escaped;
	}

	/**
	 * Escape XML 1.0 text node payloads including carriage returns.
	 *
	 * @param string $text Legal XML 1.0 text.
	 * @return string Escaped XML text payload.
	 */
	private static function escapeText(string $text): string {
		$escaped = htmlspecialchars($text, ENT_XML1 | ENT_NOQUOTES, 'UTF-8');

		return str_contains($escaped, "\r") ? str_replace("\r", '&#13;', $escaped) : $escaped;
	}

	/**
	 * Verify that text contains only legal XML 1.0 character code points.
	 *
	 * @param string $text Candidate UTF-8 string.
	 * @return void
	 */
	private static function assertXml10Text(string $text): void {
		if (YamlScalar::isPrintableAscii($text)) {
			return;
		}

		if (preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $text) === 1) {
			throw new CodecException('A value contains a character XML 1.0 cannot encode');
		}
	}

	/**
	 * Decode the <cacti-casc> root element and its sections into a ParsedTree.
	 *
	 * @param \DOMElement $root Root <cacti-casc> DOM element.
	 * @return ParsedTree Decoded tree with line numbers.
	 */
	private function decodeRoot(\DOMElement $root): ParsedTree {
		if (1 > $this->maxDepth) {
			throw new CodecException(sprintf('Nesting is deeper than %d levels', $this->maxDepth), $root->getLineNo());
		}

		$data = [];

		foreach ($root->attributes as $attr) {
			if ($attr->nodeName !== 'apiVersion' && $attr->nodeName !== 'kind') {
				throw new CodecException(sprintf('Attribute "%s" is not allowed on <%s>', $attr->nodeName, self::ROOT), $root->getLineNo());
			}

			$data[$attr->nodeName]          = (string) $attr->nodeValue;
			$this->lineMap[$attr->nodeName] = $root->getLineNo();
		}

		foreach ($root->childNodes as $child) {
			if (!$child instanceof \DOMElement) {
				$this->assertValidNonElementChild($child, $root->nodeName);

				continue;
			}

			if (2 > $this->maxDepth) {
				throw new CodecException(sprintf('Nesting is deeper than %d levels', $this->maxDepth), $child->getLineNo());
			}

			$name = $child->nodeName;

			if ($name !== 'metadata' && $name !== 'spec' && $name !== 'reference') {
				throw new CodecException(sprintf('Unexpected section <%s> inside <%s>', $name, self::ROOT), $child->getLineNo());
			}

			if (array_key_exists($name, $data)) {
				throw new CodecException(sprintf('Duplicate section <%s>', $name), $child->getLineNo());
			}

			$this->lineMap[$name] = $child->getLineNo();
			$data[$name]          = $this->decodeMap($child, $name, 2);
		}

		return new ParsedTree($data, $this->lineMap);
	}

	/**
	 * Decode a map container element composed of uniquely keyed <entry> children.
	 *
	 * @param \DOMElement $parent Map container DOM element.
	 * @param string $path Slash-delimited path for line map tracking.
	 * @param int $depth Current element nesting depth of $parent.
	 * @return array<string, mixed>
	 */
	private function decodeMap(\DOMElement $parent, string $path, int $depth): array {
		$map        = [];
		$childDepth = $depth + 1;

		foreach ($parent->childNodes as $child) {
			if (!$child instanceof \DOMElement) {
				$this->assertValidNonElementChild($child, $parent->nodeName);

				continue;
			}

			if ($childDepth > $this->maxDepth) {
				throw new CodecException(sprintf('Nesting is deeper than %d levels', $this->maxDepth), $child->getLineNo());
			}

			if ($child->nodeName !== 'entry') {
				throw new CodecException(sprintf('Expected <entry>, found <%s>', $child->nodeName), $child->getLineNo());
			}

			if (!$child->hasAttribute('key') || !$child->hasAttribute('type')) {
				throw new CodecException('<entry> requires "key" and "type" attributes', $child->getLineNo());
			}

			if ($child->attributes->length !== 2) {
				foreach ($child->attributes as $attr) {
					if ($attr->nodeName !== 'key' && $attr->nodeName !== 'type') {
						throw new CodecException(sprintf('Attribute "%s" is not allowed on <entry>', $attr->nodeName), $child->getLineNo());
					}
				}
			}

			$key = $child->getAttribute('key');

			if ($key === '') {
				throw new CodecException('An empty key is not allowed', $child->getLineNo());
			}

			if (array_key_exists($key, $map)) {
				throw new CodecException(sprintf('Duplicate key "%s"', $key), $child->getLineNo());
			}

			if ($this->fullLineMap || $childDepth <= 3 || ($childDepth === 5 && $key === 'id')) {
				$sub                 = $path . '/' . $key;
				$this->lineMap[$sub] = $child->getLineNo();
			} else {
				$sub = '';
			}

			$map[$key] = $this->decodeValue($child, $sub, $childDepth);
		}

		return $map;
	}

	/**
	 * Decode a typed <entry> or <item> element according to its type attribute.
	 *
	 * @param \DOMElement $element Typed DOM element.
	 * @param string $path Slash-delimited path for line map tracking.
	 * @param int $depth Current element nesting depth of $element.
	 * @return mixed Decoded PHP value.
	 */
	private function decodeValue(\DOMElement $element, string $path, int $depth): mixed {
		$type = $element->getAttribute('type');

		return match ($type) {
			'null'   => $this->decodeNull($element),
			'bool'   => $this->decodeBool($element),
			'int'    => $this->decodeInt($element),
			'float'  => $this->decodeFloat($element),
			'string' => $this->decodeLeafText($element),
			'map'    => $this->decodeMap($element, $path, $depth),
			'list'   => $this->decodeList($element, $path, $depth),
			default  => throw new CodecException(sprintf('Unknown type "%s" on <%s>', $type, $element->nodeName), $element->getLineNo()),
		};
	}

	/**
	 * Decode a list container element composed of typed <item> children.
	 *
	 * @param \DOMElement $parent List container DOM element.
	 * @param string $path Slash-delimited path for line map tracking.
	 * @param int $depth Current element nesting depth of $parent.
	 * @return list<mixed>
	 */
	private function decodeList(\DOMElement $parent, string $path, int $depth): array {
		$list       = [];
		$childDepth = $depth + 1;

		foreach ($parent->childNodes as $child) {
			if (!$child instanceof \DOMElement) {
				$this->assertValidNonElementChild($child, $parent->nodeName);

				continue;
			}

			if ($childDepth > $this->maxDepth) {
				throw new CodecException(sprintf('Nesting is deeper than %d levels', $this->maxDepth), $child->getLineNo());
			}

			if ($child->nodeName !== 'item') {
				throw new CodecException(sprintf('Expected <item>, found <%s>', $child->nodeName), $child->getLineNo());
			}

			if (!$child->hasAttribute('type')) {
				throw new CodecException('<item> requires a "type" attribute', $child->getLineNo());
			}

			if ($child->attributes->length !== 1) {
				foreach ($child->attributes as $attr) {
					if ($attr->nodeName !== 'type') {
						throw new CodecException(sprintf('Attribute "%s" is not allowed on <item>', $attr->nodeName), $child->getLineNo());
					}
				}
			}

			if ($this->fullLineMap || $childDepth <= 4) {
				$sub                 = $path . '/' . count($list);
				$this->lineMap[$sub] = $child->getLineNo();
			} else {
				$sub = '';
			}

			$list[] = $this->decodeValue($child, $sub, $childDepth);
		}

		return $list;
	}

	/**
	 * Decode a null-typed element verifying it has no text payload.
	 *
	 * @param \DOMElement $element Element with type="null".
	 * @return null
	 */
	private function decodeNull(\DOMElement $element): null {
		if ($this->decodeLeafText($element) !== '') {
			throw new CodecException('A null element must be empty', $element->getLineNo());
		}

		return null;
	}

	/**
	 * Decode a strict boolean element ("true" or "false").
	 *
	 * @param \DOMElement $element Element with type="bool".
	 * @return bool Decoded boolean value.
	 */
	private function decodeBool(\DOMElement $element): bool {
		$text = $this->decodeLeafText($element);

		if ($text === 'true') {
			return true;
		}

		if ($text === 'false') {
			return false;
		}

		throw new CodecException(sprintf('Invalid boolean value "%s"', $text), $element->getLineNo());
	}

	/**
	 * Decode a canonical base-10 integer element.
	 *
	 * @param \DOMElement $element Element with type="int".
	 * @return int Decoded integer value.
	 */
	private function decodeInt(\DOMElement $element): int {
		$text = $this->decodeLeafText($element);

		if (preg_match('/^(0|-?[1-9][0-9]{0,17})$/D', $text) !== 1) {
			throw new CodecException(sprintf('Invalid integer value "%s"', $text), $element->getLineNo());
		}

		return (int) $text;
	}

	/**
	 * Decode a canonical decimal float element.
	 *
	 * @param \DOMElement $element Element with type="float".
	 * @return float Decoded float value.
	 */
	private function decodeFloat(\DOMElement $element): float {
		$text = $this->decodeLeafText($element);

		if (preg_match('/^-?(0|[1-9][0-9]{0,14})\.[0-9]{1,12}$/D', $text) !== 1) {
			throw new CodecException(sprintf('Invalid float value "%s"', $text), $element->getLineNo());
		}

		return (float) $text;
	}

	/**
	 * Concatenate leaf text nodes inside a scalar element while rejecting child elements.
	 *
	 * @param \DOMElement $element Scalar DOM element.
	 * @return string Concatenated text content.
	 */
	private function decodeLeafText(\DOMElement $element): string {
		$out = '';

		foreach ($element->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				throw new CodecException(sprintf('Scalar <%s> cannot contain child elements', $element->nodeName), $child->getLineNo());
			}

			if ($child instanceof \DOMText) {
				$out .= $child->data;
			} elseif (!$child instanceof \DOMComment) {
				throw new CodecException('Only text and comments are allowed inside scalar elements', $child->getLineNo());
			}
		}

		return $out;
	}

	/**
	 * Reject unexpected non-whitespace text or non-comment nodes inside container elements.
	 *
	 * @param \DOMNode $child Non-element child node.
	 * @param string $parentName Parent element tag name for diagnostics.
	 * @return void
	 */
	private function assertValidNonElementChild(\DOMNode $child, string $parentName): void {
		if ($child instanceof \DOMText) {
			if (trim($child->data) !== '') {
				throw new CodecException(sprintf('Unexpected text inside <%s>', $parentName), $child->getLineNo());
			}

			return;
		}

		if (!$child instanceof \DOMComment) {
			throw new CodecException('Only elements and comments are allowed here', $child->getLineNo());
		}
	}
}
