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

/**
 * Zero-dependency strict-subset YAML 1.2 reader with JSON-form YAML fallback.
 *
 * Block YAML supports maps, sequences, plain/single-quoted/double-quoted
 * scalars, comments, one optional leading "---", and "|" or "|-" literal
 * blocks. Anchors, aliases, tags, flow collections, folded blocks, merge keys,
 * complex keys, duplicate keys, directives, multiple documents, tab
 * indentation, and ambiguous YAML 1.1 plain scalars are rejected with
 * line-numbered errors.
 *
 * JSON-form YAML documents (whose first non-whitespace byte is "{" or "[")
 * are parsed through json_decode after size, UTF-8, nesting depth, and
 * duplicate-key verification.
 */
final class YamlReader {
	public const MAX_BYTES = 8388608;
	public const MAX_DEPTH = 16;
	public const MAX_LINE  = 16384;

	private const CONTROL_CHAR_REGEX = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}\x{2028}\x{2029}\x{FEFF}\x{FFFE}\x{FFFF}]/u';

	private const SIMPLE_ESCAPES = [
		'\\' => '\\',
		'"'  => '"',
		'/'  => '/',
		'n'  => "\n",
		't'  => "\t",
		'r'  => "\r",
		'0'  => "\0",
		'b'  => "\x08",
		'f'  => "\x0C",
		'e'  => "\x1B",
	];

	/** @var array<int, string> */
	private array $raw = [];
	private int $rawCount = 0;
	private int $pos = 0;
	private int $peekCachePos = -1;
	/** @var array{0: int, 1: int, 2: string}|null */
	private ?array $peekCache = null;
	/** @var array{0: string, 1: string}|null */
	private ?array $prefetchedSplit = null;
	private int $prefetchedSplitLine = -1;
	/** @var array<string, int> */
	private array $lineMap = [];

	/**
	 * Initialize a block YAML reader bound to a maximum nesting depth.
	 *
	 * @param int $maxDepth Maximum permitted container nesting depth.
	 * @param bool $fullLineMap Whether to index line numbers for every leaf path or only schema-checked paths.
	 */
	private function __construct(
		private readonly int $maxDepth,
		private readonly bool $fullLineMap = true,
	) {
	}

	/**
	 * Parse strict-subset YAML 1.2 or JSON-form YAML into a line-indexed ParsedTree.
	 *
	 * @param string $text Raw YAML document text.
	 * @param int $maxBytes Maximum allowed document byte size.
	 * @param int $maxDepth Maximum allowed nesting depth.
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

		if (str_starts_with($text, "\xEF\xBB\xBF")) {
			$text = substr($text, 3);
		}

		if (preg_match('//u', $text) !== 1) {
			throw new CodecException('Document is not valid UTF-8');
		}

		$trimmed = ltrim($text, " \t\r\n");

		if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
			return self::parseJsonForm($text, $maxDepth);
		}

		return (new self($maxDepth, $fullLineMap))->runBlock($text);
	}

	/**
	 * Parse JSON-form YAML after checking nesting depth and duplicate keys.
	 *
	 * @param string $text Raw JSON-form YAML text.
	 * @param int $maxDepth Maximum allowed nesting depth.
	 * @return ParsedTree Decoded document tree.
	 */
	private static function parseJsonForm(string $text, int $maxDepth): ParsedTree {
		self::assertNoJsonDuplicateKeysAndDepth($text, $maxDepth);

		$jsonDepth = max(1, $maxDepth + 2);

		try {
			$decoded = json_decode($text, true, $jsonDepth, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new CodecException('Invalid JSON-form YAML: ' . $e->getMessage(), null, [], $e);
		}

		if (!is_array($decoded)) {
			throw new CodecException('Document root must be a map or list');
		}

		return new ParsedTree($decoded, []);
	}

	/**
	 * Lightweight lexical scan of JSON-form YAML to reject duplicate object
	 * keys and enforce nesting depth before json_decode silently overwrites.
	 *
	 * @param string $text Raw JSON text to scan.
	 * @param int $maxDepth Maximum permitted container nesting depth.
	 * @return void
	 */
	private static function assertNoJsonDuplicateKeysAndDepth(string $text, int $maxDepth): void {
		$len   = strlen($text);
		$i     = 0;
		$line  = 1;
		/** @var list<array{type: string, keys: array<string, int>, expectingKey: bool}> */
		$stack = [];

		while ($i < $len) {
			$skip = strcspn($text, "\"{}[],:\n", $i);
			$i   += $skip;

			if ($i >= $len) {
				break;
			}

			$ch = $text[$i];

			if ($ch === "\n") {
				$line++;
				$i++;

				continue;
			}

			if ($ch === '{') {
				$depth = count($stack) + 1;

				if ($depth > $maxDepth) {
					throw new CodecException(sprintf('Nesting is deeper than %d levels', $maxDepth), $line);
				}

				$stack[] = ['type' => 'object', 'keys' => [], 'expectingKey' => true];
				$i++;

				continue;
			}

			if ($ch === '[') {
				$depth = count($stack) + 1;

				if ($depth > $maxDepth) {
					throw new CodecException(sprintf('Nesting is deeper than %d levels', $maxDepth), $line);
				}

				$stack[] = ['type' => 'array', 'keys' => [], 'expectingKey' => false];
				$i++;

				continue;
			}

			if ($ch === '}' || $ch === ']') {
				array_pop($stack);
				$i++;

				continue;
			}

			if ($ch === ',') {
				$top = count($stack) - 1;

				if ($top >= 0 && $stack[$top]['type'] === 'object') {
					$stack[$top]['expectingKey'] = true;
				}

				$i++;

				continue;
			}

			if ($ch === ':') {
				$top = count($stack) - 1;

				if ($top >= 0 && $stack[$top]['type'] === 'object') {
					$stack[$top]['expectingKey'] = false;
				}

				$i++;

				continue;
			}

			if ($ch === '"') {
				$start      = $i;
				$hasEscape  = false;
				$i++;

				while ($i < $len) {
					$i += strcspn($text, "\"\\\n", $i);

					if ($i >= $len) {
						break;
					}

					$sc = $text[$i];

					if ($sc === '\\') {
						$hasEscape = true;
						$i        += 2;

						continue;
					}

					if ($sc === '"') {
						$i++;

						break;
					}

					$line++;
					$i++;
				}

				$top = count($stack) - 1;

				if ($top >= 0 && $stack[$top]['type'] === 'object' && $stack[$top]['expectingKey']) {
					if ($hasEscape) {
						$decodedKey = json_decode(substr($text, $start, $i - $start), true);
					} else {
						$decodedKey = substr($text, $start + 1, max(0, $i - $start - 2));
					}

					if (is_string($decodedKey)) {
						if (isset($stack[$top]['keys'][$decodedKey])) {
							throw new CodecException(sprintf('Duplicate key "%s" (first on line %d)', $decodedKey, $stack[$top]['keys'][$decodedKey]), $line);
						}

						$stack[$top]['keys'][$decodedKey] = $line;
					}

					$stack[$top]['expectingKey'] = false;
				}

				continue;
			}

			$i++;
		}
	}

	/**
	 * Tokenize lines and parse a block-style YAML 1.2 document.
	 *
	 * @param string $text Normalized UTF-8 block YAML text.
	 * @return ParsedTree Decoded tree with path-to-line mapping.
	 */
	private function runBlock(string $text): ParsedTree {
		$text = str_replace("\r\n", "\n", $text);

		if (str_contains($text, "\r")) {
			throw new CodecException('A carriage return outside a line ending is not allowed');
		}

		$this->raw      = explode("\n", $text);
		$this->rawCount = count($this->raw);

		// Fast path: scan the full document once for forbidden control/noncharacter
		// code points, falling back to per-line scan only on error to report the line.
		$hasControl = preg_match(self::CONTROL_CHAR_REGEX, $text) === 1;

		foreach ($this->raw as $i => $line) {
			if (strlen($line) > self::MAX_LINE) {
				throw new CodecException(sprintf('Line exceeds the %d byte limit', self::MAX_LINE), $i + 1);
			}

			if ($hasControl && preg_match(self::CONTROL_CHAR_REGEX, $line) === 1) {
				throw new CodecException('Control or noncharacter code point is not allowed; write it as an escape in a double-quoted scalar', $i + 1);
			}
		}

		$first = $this->peek(true);

		if ($first !== null && $first[2] === '---') {
			$this->pos++;
			$this->peekCachePos = -1;
		}

		$cur = $this->peek();

		if ($cur === null) {
			return new ParsedTree([], []);
		}

		$data = $this->block($cur[1], '', 1);

		$extra = $this->peek();

		if ($extra !== null) {
			throw new CodecException('Unexpected content after the end of the document', $extra[0]);
		}

		if (!is_array($data)) {
			throw new CodecException('Document root must be a map or list', $cur[0]);
		}

		return new ParsedTree($data, $this->lineMap);
	}

	/**
	 * Inspect the next non-blank, non-comment line without consuming it.
	 *
	 * @param bool $allowMarker Whether a leading "---" document marker is permitted.
	 * @return array{0: int, 1: int, 2: string}|null Tuple of [lineNo, indent, trimmedText] or null at EOF.
	 */
	private function peek(bool $allowMarker = false): ?array {
		if (!$allowMarker && $this->peekCachePos === $this->pos) {
			return $this->peekCache;
		}

		while ($this->pos < $this->rawCount) {
			$line = rtrim($this->raw[$this->pos], ' ');
			$trim = ltrim($line, ' ');

			if ($trim === '' || $trim[0] === '#' || trim($trim, "\t") === '') {
				$this->pos++;

				continue;
			}

			$indent = strlen($line) - strlen($trim);
			$no     = $this->pos + 1;

			if ($trim[0] === "\t") {
				throw new CodecException('A tab cannot be used for indentation', $no);
			}

			if ($indent === 0 && ($trim[0] === '-' || $trim[0] === '.') && preg_match('/^(---|\.\.\.)(\s|$)/', $trim) === 1 && (!$allowMarker || $trim !== '---')) {
				throw new CodecException('Only one document is supported; a document marker was found', $no);
			}

			if ($indent === 0 && $trim[0] === '%') {
				throw new CodecException('Directives are not supported', $no);
			}

			$entry = [$no, $indent, $trim];

			if (!$allowMarker) {
				$this->peekCachePos = $this->pos;
				$this->peekCache    = $entry;
			}

			return $entry;
		}

		if (!$allowMarker) {
			$this->peekCachePos = $this->pos;
			$this->peekCache    = null;
		}

		return null;
	}

	/**
	 * Parse either a sequence or map block starting at the given indentation.
	 *
	 * @param int $indent Expected indentation level in spaces.
	 * @param string $path Slash-delimited path prefix.
	 * @param int $depth Current nesting depth.
	 * @return mixed Parsed container or null when empty.
	 */
	private function block(int $indent, string $path, int $depth): mixed {
		$cur = $this->peek();

		if ($cur === null) {
			return null;
		}

		return self::isItem($cur[2]) ? $this->sequence($indent, $path, $depth) : $this->map($indent, $path, $depth);
	}

	/**
	 * Reject nesting deeper than $this->maxDepth.
	 *
	 * @param int $depth Current nesting depth.
	 * @return void
	 */
	private function guardDepth(int $depth): void {
		if ($depth > $this->maxDepth) {
			throw new CodecException(sprintf('Nesting is deeper than %d levels', $this->maxDepth), $this->pos + 1);
		}
	}

	/**
	 * Determine whether trimmed line text begins a YAML sequence item.
	 *
	 * @param string $text Trimmed line content.
	 * @return bool True for "-" or "- ...".
	 */
	private static function isItem(string $text): bool {
		return $text === '-' || str_starts_with($text, '- ');
	}

	/**
	 * Parse a block mapping at the specified indentation level.
	 *
	 * @param int $indent Map indentation level in spaces.
	 * @param string $path Slash-delimited path prefix.
	 * @param int $depth Current nesting depth.
	 * @return array<array-key, mixed>
	 */
	private function map(int $indent, string $path, int $depth): array {
		$this->guardDepth($depth);

		$result = [];
		$seen   = [];

		while (($cur = $this->peek()) !== null) {
			[$no, $ind, $text] = $cur;

			if ($ind < $indent) {
				break;
			}

			if ($ind > $indent) {
				throw new CodecException('Unexpected indentation', $no);
			}

			if (self::isItem($text)) {
				throw new CodecException('A list item is not allowed where a key is expected', $no);
			}

			if ($text === '?' || str_starts_with($text, '? ')) {
				throw new CodecException('Complex keys are not supported', $no);
			}

			if ($this->prefetchedSplitLine === $no && $this->prefetchedSplit !== null) {
				$split                     = $this->prefetchedSplit;
				$this->prefetchedSplit     = null;
				$this->prefetchedSplitLine = -1;
			} else {
				$split = $this->splitKey($text, $no);
			}

			if ($split === null) {
				throw new CodecException('Expected "key: value"', $no);
			}

			[$key, $rest] = $split;

			if (isset($seen[$key])) {
				throw new CodecException(sprintf('Duplicate key "%s" (first on line %d)', $key, $seen[$key]), $no);
			}

			$seen[$key] = $no;

			if ($this->fullLineMap || $depth <= 2 || ($depth === 4 && $key === 'id')) {
				$sub                 = $path === '' ? $key : $path . '/' . $key;
				$this->lineMap[$sub] = $no;
			} else {
				$sub = '';
			}

			$this->pos++;

			$result[$key] = $this->value($rest, $no, $indent, $sub, $depth, true);
		}

		return $result;
	}

	/**
	 * Parse a block sequence at the specified indentation level.
	 *
	 * @param int $indent Sequence indentation level in spaces.
	 * @param string $path Slash-delimited path prefix.
	 * @param int $depth Current nesting depth.
	 * @return list<mixed>
	 */
	private function sequence(int $indent, string $path, int $depth): array {
		$this->guardDepth($depth);

		$result = [];

		while (($cur = $this->peek()) !== null) {
			[$no, $ind, $text] = $cur;

			if ($ind < $indent) {
				break;
			}

			if ($ind > $indent) {
				throw new CodecException('Unexpected indentation', $no);
			}

			if (!self::isItem($text)) {
				break;
			}

			$after = ltrim(substr($text, 1), ' ');
			$col   = $indent + strlen($text) - strlen($after);

			if ($this->fullLineMap || $depth <= 3) {
				$sub                 = $path . '/' . count($result);
				$this->lineMap[$sub] = $no;
			} else {
				$sub = '';
			}

			if ($after !== '' && $after[0] !== '#' && self::isItem($after)) {
				throw new CodecException('A list inside a list item on one line is not supported', $no);
			}

			if ($after !== '' && $after[0] !== '#') {
				$inlineSplit = $this->splitKey($after, $no);

				if ($inlineSplit !== null) {
					$this->raw[$no - 1]        = str_repeat(' ', $col) . $after;
					$this->peekCachePos        = $no - 1;
					$this->peekCache           = [$no, $col, $after];
					$this->prefetchedSplit     = $inlineSplit;
					$this->prefetchedSplitLine = $no;
					$result[]                  = $this->map($col, $sub, $depth + 1);

					continue;
				}
			}

			$this->pos++;

			$result[] = $this->value($after, $no, $indent, $sub, $depth, false);
		}

		return $result;
	}

	/**
	 * Parse the value portion following a map key or sequence dash.
	 *
	 * @param string $rest Remainder of the line after the key or dash.
	 * @param int $no 1-based line number.
	 * @param int $indent Parent indentation level in spaces.
	 * @param string $path Slash-delimited path for line map tracking.
	 * @param int $depth Current nesting depth.
	 * @param bool $inMap Whether the value belongs to a mapping entry.
	 * @return mixed Decoded scalar, container, or null value.
	 */
	private function value(string $rest, int $no, int $indent, string $path, int $depth, bool $inMap): mixed {
		if ($rest === '' || $rest[0] === '#') {
			$next = $this->peek();

			if ($next === null) {
				return null;
			}

			if ($next[1] > $indent) {
				return $this->block($next[1], $path, $depth + 1);
			}

			if ($inMap && $next[1] === $indent && self::isItem($next[2])) {
				return $this->sequence($indent, $path, $depth + 1);
			}

			return null;
		}

		$first = $rest[0];

		if ($first === '|') {
			return $this->literal($rest, $no, $indent);
		}

		$refusal = match (true) {
			$first === '>'                                     => 'Folded blocks (>) are not supported',
			$first === '&'                                     => 'Anchors are not supported',
			$first === '*'                                     => 'Aliases are not supported',
			$first === '!'                                     => 'Tags are not supported',
			$first === '[' || $first === '{'                   => 'Flow collections ([...] and {...}) are not supported in block YAML',
			$first === '%' || $first === '@' || $first === '`' => 'A plain scalar cannot start with "' . $first . '"; quote it',
			self::isItem($rest)                                => 'A list cannot start on the same line as its key',
			$first === '?' && (strlen($rest) === 1 || $rest[1] === ' ') => 'Complex keys are not supported',
			default                                            => null,
		};

		if ($refusal !== null) {
			throw new CodecException($refusal, $no);
		}

		return $this->scalar($rest, $no);
	}

	/**
	 * Split a mapping line into its validated key and remaining value text.
	 *
	 * @param string $text Trimmed line content.
	 * @param int $no 1-based line number.
	 * @return array{0: string, 1: string}|null Tuple of [key, valueRemainder] or null if not a mapping line.
	 */
	private function splitKey(string $text, int $no): ?array {
		$first = $text[0];

		if ($first === '"' || $first === "'") {
			[$key, $end] = $this->quoted($text, 0, $no);
			$tail        = ltrim(substr($text, $end), ' ');

			if ($tail === '' || $tail[0] !== ':' || (strlen($tail) > 1 && $tail[1] !== ' ')) {
				return null;
			}

			return [$key, ltrim(substr($tail, 1), ' ')];
		}

		$head = $text;

		if (str_contains($text, '#') && preg_match('/\s#/', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
			$head = substr($text, 0, (int) $m[0][1]);
		}

		$at      = -1;
		$headLen = strlen($head);
		$offset  = 0;

		while (($colon = strpos($head, ':', $offset)) !== false) {
			if ($colon + 1 === $headLen || $head[$colon + 1] === ' ' || $head[$colon + 1] === "\t") {
				$at = $colon;

				break;
			}

			$offset = $colon + 1;
		}

		if ($at === -1) {
			return null;
		}

		$key = rtrim(substr($head, 0, $at));

		if ($key === '') {
			throw new CodecException('An empty key is not allowed', $no);
		}

		$refusal = match (true) {
			$key[0] === '&'                   => 'Anchors are not supported',
			$key[0] === '*'                   => 'Aliases are not supported',
			$key[0] === '!'                   => 'Tags are not supported',
			$key[0] === '[' || $key[0] === '{' => 'Flow collections are not supported in block YAML',
			$key[0] === '?'                   => 'Complex keys are not supported',
			$key === '<<'                     => 'Merge keys (<<) are not supported',
			str_contains('%@`|>#', $key[0])   => 'A plain key cannot start with "' . $key[0] . '"; quote it',
			default                           => null,
		};

		if ($refusal !== null) {
			throw new CodecException($refusal, $no);
		}

		[$type] = YamlScalar::classify($key);

		if ($type === YamlScalar::AMBIGUOUS || $type === YamlScalar::BOOL || $type === YamlScalar::NIL) {
			throw new CodecException(sprintf('The key "%s" reads differently in other YAML versions; quote it', $key), $no);
		}

		return [$key, ltrim(substr($text, $at + 1), ' ')];
	}

	/**
	 * Parse a single-quoted, double-quoted, or strict YAML 1.2 plain scalar.
	 *
	 * @param string $text Scalar token text.
	 * @param int $no 1-based line number.
	 * @return null|bool|int|float|string Typed scalar value.
	 */
	private function scalar(string $text, int $no): null|bool|int|float|string {
		if ($text[0] === '"' || $text[0] === "'") {
			[$value, $end] = $this->quoted($text, 0, $no);
			$tail          = substr($text, $end);

			if (trim($tail, ' ') !== '' && preg_match('/^\s+#/', $tail) !== 1) {
				throw new CodecException('Unexpected text after a quoted scalar', $no);
			}

			return $value;
		}

		if (str_contains($text, '#') && preg_match('/\s#/', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
			$text = rtrim(substr($text, 0, (int) $m[0][1]));
		}

		if (str_contains($text, ':') && preg_match('/:(\s|$)/', $text) === 1) {
			throw new CodecException('A ": " inside a plain scalar is ambiguous; quote the value', $no);
		}

		[$type, $value] = YamlScalar::classify($text);

		if ($type === YamlScalar::AMBIGUOUS) {
			throw new CodecException(sprintf('The plain scalar "%s" reads differently in other YAML versions; quote it', $text), $no);
		}

		return $value;
	}

	/**
	 * Parse a single-quoted or double-quoted scalar starting at $start.
	 *
	 * @param string $text Line text containing the quoted scalar.
	 * @param int $start Byte offset of the opening quote.
	 * @param int $no 1-based line number.
	 * @return array{0: string, 1: int} Tuple of [decodedString, nextByteOffset].
	 */
	private function quoted(string $text, int $start, int $no): array {
		$quote = $text[$start];
		$out   = '';
		$len   = strlen($text);
		$i     = $start + 1;

		if ($quote === "'") {
			while ($i < $len) {
				$span = strcspn($text, "'", $i);

				if ($span > 0) {
					$out .= substr($text, $i, $span);
					$i   += $span;
				}

				if ($i >= $len) {
					break;
				}

				if ($i + 1 < $len && $text[$i + 1] === "'") {
					$out .= "'";
					$i   += 2;

					continue;
				}

				return [$out, $i + 1];
			}

			throw new CodecException('Unterminated quoted scalar (a quoted scalar must close on the line it starts)', $no);
		}

		while ($i < $len) {
			$span = strcspn($text, '"\\', $i);

			if ($span > 0) {
				$out .= substr($text, $i, $span);
				$i   += $span;
			}

			if ($i >= $len) {
				break;
			}

			if ($text[$i] === '"') {
				return [$out, $i + 1];
			}

			$i++;

			if ($i >= $len) {
				break;
			}

			$e = $text[$i];

			if (isset(self::SIMPLE_ESCAPES[$e])) {
				$out .= self::SIMPLE_ESCAPES[$e];
				$i++;

				continue;
			}

			$digits = $e === 'x' ? 2 : ($e === 'u' ? 4 : 0);
			$hex    = $digits > 0 ? substr($text, $i + 1, $digits) : '';

			if ($digits === 0 || strlen($hex) !== $digits || preg_match('/^[0-9A-Fa-f]+$/D', $hex) !== 1) {
				throw new CodecException(sprintf('Unknown escape "\\%s" in a double-quoted scalar', $e), $no);
			}

			$code = (int) hexdec($hex);

			if ($code >= 0xD800 && $code <= 0xDFFF) {
				throw new CodecException('A surrogate code point is not allowed', $no);
			}

			$out .= self::utf8($code);
			$i   += 1 + $digits;
		}

		throw new CodecException('Unterminated quoted scalar (a quoted scalar must close on the line it starts)', $no);
	}

	/**
	 * Encode a Basic Multilingual Plane Unicode code point into UTF-8 bytes.
	 *
	 * @param int $code Unicode scalar value (0x0000 to 0xFFFF excluding surrogates).
	 * @return string UTF-8 encoded character.
	 */
	private static function utf8(int $code): string {
		return match (true) {
			$code < 0x80  => chr($code),
			$code < 0x800 => chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F)),
			default       => chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F)),
		};
	}

	/**
	 * Parse a "|" or "|-" literal block scalar following $header.
	 *
	 * @param string $header Literal block indicator line text.
	 * @param int $no 1-based line number of the indicator.
	 * @param int $parentIndent Indentation level of the owning key or item.
	 * @return string Decoded multi-line literal string.
	 */
	private function literal(string $header, int $no, int $parentIndent): string {
		$rest = $header;

		if (preg_match('/\s#/', $rest, $m, PREG_OFFSET_CAPTURE) === 1) {
			$rest = rtrim(substr($rest, 0, (int) $m[0][1]));
		}

		if ($rest !== '|' && $rest !== '|-') {
			throw new CodecException('Only the "|" and "|-" literal block styles are supported', $no);
		}

		$lines       = [];
		$blockIndent = null;

		while ($this->pos < $this->rawCount) {
			$line = $this->raw[$this->pos];

			if (trim($line, " \t") === '') {
				$lines[] = '';
				$this->pos++;

				continue;
			}

			$indent = strlen($line) - strlen(ltrim($line, ' '));

			if ($indent <= $parentIndent) {
				break;
			}

			$blockIndent ??= $indent;

			if ($indent < $blockIndent) {
				throw new CodecException('Inconsistent indentation inside a literal block', $this->pos + 1);
			}

			$lines[] = substr($line, $blockIndent);
			$this->pos++;
		}

		while ($lines !== [] && end($lines) === '') {
			array_pop($lines);
		}

		if ($lines === []) {
			return '';
		}

		return implode("\n", $lines) . ($rest === '|' ? "\n" : '');
	}
}
