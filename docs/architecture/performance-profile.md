<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
SPDX-License-Identifier: GPL-2.0-or-later
-->

# Performance Profile and Algorithmic Audit

This document records the algorithmic audit, identified hotspots, implemented fixes, and before/after PHP benchmarks for `plugin_casc`.

## Benchmark Methodology

- Runtime: PHP 8.4.25 (CLI, NTS, macOS arm64, `memory_limit=1G`).
- Timing: monotonic nanosecond clock via `hrtime(true)` converted to milliseconds.
- Memory: `memory_get_peak_usage(true)` (OS-allocated real peak) and `memory_get_peak_usage(false)` (emalloc peak).
- Isolation: each workload executes in a fresh PHP subprocess so peak memory measurements are not contaminated by prior scenarios.
- Baseline commit: `a29ab78` (`feat/enterprise-hardening-yaml-hooks-phpdoc-coverage`).

## Bottlenecks Found and Fixed

### 1. Quadratic $O(N^2)$ and $O(D \times (S + P))$ Linear Scans in Planning

- **Root cause**: `DevicesProvider::plan()`, `SitesProvider::plan()`, `PollersProvider::plan()`, and `TreesProvider::plan()` accumulated desired natural keys into a flat list and called `in_array(..., true)` inside the prune loop over existing rows ($O(N^2)$). `DevicesProvider::plan()` also ran `in_array($site, $plannedSites, true)` and `in_array($poller, $plannedPollers, true)` for every device referencing a newly declared site or poller ($O(D \times (S + P))$).
- **Fix**: Replaced list scans with `array<string, true>` hash-set membership (`isset(...)`) across all four providers and passed pre-indexed sets from `Planner::plan()`.

### 2. N+1 SQL Queries and Redundant Repository Reads

- **Root cause**:
  - `CactiStateRepository::getDevices()` issued 1 host query plus 2 queries per host (`host_snmp_query` and `host_graph`), totaling $2N + 1$ SQL round-trips (20,001 queries at 10,000 devices).
  - `CactiStateRepository::getTrees()` issued $T + 1$ SQL queries (1 per tree for `graph_tree_items`).
  - `Planner::plan()` caused redundant full-table fetches across `SitesProvider`, `PollersProvider`, `DevicesProvider`, `TemplatesProvider`, `SettingsProvider`, and `CrossParameterValidator` (each table read 2 to 3 times per plan).
  - `Applier::apply()` computed `$livePlan` inside the transaction and then called each provider's `apply()`, which re-ran `$this->plan()` a second time before executing mutations.
- **Fix**:
  - Rewrote `CactiStateRepository::getDevices()` to 3 bulk queries grouped by `host_id`, and `CactiStateRepository::getTrees()` to 2 bulk queries grouped by `graph_tree_id` and `parent`.
  - Scoped `ReadCachedStateRepository` to each `Planner::plan()` call so every table is read at most once during planning.
  - Passed domain-grouped `plannedActions` from `Applier::apply()` into each provider's `apply()` (preserving standalone fallback when called directly) and skipped domains with zero mutations.
  - Deferred `CrossParameterValidator` live device/site/poller lookups so full manifests with complete credentials avoid loading or indexing `getDevices()` during validation.

### 3. Redundant Secret Resolution and Template Inspection

- **Root cause**:
  - `DevicesProvider::plan()` resolved each SNMP secret reference during validation and resolved it again inside `diffDevice()`. Across 10,000 devices sharing `env:` or `file:` references, this caused up to 40,000 resolver calls.
  - `TemplatesProvider::plan()` called `TemplateBridge::inspectTemplateFile()` inside `validate()` and then again inside `plan()` (file read, SHA-256 hash, XML DOM parse or YAML compilation done twice per plan and five times per apply).
- **Fix**:
  - Added an ephemeral function-local secret resolution cache inside `DevicesProvider::plan()` and `DevicesProvider::apply()` (discarded on return, never persisted on object state).
  - Consolidated `TemplatesProvider::validate()` and `plan()` onto a single `validateAndInspect()` pass.

### 4. Regex Overhead and Allocations in YAML and XML Codecs

- **Root cause**:
  - `YamlScalar::classify()` ran 3 `in_array()` checks and up to 8 `preg_match()` calls on every map key and plain string scalar.
  - `YamlReader::runBlock()` ran a UTF-8 control-character regex once per line (86,315 PCRE calls on a 5,000-device manifest), `peek()` recomputed trimming and marker checks repeatedly on the same line, `sequence()` ran `splitKey()` twice on map-in-sequence headers, `quoted()` and `assertNoJsonDuplicateKeysAndDepth()` looped byte-by-byte in PHP, and `CascCodec::parse()` allocated a leaf-level `lineMap` entry for every scalar only to discard it after schema validation.
  - `YamlWriter::collection()` allocated a temporary `$block` array and unpacked `...$block` for every sequence item map; `YamlWriter::scalar()` ran multiple `/u` regexes on printable ASCII strings.
  - `XmlCodec::parse()` performed a separate full-DOM `checkDepth()` pass before decoding and allocated intermediate child arrays and attribute loops per `<entry>`/`<item>`. `XmlCodec::write()` allocated a full libxml `DOMDocument` node tree in C memory.
- **Fix**:
  - Replaced `YamlScalar::classify()` keyword checks with `const` hash maps and gated numeric/date regexes behind first-byte dispatch (`0-9`, `-`, `+`, `.`). Added a C-level `strspn()` printable-ASCII fast path (`YamlScalar::isPrintableAscii()`).
  - Updated `YamlReader` to run a single document-wide control-character scan (falling back to per-line lookup only on error), cache `peek()` tuples, pass prefetched inline `splitKey()` splits from `sequence()` to `map()`, guard `#` and `:` checks with `str_contains()`/`strpos()`, use `strcspn()` chunk scanning in `quoted()` and JSON-form scanning, and allow `CascCodec::parse()` to index only schema-checked paths in `lineMap`.
  - Updated `YamlWriter` to write sequence item maps directly into `$out` without temporary arrays, bypass `/u` regexes on printable ASCII, and use `preg_replace_callback` for double-quoted control escapes.
  - Updated `XmlCodec::parse()` to enforce depth inline during decoding and short-circuit attribute validation via `attributes->length`; replaced `XmlCodec::write()` DOM allocation with direct XML 1.0 streaming line emission.

### 5. Plugin Provider Registry Dispatch and Sorting

- **Root cause**: `ProviderRegistry::dispatchHooks()` cloned `$this` on every hook, copying $1 + 2 + \dots + P = O(P^2)$ provider entries across $P$ sibling plugins. `ProviderRegistry::all()` copied and `ksort()`ed the provider map on every call. `SecretPolicy` and `CrossParameterValidator` used `in_array()` on fixed allowlists and protocol sets.
- **Fix**: Isolated each hook in an empty single-plugin scratch `ProviderRegistry` ($O(P)$ total work), cached the sorted list in `ProviderRegistry::all()` (invalidated on `register()`/`adopt()`), and converted fixed allowlists and reserved slug/protocol lists to $O(1)$ hash sets.

## Measured Before vs After Results

| Workload | Metric | Before (`a29ab78`) | After | Improvement |
| --- | --- | ---: | ---: | ---: |
| 10k-line YAML emit (11,475 lines, 650 devices) | Wall time | 26.75 ms | 16.62 ms | 1.61x faster |
| 10k-line YAML parse (11,475 lines, 650 devices) | Wall time / Peak real RAM | 36.03 ms / 8.00 MB | 24.86 ms / 6.00 MB | 1.45x faster, -25.0% RAM |
| 5,000-device YAML emit (86,315 lines, 2.30 MB) | Wall time | 198.95 ms | 118.10 ms | 1.68x faster |
| 5,000-device YAML parse (86,315 lines, 2.30 MB) | Wall time / Peak real RAM | 273.47 ms / 45.72 MB | 190.12 ms / 36.22 MB | 1.44x faster, -20.8% RAM |
| 10k-line XML emit (13,104 lines, 735 KB) | Wall time | 15.57 ms | 9.95 ms | 1.56x faster |
| 10k-line XML parse (13,104 lines, 735 KB) | Wall time | 19.47 ms | 14.56 ms | 1.34x faster |
| JSON-form YAML parse (13,104 lines, 491 KB) | Wall time | 35.24 ms | 14.29 ms | 2.47x faster |
| `plan()` 5,000 devices + 200 sites + 50 trees (drift) | Wall time | 52.44 ms | 46.52 ms | 1.13x faster |
| `plan()` 5,000 devices + prune (200 unmanaged) | Wall time | 97.66 ms | 46.05 ms | 2.12x faster ($O(N^2) \to O(N)$) |
| `plan()` 10,000 devices + 500 new sites + prune | Wall time | 330.88 ms | 132.07 ms | 2.51x faster ($O(N^2) \to O(N)$) |
| `apply()` 5,000 devices (503 mutations) | Wall time | 84.67 ms | 47.92 ms | 1.77x faster |
| `CrossParameterValidator::validate()` 10,000 devices | Wall time / Peak real RAM | 24.19 ms / 46.00 MB | 15.11 ms / 44.00 MB | 1.60x faster, -4.3% RAM |
| `TemplatesProvider` 20 XML templates (`plan` / `apply`) | Wall time | 4.77 ms / 9.07 ms | 2.63 ms / 3.74 ms | 1.81x / 2.43x faster |
| 50 sibling plugin providers (`dispatchHooks` / `all()` x 1,000) | Wall time | 1.46 ms / 1.21 ms | 0.38 ms / 0.03 ms | 3.84x / 40.3x faster |
| `CactiStateRepository::getDevices()` at 10,000 hosts | SQL query count | 20,001 queries | 3 queries | 6,667x fewer queries |

## Remaining Constraints and Tradeoffs

- `Planner::plan()` canonicalizes the manifest via `CascDocument::toCanonicalArray()` and hashes the JSON payload to guarantee identical SHA-256 `planHash` values across YAML and XML inputs. At 10,000 devices, canonicalization and plan-hash serialization account for the remaining ~85 MB peak RSS during planning.
- `CactiStateRepository::saveDevice()` delegates to core `api_device_save()`, and `saveTree()` inserts tree items sequentially to preserve Cacti parent-ID references. Large initial imports remain bounded by MariaDB write throughput and Cacti core API latency.
