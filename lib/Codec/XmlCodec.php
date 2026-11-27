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
	 * Parse hardened canonical CasC XML into a line-indexed ParsedTree.
	 *
	 * @param string $text Raw XML document text.
	 * @param int $maxBytes Maximum allowed byte length.
	 * @param int $maxDepth Maximum allowed element nesting depth.
	 * @return ParsedTree Decoded tree with source line mapping.
	 */
	public static function parse(string $text, int $maxBytes = self::MAX_BYTES, int $maxDepth = self::MAX_DEPTH): ParsedTree {
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

		self::checkDepth($root, 1, $maxDepth);

		return (new self())->decodeRoot($root);
	}

	/**
	 * Serialize a canonical CascDocument array into formatted canonical XML.
	 *
	 * @param array<string, mixed> $data Canonical CascDocument array.
	 * @param int $maxDepth Maximum allowed element nesting depth.
	 * @return string Canonical XML text.
	 */
	public static function write(array $data, int $maxDepth = self::MAX_DEPTH): string {
		$dom               = new \DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;

		$root = $dom->createElement(self::ROOT);
		$dom->appendChild($root);

		$apiVersion = is_string($data['apiVersion'] ?? null) ? $data['apiVersion'] : CascDocument::API_VERSION;
		$kind       = is_string($data['kind'] ?? null) ? $data['kind'] : CascDocument::KIND;

		self::assertXml10Text($apiVersion);
		self::assertXml10Text($kind);

		$root->setAttribute('apiVersion', $apiVersion);
		$root->setAttribute('kind', $kind);

		foreach (['metadata', 'spec', 'reference'] as $section) {
			if (!isset($data[$section]) || !is_array($data[$section]) || $data[$section] === []) {
				continue;
			}

			$sectionEl = $dom->createElement($section);
			$root->appendChild($sectionEl);
			self::writeMapEntries($dom, $sectionEl, $data[$section], 2, $maxDepth);
		}

		$xml = $dom->saveXML();

		if ($xml === false) {
			throw new CodecException('Failed to serialize canonical XML');
		}

		return $xml;
	}

	/**
	 * Append sorted <entry> elements for a map container onto $parent.
	 *
	 * @param \DOMDocument $dom Owning DOM document.
	 * @param \DOMElement $parent Parent element receiving map entries.
	 * @param array<array-key, mixed> $map Associative map to serialize.
	 * @param int $depth Current XML nesting depth.
	 * @param int $maxDepth Maximum allowed XML nesting depth.
	 * @return void
	 */
	private static function writeMapEntries(\DOMDocument $dom, \DOMElement $parent, array $map, int $depth, int $maxDepth): void {
		if ($depth > $maxDepth) {
			throw new CodecException(sprintf('Nesting is deeper than %d levels', $maxDepth));
		}

		ksort($map, SORT_STRING);

		foreach ($map as $key => $value) {
			$keyStr = (string) $key;
			self::assertXml10Text($keyStr);

			$entry = $dom->createElement('entry');
			$entry->setAttribute('key', $keyStr);
			self::writeTypedValue($dom, $entry, $value, $depth + 1, $maxDepth);
			$parent->appendChild($entry);
		}
	}

	/**
	 * Populate type attribute and child text or container nodes for a value.
	 *
	 * @param \DOMDocument $dom Owning DOM document.
	 * @param \DOMElement $element Element receiving type attribute and value content.
	 * @param mixed $value Scalar, null, list, or map value.
	 * @param int $depth Current XML nesting depth.
	 * @param int $maxDepth Maximum allowed XML nesting depth.
	 * @return void
	 */
	private static function writeTypedValue(\DOMDocument $dom, \DOMElement $element, mixed $value, int $depth, int $maxDepth): void {
		if ($depth > $maxDepth) {
			throw new CodecException(sprintf('Nesting is deeper than %d levels', $maxDepth));
		}

		if ($value === null) {
			$element->setAttribute('type', 'null');

			return;
		}

		if (is_bool($value)) {
			$element->setAttribute('type', 'bool');
			$element->appendChild($dom->createTextNode($value ? 'true' : 'false'));

			return;
		}

		if (is_int($value)) {
			$element->setAttribute('type', 'int');
			$element->appendChild($dom->createTextNode((string) $value));

			return;
		}

		if (is_float($value)) {
			if (!is_finite($value)) {
				throw new CodecException('Non-finite floats cannot be written to XML');
			}

			$element->setAttribute('type', 'float');
			$element->appendChild($dom->createTextNode(json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)));

			return;
		}

		if (is_string($value)) {
			self::assertXml10Text($value);
			$element->setAttribute('type', 'string');
			$element->appendChild($dom->createTextNode($value));

			return;
		}

		if (!is_array($value)) {
			throw new CodecException('Unsupported value type ' . get_debug_type($value));
		}

		if ($value === []) {
			throw new CodecException('Empty collections must be omitted before serialization');
		}

		if (array_is_list($value)) {
			$element->setAttribute('type', 'list');

			foreach ($value as $item) {
				$itemEl = $dom->createElement('item');
				self::writeTypedValue($dom, $itemEl, $item, $depth + 1, $maxDepth);
				$element->appendChild($itemEl);
			}

			return;
		}

		$element->setAttribute('type', 'map');
		self::writeMapEntries($dom, $element, $value, $depth, $maxDepth);
	}

	/**
	 * Verify that text contains only legal XML 1.0 character code points.
	 *
	 * @param string $text Candidate UTF-8 string.
	 * @return void
	 */
	private static function assertXml10Text(string $text): void {
		if (preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $text) === 1) {
			throw new CodecException('A value contains a character XML 1.0 cannot encode');
		}
	}

	/**
	 * Recursively enforce maximum XML element nesting depth.
	 *
	 * @param \DOMNode $node Current DOM node.
	 * @param int $depth Current depth level.
	 * @param int $max Maximum allowed depth level.
	 * @return void
	 */
	private static function checkDepth(\DOMNode $node, int $depth, int $max): void {
		if ($depth > $max) {
			throw new CodecException(sprintf('Nesting is deeper than %d levels', $max), $node->getLineNo());
		}

		foreach ($node->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				self::checkDepth($child, $depth + 1, $max);
			}
		}
	}

	/**
	 * Decode the <cacti-casc> root element and its sections into a ParsedTree.
	 *
	 * @param \DOMElement $root Root <cacti-casc> DOM element.
	 * @return ParsedTree Decoded tree with line numbers.
	 */
	private function decodeRoot(\DOMElement $root): ParsedTree {
		$data = [];

		foreach ($root->attributes as $attr) {
			if (!in_array($attr->nodeName, ['apiVersion', 'kind'], true)) {
				throw new CodecException(sprintf('Attribute "%s" is not allowed on <%s>', $attr->nodeName, self::ROOT), $root->getLineNo());
			}

			$data[$attr->nodeName]          = (string) $attr->nodeValue;
			$this->lineMap[$attr->nodeName] = $root->getLineNo();
		}

		foreach ($this->childElements($root) as $child) {
			$name = $child->nodeName;

			if (!in_array($name, ['metadata', 'spec', 'reference'], true)) {
				throw new CodecException(sprintf('Unexpected section <%s> inside <%s>', $name, self::ROOT), $child->getLineNo());
			}

			if (array_key_exists($name, $data)) {
				throw new CodecException(sprintf('Duplicate section <%s>', $name), $child->getLineNo());
			}

			$this->lineMap[$name] = $child->getLineNo();
			$data[$name]          = $this->decodeMap($child, $name);
		}

		return new ParsedTree($data, $this->lineMap);
	}

	/**
	 * Decode a map container element composed of uniquely keyed <entry> children.
	 *
	 * @param \DOMElement $parent Map container DOM element.
	 * @param string $path Slash-delimited path for line map tracking.
	 * @return array<string, mixed>
	 */
	private function decodeMap(\DOMElement $parent, string $path): array {
		$map = [];

		foreach ($this->childElements($parent) as $child) {
			if ($child->nodeName !== 'entry') {
				throw new CodecException(sprintf('Expected <entry>, found <%s>', $child->nodeName), $child->getLineNo());
			}

			if (!$child->hasAttribute('key') || !$child->hasAttribute('type')) {
				throw new CodecException('<entry> requires "key" and "type" attributes', $child->getLineNo());
			}

			foreach ($child->attributes as $attr) {
				if (!in_array($attr->nodeName, ['key', 'type'], true)) {
					throw new CodecException(sprintf('Attribute "%s" is not allowed on <entry>', $attr->nodeName), $child->getLineNo());
				}
			}

			$key = $child->getAttribute('key');

			if ($key === '') {
				throw new CodecException('An empty key is not allowed', $child->getLineNo());
			}

			if (array_key_exists($key, $map)) {
				throw new CodecException(sprintf('Duplicate key "%s"', $key), $child->getLineNo());
			}

			$sub                 = $path . '/' . $key;
			$this->lineMap[$sub] = $child->getLineNo();
			$map[$key]           = $this->decodeValue($child, $sub);
		}

		return $map;
	}

	/**
	 * Decode a typed <entry> or <item> element according to its type attribute.
	 *
	 * @param \DOMElement $element Typed DOM element.
	 * @param string $path Slash-delimited path for line map tracking.
	 * @return mixed Decoded PHP value.
	 */
	private function decodeValue(\DOMElement $element, string $path): mixed {
		$type = $element->getAttribute('type');

		return match ($type) {
			'null'   => $this->decodeNull($element),
			'bool'   => $this->decodeBool($element),
			'int'    => $this->decodeInt($element),
			'float'  => $this->decodeFloat($element),
			'string' => $this->decodeLeafText($element),
			'map'    => $this->decodeMap($element, $path),
			'list'   => $this->decodeList($element, $path),
			default  => throw new CodecException(sprintf('Unknown type "%s" on <%s>', $type, $element->nodeName), $element->getLineNo()),
		};
	}

	/**
	 * Decode a list container element composed of typed <item> children.
	 *
	 * @param \DOMElement $parent List container DOM element.
	 * @param string $path Slash-delimited path for line map tracking.
	 * @return list<mixed>
	 */
	private function decodeList(\DOMElement $parent, string $path): array {
		$list = [];

		foreach ($this->childElements($parent) as $child) {
			if ($child->nodeName !== 'item') {
				throw new CodecException(sprintf('Expected <item>, found <%s>', $child->nodeName), $child->getLineNo());
			}

			if (!$child->hasAttribute('type')) {
				throw new CodecException('<item> requires a "type" attribute', $child->getLineNo());
			}

			foreach ($child->attributes as $attr) {
				if ($attr->nodeName !== 'type') {
					throw new CodecException(sprintf('Attribute "%s" is not allowed on <item>', $attr->nodeName), $child->getLineNo());
				}
			}

			$sub                 = $path . '/' . count($list);
			$this->lineMap[$sub] = $child->getLineNo();
			$list[]              = $this->decodeValue($child, $sub);
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
	 * Return direct child DOM elements while rejecting unexpected non-whitespace text nodes.
	 *
	 * @param \DOMElement $parent Container DOM element.
	 * @return list<\DOMElement>
	 */
	private function childElements(\DOMElement $parent): array {
		$elements = [];

		foreach ($parent->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				$elements[] = $child;
			} elseif ($child instanceof \DOMText && trim($child->data) !== '') {
				throw new CodecException(sprintf('Unexpected text inside <%s>', $parent->nodeName), $child->getLineNo());
			} elseif (!$child instanceof \DOMText && !$child instanceof \DOMComment) {
				throw new CodecException('Only elements and comments are allowed here', $child->getLineNo());
			}
		}

		return $elements;
	}
}
