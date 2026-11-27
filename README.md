# plugin_casc

Configuration-as-Code (CasC) plugin for Cacti 1.2.x that exports, validates, diffs, and declaratively applies Cacti operational configuration in strict YAML 1.2 and canonical XML with zero runtime Composer dependencies.

## Why this plugin exists

Cacti 1.2 ships XML template import and export (`lib/export.php`, `lib/import.php`), which covers the 18 hash-keyed template tables. Operational state such as `settings`, `sites`, `poller`, `host`, `graph_tree`, and `plugin_config` relies on auto-increment IDs and has no declarative export, drift detection, or transactional reconciliation workflow. `plugin_casc` closes that gap for Cacti 1.2.31+ while bridging native Cacti template XML and `.xml.gz` packages directly through core import and export APIs.

## Capabilities

- **Dual-format codec with zero runtime dependencies**: Strict-subset block YAML 1.2 reader and writer (with JSON-form YAML fallback via `json_decode`) and hardened canonical XML envelope (`<cacti-casc apiVersion="cacti.net/v1alpha1">`). Both decode into the same `CascDocument` intermediate representation and round-trip losslessly.
- **Natural keys instead of database IDs**: Resources use portable keys (`settings.name`, `sites.name`, `poller.name`, `host.external_id` or `hostname::description`, `graph_tree.name`, `plugin_config.directory`) and reject raw `id` fields in manifests.
- **Secret governance**: Hard-blocks installation-local keys (`secret_encryption_key`, `csrf_secret`, RRDproxy RSA keys and fingerprints) from export and management. Redacts all database credential columns on export (substituting `env:CACTI_SECRET_...` placeholders by default, or omitting them with `--omit-secrets`), validates `env:VAR`, `file:/path` (confined to allowlisted `casc_secret_dirs`, mode `0400` or `0600`, symlinks rejected), and `vault:<plugin>:<slot>` syntax, and resolves `env:` and `file:` references in memory during `plan`, `drift`, and `apply` while masking values in diffs, logs, and audit rows. `SecretResolver` supports an injected `vault:` reader callable; the default `casc_service()` runtime does not wire a built-in vault backend.
- **Drift-checked transactional reconciliation**: `plan` orders mutations topologically (`settings` -> `sites` -> `pollers` -> `templates` -> `devices` -> `trees` -> `plugins`) and produces a deterministic SHA-256 plan hash. `apply --confirm=<PLAN_HASH>` re-computes the live plan inside a database transaction, aborts if controller state drifted since planning, triggers `replicate_out()` and `repopulate_poller_cache()`, and records a zero-secret audit entry in `plugin_casc_audit`.
- **Additive by default**: Unmanaged objects are preserved unless `--prune=<domains>` is passed explicitly. Primary poller `id=1` is never pruned.
- **Fault-isolated plugin provider hook**: Sibling plugins can register `casc_register_providers` hooks to participate in `export`, `validate`, `plan`, and `apply`. Each plugin registers into a cloned scratch registry so a faulty plugin cannot corrupt core state or other providers.
- **Platform integrations**: Registers read-only status and drift abilities (`casc-get-status`, `casc-check-drift`) with `plugin_mcp` (`abilities_register`) and diagnostic checks (`casc_secret_dirs`, `casc_baseline_drift`, `casc_plugin_providers`) with `plugin_doctor` (`doctor_register_checks`).

## Requirements

- Cacti `1.2.31` or later on the `1.2.x` branch
- PHP `8.2` or later with `ext-dom`, `ext-json`, `ext-libxml`, and `ext-pdo`
- Entry points (`setup.php`, `cli/casc.php`, web pages, `include/autoload.php`) parse cleanly on PHP `8.0` so older hosts receive a refusal instead of a fatal parse error

## Installation

1. Clone or unpack into `<cacti_root>/plugins/casc`.
2. Install and enable via CLI or Console:

```bash
php cli/plugin_manage.php --plugin=casc --install --enable
```

3. Assign user permissions using the three-tier RBAC realms (`CasC View`, `CasC Operator`, `CasC Admin`).

## CLI Usage

```bash
# Export current state to YAML or canonical XML (secrets redacted, local paths in reference:)
php plugins/casc/cli/casc.php export --format=yaml --output=cacti-config.yaml
php plugins/casc/cli/casc.php export --format=xml --output=cacti-config.xml --split-templates=templates/

# Validate syntax, schema, natural keys, secret references, and template SHA-256 digests
php plugins/casc/cli/casc.php validate --file=cacti-config.yaml

# Compute deterministic plan and SHA-256 confirmation hash (exit: 0 no-op, 2 changes, 1 error)
php plugins/casc/cli/casc.php plan --file=cacti-config.yaml --format=text

# Apply exact planned changes transactionally
php plugins/casc/cli/casc.php apply --file=cacti-config.yaml --confirm=<PLAN_HASH>

# Check live drift (JSON or Nagios status line)
php plugins/casc/cli/casc.php drift --file=cacti-config.yaml --format=json
php plugins/casc/cli/casc.php drift --file=cacti-config.yaml --format=nagios
```

## Manifest Example

```yaml
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
metadata:
  name: prod-us-west
  cactiCompat: '1.2.31'
spec:
  settings:
    allowlistMode: merge
    values:
      poller_interval: 300
      poller_type: 2
      snmp_password: 'env:CACTI_SNMP_PASSWORD'
  sites:
    - city: San Jose
      country: US
      name: sjc1
      timezone: America/Los_Angeles
  pollers:
    - disabled: false
      hostname: cacti-main.internal
      name: Main Poller
      processes: 4
      threads: 8
    - dbhost: poller-sjc1.internal
      dbpass: 'file:/run/secrets/poller_sjc1_dbpass'
      disabled: false
      hostname: poller-sjc1.internal
      name: sjc1-remote
      processes: 2
      threads: 4
  devices:
    - description: Edge Router 01
      disabled: false
      hostname: 198.51.100.10
      key: edge-rtr-01.sjc1
      poller: sjc1-remote
      site: sjc1
      snmpAuthProtocol: SHA256
      snmpPassword: 'env:EDGE_SNMP_AUTH'
      snmpPrivPassphrase: 'env:EDGE_SNMP_PRIV'
      snmpPrivProtocol: AES256
      snmpUsername: cacti_ro
      snmpVersion: 3
  trees:
    - enabled: true
      items:
        - children:
            - device: edge-rtr-01.sjc1
          header: SJC1
      name: Production Network
      sortType: natural
reference:
  paths:
    path_rrdtool: /usr/bin/rrdtool
```

## Security Boundaries

- **XML parsing**: Rejects `<!DOCTYPE` and `<!ENTITY` before libxml sees the document, loads with `LIBXML_NONET | LIBXML_NOBLANKS`, verifies `$dom->doctype === null`, and enforces 8 MiB and depth-16 limits.
- **YAML parsing**: Rejects duplicate keys, anchors (`&`), aliases (`*`), tags (`!`), flow collections inside block YAML, merge keys (`<<`), ambiguous YAML 1.1 scalars (`yes`/`no`/`on`/`off`/octal/sexagesimal), and unescaped control characters. JSON-form YAML is scanned for duplicate keys and depth limits before `json_decode`.
- **Secret files**: `file:` secret references must reside inside configured `casc_secret_dirs`, must not traverse or point to symlinks on any path component, and must have mode `0400` or `0600`.
- **Audit logs**: `plugin_casc_audit` stores actor, source, plan hash, mutation counts, and masked action identifiers only. Secret values are never persisted to audit rows, plan output, or Cacti logs.

## Development & Testing

Runtime code has zero Composer dependencies. Development tooling (`phpunit/phpunit` and `phpstan/phpstan`) is installed via `composer install`:

```bash
composer install
composer test
CACTI_PATH=/path/to/cacti-1.2.31 composer phpstan
```

## Roadmap

- **Implemented (v0.1.0)**: Dual YAML 1.2 + XML codec, native template XML bridge, secret policy and resolver, core providers (`settings`, `sites`, `pollers`, `templates`, `devices`, `trees`, `plugins`), transactional planner/applier with plan-hash drift guard, `casc_register_providers` hook, CLI, 3-tier RBAC web pages, `plugin_mcp` and `plugin_doctor` hooks.
- **Planned**: Declarative providers for `user_domains*`, `user_auth_group*` (symbolic plugin realm resolution), `automation_*`, `reports*`, `external_links`, `aggregate_graph_templates*`, and `snmpagent_managers*`.

## License

GNU General Public License v2.0 or later (`GPL-2.0-or-later`). See [LICENSE](LICENSE).
