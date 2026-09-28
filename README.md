# texstar/exchange-contract (v1)

The wire truth of the **Texstar Exchange**: the package format PMO and NESC read and write when they
sync with or without the live HTTP bridge, the `/api/v1/exchange/*` runner API both apps expose, the
golden fixtures both apps test against, and the reference runner CLI. Plain PHP 8.3 — no Laravel in
`src/` (test-enforced) — so both apps, the owner's runner and PMO's future implementation depend on
the same code, the same JSON Schemas and the same fixtures.

Phase 1b ships this package and the NESC side. **The PMO side is deferred** until the PMO test copy
exists; the [PMO checklist](#pmo-implementation-checklist) at the end lists what it must do to pass
the same fixtures.

## Layout

| Path | What |
|---|---|
| `schemas/v1/common.schema.json` | shared `$defs`: `jb`, `tsk`, `candidate_key`, `sha256`, `uuid`, `iso_datetime`, `sql_datetime`, `direction`, `kind`, `mode`, `scope`, `entity_key` |
| `schemas/v1/{scope,manifest,receipt,updates}.schema.json` | the manifest, the receipt, the `UpdateOp` row, the scope |
| `schemas/v1/streams/<stream>.schema.json` | one document per package stream (18) |
| `src/Contract.php` | `FORMAT`, `RECEIPT_FORMAT`, `VERSION`, `MAJOR`, `MAX_ROW_BYTES`, `DIRECTION_*`, `KIND_*` |
| `src/Dto/*` | readonly DTOs with `fromArray()` / `toArray()`; unknown keys survive in `$extra` |
| `src/Schema/*` | `SchemaValidator` (2020-12 subset), `SchemaRegistry`, `KeywordAudit`, `Violation` |
| `src/Stream/*` | `StreamCatalogue` (name, direction, mode, key, bridge action, neutral name) and `RowKey` |
| `src/Canonical/CanonicalJson.php` | the signing input encoder |
| `src/Signing/*` | `KeyRing`, `Signer`, `Verifier` |
| `src/Package/*` | `PackageBuilder`, `PackageReader`, `Negotiation`, `PathDiscipline`, `InflateCounter` |
| `src/Receipt/ReceiptBuilder.php` | per-stream received/accepted/rejected -> `Receipt` |
| `src/Client/RunnerClient.php` | the runner API client (`CurlTransport` behind a callable) |
| `src/Cli/*` | `App`, `SyncCommand`, `PushUpdatesCommand` — what `bin/exchange` runs |
| `src/Testing/ContractSuite.php` | the golden fixtures as a suite either app's test runner invokes |
| `src/Testing/FixtureSigner.php`, `ZipTamper.php` | fixture maintenance and test-only zip surgery |
| `fixtures/v1/` | `keys.json` (test-only key) and the twelve fixture trees, each with `expectations.json` |
| `bin/exchange` | the reference CLI |

Every schema declares `$schema` 2020-12 and uses only the keywords `SchemaValidator` implements
(`KeywordAudit` is test-enforced): `type`, `required`, `properties`, `enum`, `const`, `pattern`,
`minLength`/`maxLength`, `minimum`/`maximum`, `minItems`/`maxItems`, `items`, `format`
(`date-time`, `date`, `uuid`), `$ref`, `allOf`/`anyOf`/`oneOf`. `additionalProperties` is never
used: unknown fields are ignored on the wire by design. PMO may run a full validator over the same files.

## Package (`*.txx.zip`)

Closed entry set: `manifest.json`, `streams/<stream>.ndjson`, `files/<sha256>`, `receipt.json`.
`PathDiscipline` refuses any other entry (`unexpected_entry`) and any manifest path outside that
layout (`bad_path`) **before** a stream or blob entry is read; entry names are never used as filesystem
path components. File name: `<producer.app>-<to>-<created_at compact>-<package_id8>.txx.zip`, built
from validated fields only.

### Manifest

```
format: "texstar-exchange"          version: 1 (reader accepts == its MAJOR)      contract: "1.0.0"
kind: "package" | "rows"            package_id: uuid v4                            direction: nesc_to_pmo | pmo_to_nesc
created_at: ISO-8601                producer: {app: nesc|pmo, version, instance, contract}
scope: {tenant, contract, program, instance|null}   OPTIONAL — absent => Scope::UNSCOPED (texstar/comcast/comcast-nesc)
cursors: {<stream>: {from, to}}     opaque
files:  [{path, stream, schema: "<stream>@1", rows, sha256, bytes, mode: delta|full, set_checksum?, set_total?}]
blobs:  [{sha256, bytes, mime, path: "files/<sha256>"}]
receipts: [{package_id, path: "receipt.json"}]
extras: {readiness_feed?, p2_runner_summary?, worker_activity_summary?, gis_icons?, external_refs_skipped?}
signature: {alg: "HMAC-SHA256", key_id: "<tenant>:<contract>", value: hex} | null   (null only when kind = rows)
```

Directions are the ASCII strings `nesc_to_pmo` / `pmo_to_nesc`; the plan's arrow is prose. The
manifest — `extras` included — is signed as **strict** canonical JSON, so it must not contain floats:
render rates and averages as strings or integers (the fixture `p2_runner_summary` does). Rows may
carry floats freely.

### Streams

Row = today's bridge row byte-for-byte, plus `scope` (the builder stamps the manifest scope on rows
that lack it). `origin` / `origin_ref` are optional on every row (Phase 1e sheet-origin rows).
"Neutral name" is the source-neutral name reserved for 1.x renames; the wire name is the stream column.

| stream | neutral name | dir | mode | key | shape | PMO exporter to write |
|---|---|---|---|---|---|---|
| readiness | deliverables:readiness | nesc→pmo | delta | job_number | `push_p2_job_states` row in `P2ContractPinTest::PAYLOAD_KEYS` order + `backlog`; `tasks[]` also carry `system`, `external_task_id` (package rows only — the HTTP payload does not) | — (importer: `push_p2_job_states`) |
| readiness_feed | — | nesc→pmo | full | `feed` | one row: `P2ReadinessService::feedSummary()`; also in `extras.readiness_feed` | — |
| inspection_reports | deliverables:report | nesc→pmo | delta | external_report_id | `REPORT_KEYS`; `content_base64` MUST be null; PDF under `files/<sha256>` or via `GET /files/{sha256}` | — (importer resolves `files/{sha}`) |
| inspection_packets | deliverables:packet | nesc→pmo | delta | external_id | `InspectionPacketOutboxService::packet()`; `photos[].sha256` nullable, `file_url` kept | — |
| review_decisions | — | nesc→pmo | delta | external_decision_id | `decision` ∈ confirm, reject, merged, merge | — (importer accepts the `merged` alias) |
| observations | — | nesc→pmo | delta | external_id | legacy obs item `{jb, entity, entity_id, field, value, observed_at, note}` + `external_id` `nesc:obs:{jb}:{field}:{sha8(value)}` | — |
| worker_activity | sow_claims:presence | nesc→pmo | delta | kind\|id | `{kind: job\|assignment\|contractor, …WorkerActivityRunnerSync row}` | — |
| external_refs | external_refs | nesc→pmo | delta | entity_type\|entity_key\|system\|external_id | see below; emitted only when NESC's drift check passes (`extras.external_refs_skipped` otherwise) | — |
| jobs | jobs | pmo→nesc | delta | job_number | `NescSyncFeed::jobPayload()` + `withBridgeAliases` (`job_number`, `verdict`, `nesc_scope`, `poles.count`); only `job_number` required; cursor `{updated_since, after_id}` | exporter over `NescSyncFeed::pullPage` |
| poles | poles (+violations nested) | pmo→nesc | full | candidate_key | `NescPoleSnapshotService` candidateRow/recordRow (+ `ai_verdict`, `ai_rationale`, `ai`), record wins; rows in candidate_key order; `set_checksum` = sha256(sorted distinct candidate_keys joined `"\n"`) = `keysChecksum()`, `set_total` | exporter over `snapshotPage` |
| p2_runner_status | external_refs:p2_runner | pmo→nesc | delta | kind\|id | `{kind: report\|note\|rfa\|job, …P2RunnerStatusFeed row}` incl. `jobs[].p2_tasks[]`; summary in `extras.p2_runner_summary` (float-free) | exporter over `P2RunnerStatusFeed::pull` |
| jobmaster_status | external_refs:jobmaster | pmo→nesc | delta | job_number | `JobMasterStatusFeed::pull` row | exporter over `JobMasterStatusFeed::pull` |
| worker_activity_status | external_refs:worker_activity | pmo→nesc | delta | kind\|id | `{kind: job\|assignment, …WaRunnerNescIngest::pull row}`; `presence` present\|missing\|unknown; summary in `extras.worker_activity_summary` | exporter over `WaRunnerNescIngest::pull` |
| dailies | — | pmo→nesc | delta | daily | legacy `dailies_since` row incl. `work_items[{seq, unit_code, quantity, qty_num, description}]` | **yes — no PMO bridge producer exists** (fixture pins the NESC consumer shape, `PmoSyncService.php:201-283`) |
| packets_wanted | — | pmo→nesc | full | job_number | `{job_number}` (one row per `inspection_packets_wanted` job) | exporter over `inspection_packets_wanted` |
| gis_sources | — | pmo→nesc | full | id | `GisReferenceLayers::catalog()` source `{id, name, sort, layers[{id, name, role, …}]}`; icons in `extras.gis_icons` | exporter over `catalog()` + icons |
| gis_features | — | pmo→nesc | full | layer\|feature_id | `{layer_id, layer, kind: pole\|job\|feature, feature_id, ref, source, ref_raw, lng, lat, properties (≤20 keys), geometry?}` — the `NescGisController::lookup` candidate + identity | **yes — no full-set producer exists** (the live lookup answers per ref) |
| pge_violations | — | pmo→nesc | full | ticket | `{ticket, payload}`; `payload` = `NescPgeController::violation()`; store only on NESC | **yes — no full-set producer exists** |
| updates | — | API only | — | id | `UpdateOp` (`POST /updates`); never a package stream | — |

`external_refs.entity_key`: `App\Models\Job` → `job_number` (covers p2 / jobmaster / p2_runner / pmo /
sphere refs, worker_activity presence and the `assignment:<id>` booking refs, which
`MirrorProjector::waBooking` writes on the Job); `App\Models\JobTask` → `external_task_id`, else
`synthetic_key`, else the row is omitted; `Pole` (`candidate_key`) and `Inspection`
(`nesc:inspection:<id>:r<rev>`) are **reserved** — no projector writes them in 1b and PMO must not
expect them. `entity_id` is informational. The JSON column is `properties` (not `attributes`).

`RowKey::for(stream, row)` joins composite keys with `|` and prefixes kind-discriminated streams
(`job|JB000001`); receipts and verdicts address rows by `(key, seq)`, never by batch index.

### Receipt

`{format: "texstar-exchange-receipt", version: 1, package_id, receipt_id, produced_at, producer, scope,
streams: {<stream>: {received, accepted, rejected, accepted_all, rejected_rows: [{key, seq, code, message}], cursor_to, applied_at}},
status: applied|rejected|partial, message?}`. `receipt.json` inside a package wraps a list:
`{format, version, receipts: [...]}`. "Accepted" is every row of the stream not listed in
`rejected_rows`; `seq` is the 0-based line in the stream file.

A receipt exists **only** for a package whose manifest verified: `applied`, or `rejected` after verify
(`unsupported_version`, `unexpected_entry`, `hash_mismatch`, `unpacked_too_large`). Packages rejected
before verify (`signature_invalid`, `signature_missing`, `unknown_key_id`, `unknown_producer`,
`direction_mismatch`) get none — their `rejected_code` is visible only on `GET /inbound/{uuid}`.
Receipts travel inside the next package in the opposite direction AND are fetchable from
`GET /inbound/{uuid}/receipt`.

## Signing (fail closed)

`Signer::sign()` = HMAC-SHA256 hex over `CanonicalJson::encode(manifest minus signature, strict: true)`.
Canonical JSON: keys sorted bytewise (lists keep order), no whitespace, unescaped slashes/unicode,
`serialize_precision = -1` pinned for the call; an empty object and an empty array both canonicalise to
`[]`; `strict` refuses floats. `key_id` is `"<tenant>:<contract>"` — `texstar:comcast` by default —
and the secret is agreed out of band per (tenant, contract).

`KeyRing::fromEnvString("texstar:comcast=<secret>,other:pair=<secret>")` splits pairs on `,` and each
pair on the **first** `=` (base64 padding survives; a `,` cannot appear in a secret). A blank key id
or secret throws, so an unset environment yields an **empty** ring: `Signer` throws
(`unknown_key_id`), `Verifier` always fails. `Verifier` requires `alg`, a known non-empty `key_id`
and a hex value, compares with `hash_equals`, and throws `SignatureException` with
`signature_missing` (absent, `null` or incomplete), `unknown_key_id` or `signature_invalid`.

**Exemption — `kind = rows`.** `POST /rows/{stream}` builds an unsigned mini package server-side
(`PackageBuilder::finalizeUnsigned()`, permitted only when the draft `kind === 'rows'`) and stages it
with the signature, producer and direction gates skipped, because the Sanctum principal holding
`exchange.apply` already authenticated the rows. The same zip pushed through `POST /inbound` is a
`kind = package` upload and is rejected `signature_invalid` (`signature_missing` on the wire): the
inbound upload crosses company lines and nothing unsigned crosses it. Fixture: `rows-kind`.

## Runner API (`/api/v1/exchange/*`, identical on both apps)

Bearer token only (a field login / pairing token or an API-client token). Every POST/PUT carries an
`Idempotency-Key` (1–128 chars); `429` answers carry `Retry-After`; every non-2xx body is the one
envelope `{"error": {"code", "message", "row"?: {stream, seq, key}, "correlation_id"?}}`; long
operations are async with a pollable status (`outbound: queued → building → ready → receipted | failed`;
`inbound: uploading → staging → previewed → applying → applied | failed | rejected`).

The reference document is `openapi/nesc-exchange.openapi.json` (OpenAPI 3.1, exported from NESC by
`php artisan scramble:export`, byte-diffed by NESC's `OpenApiSpecTest`, refreshed here with each slice that
changes the surface; served interactively at NESC's `/docs/api` for `exchange.view` holders). PMO's
implementation must satisfy the same document; `POST /updates` and `GET /updates/workbook` are not in it yet
(NESC slice 8).

```
GET  /status                                     contract, scope, limits {max_upload_mb, max_package_mb, chunk_mb, max_file_mb}, pending, cursors
POST /outbound {include_blobs?, streams?, program?} -> 202 {package_id, status, status_url}
GET  /outbound/{id}   GET /outbound/{id}/download   GET /outbound/{id}/streams/{stream}?cursor=&limit=   (X-Next-Cursor, X-Has-More, X-Stream-Schema)
POST /inbound  multipart package (≤ max_upload_mb) + auto_apply?           -> 202 {import_id, status}
POST /inbound/{uuid}/chunks   POST /inbound/{uuid}/finalize   GET /inbound/{uuid}   POST /inbound/{uuid}/apply   GET /inbound/{uuid}/receipt
HEAD|GET|PUT /files/{sha256}                       files by hash; PUT re-hashes the body
POST /rows/{stream} (≤ 500 rows, ?dry_run=)        POST /updates (ops ≤ 2000 or a workbook)   GET /updates/workbook   GET /imports   GET /imports/{id}
```

### Chunk lifecycle (client uuid)

The **client** generates a v4 uuid. The first `POST /inbound/{uuid}/chunks` creates the `uploading`
import owned by the caller (`409 conflict` when the uuid exists under another owner); later chunks,
`finalize` and `apply` require the same owner; `finalize`/`apply` on an unknown uuid → `404`. Each
chunk carries `chunk_index`, `total_chunks` (≤ `max_chunks`) and `checksum` (sha256 of the chunk);
resending an index with the same checksum is idempotent, a different checksum is `409`. Chunk bodies
are ≤ `max_upload_mb`; the running assembled total is ≤ `max_package_mb` (`413 package_too_large` at
chunk time). `finalize {total_chunks, sha256, auto_apply?}` checks the assembled hash
(`400 hash_mismatch`). `RunnerClient::uploadInbound()` does all of this from the `limits` in `GET /status`.

### Size caps

| cap | default | applies to |
|---|---|---|
| `max_upload_mb` | **60** | any single HTTP body: a single-shot package, one chunk, a page upload |
| `max_package_mb` | **200** | the assembled total of a chunked package |
| `max_unpacked_mb` | **2048** | the declared-size pre-check AND the inflated-bytes guard in `PackageReader` (`413 unpacked_too_large`) |
| `max_file_mb` | **30** | `PUT /files/{sha256}` and blob entries |
| `max_row_bytes` | 1 MiB | one NDJSON line (`row_too_large` rejects the row, not the package) |
| chunks | `chunk_mb` 8, `max_chunks` 4000 | the chunk lifecycle |

PMO mirrors the caps (they are advertised in `GET /status`, so the runner never hard-codes them).

## Builder and reader

```php
$b = PackageBuilder::open($zipPath, $draftManifest);          // kind, direction, producer, scope, cursors
$b->addStreamRows('readiness', $rows, fn (int $seq, int $offset) => ...);  // line by line; offsets every 1000 rows
$b->addBlob($sha256, $pdfPath, 'application/pdf');            // re-hashed; BlobHashException on mismatch
$b->addReceipts([$receipt]);
$manifest = $b->finalize(new Signer, $ring, 'texstar:comcast', $extras);   // manifest.json written last

$r = PackageReader::open($zipPath, $maxInflatedBytes);        // ALWAYS pass the cap for untrusted input
$r->verify($ring);                                            // before anything else
$violations = $r->validateManifest();                         // schema + version negotiation + path discipline
$r->declaredSize();                                           // central-directory sum: a pre-check only
$r->checkHashes();                                            // streams AND blobs, under the inflate guard
foreach ($r->rows('readiness') as $seq => $rowOrRowError) { ... }
$r->blob($sha256); $r->receipts();
```

Guards: a line over `MAX_ROW_BYTES` (1 MiB) is refused by the builder and reported by the reader as
`RowError row_too_large` (the next line still parses); `inspection_reports` rows with a non-null
`content_base64` are refused; every inflated byte passes through one `InflateCounter`
(`InflatedSizeException`, reason `cap`); an entry that inflates past the size its central-directory
record declares is cut off within one read buffer (`entry_overflow`); a stream whose bytes hash
differently from the manifest, or a blob whose bytes/length differ from the manifest and the entry
name, is `HashMismatchException`. Wire codes: `unpacked_too_large` / `hash_mismatch` via `wireCode()`.

## Versioning

- manifest `version` must equal the reader's `MAJOR` (else `unsupported_version`);
- per-stream `schema` `"<stream>@<major>"`: unknown stream or higher stream major → skipped with a
  warning, never rejected (`Negotiation::evaluate()`);
- unknown fields are ignored; missing required fields reject the row `(key, seq)`; oversize or
  malformed lines reject the row;
- additive changes bump `contract` minor; renames/removals bump major. Ids stay un-namespaced in v1
  (`nesc:<tenant>:…` is v2).
- **Timestamps:** `latest_event_at` (readiness) is a SQL datetime `Y-m-d H:i:s`; every other
  timestamp is ISO-8601. The schemas' `date-time` format accepts both; a strict RFC 3339 validator
  must special-case `latest_event_at`.

## Golden fixtures and the ContractSuite

`fixtures/v1/<name>/` is a committed package tree (`manifest.json`, `streams/*.ndjson`, `files/*`,
`receipt.json`, optional `entries.json` for exotic zip entry names) plus `expectations.json`
(`kind`, `direction`, `key_id`, `scope`, `verify`, `validate`/`violations`, `hashes`, per-stream
`rows`/`rejects`, `skipped`, `receipts`, `source` = the producer file:line each stream was derived
from, `pins`, and the `build` block `fixtures:sign` uses). Every manifest is signed with
`fixtures/v1/keys.json` (`texstar:comcast` = `golden-fixture-key`, test-only).

| fixture | what it proves |
|---|---|
| `nesc-to-pmo-basic` | every nesc_to_pmo stream from the frozen `P2ContractPinTest` fixture (Carbon 2026-09-01 12:00:00): the readiness row hashes to `READINESS_HASH` once `scope` and `tasks[].system/external_task_id` are stripped, the packet row to `PACKET_FINGERPRINT`; report PDFs under `files/`; NESC's receipt for PMO package p-0001 |
| `pmo-to-nesc-basic` | every pmo_to_nesc stream in the PMO emitters' shape (`jobs` = `NescSyncFeed::jobPayload` + the three aliases, `poles` = candidateRow/recordRow with `set_checksum`, runner/jobmaster/WA rows, plus the consumer-shaped `dailies`, `gis_*`, `pge_violations`); PMO's receipt for NESC package n-0001 |
| `rows-kind` | `kind = rows`, `signature: null`: valid through `stage(kind: rows)`, `signature_missing` as a package |
| `tampered-stream` | the signed manifest describes the original row; the stream was edited → `hash_mismatch` |
| `bad-signature` | wrong secret → `signature_invalid` |
| `bad-blob` | blob bytes do not hash to the entry name → `hash_mismatch` |
| `bad-path` | `streams/../readiness.ndjson` → `bad_path` + `unexpected_entry`, nothing read |
| `unknown-stream` | `foo_bar@1` skipped with a warning, the known stream still applies |
| `v2-major` | `version: 2` → `unsupported_version` |
| `unscoped` | no scope anywhere → `Scope::UNSCOPED`, applies to `comcast-nesc` |
| `oversize-row` | one 2 MB line → `row_too_large` for that seq, the package stays valid |
| `unsigned-package` | `kind = package` with `signature: null` → `signature_missing` |

Run them from any test runner:

```php
foreach (ContractSuite::cases() as $case) {                    // {name, path (zip in sys temp), keyRing, expected}
    $reader = PackageReader::open($case['path'], 64 * 1024 * 1024);
    ContractSuite::assertReader($reader, $case['expected'], fn (string $problem) => $this->fail($case['name'].': '.$problem));
}
ContractSuite::cleanup();
```

`assertReader()` drives the reader like an importer — verify, validate, hashes, then every accepted
stream's rows through the stream schema — and reports each mismatch through the callable. NESC runs
this in `tests/Feature/Exchange/ContractFixturesTest.php` and additionally rebuilds the frozen fixture
database to prove its live payloads equal the fixture rows; PMO runs the same suite against its reader
and its emitters.

Maintenance: edit a tree, then `bin/exchange fixtures:sign` rewrites `manifest.json` (hashes, byte
counts, signature) per `expectations.build` and the committed output must match (`FixturesTest`).

## CLI

```
bin/exchange sync --from=nesc --to=pmo --from-url=https://nesc.example --to-url=https://pmo.example [--both] [--include-blobs] [--program=comcast-nesc] [--timeout=600] [--keep]
bin/exchange push-updates changes.xlsx --url=https://nesc.example [--dry-run] [--create]
bin/exchange fixtures:sign [--fixture=NAME]   bin/exchange fixtures:build NAME [--out=ZIP]   bin/exchange suite:run
bin/exchange schemas:audit                    bin/exchange package:inspect some.txx.zip --keys=texstar:comcast=<secret> --max-unpacked-mb=2048
```

Tokens come from `--from-token` / `--to-token` / `--token` or, better, the environment
(`EXCHANGE_FROM_TOKEN`, `EXCHANGE_TO_TOKEN`, `EXCHANGE_TOKEN`), so they never show in `ps`. `sync`
builds "everything not yet receipted" on the FROM app, waits, downloads the signed zip (checked
against `X-Package-Sha256`), uploads it to the TO app with `auto_apply`, waits, prints the receipt;
`--both` runs the opposite direction next, which carries the receipt back. The runner talks to both
`/api/v1/exchange` surfaces itself — neither app needs to reach the other. `RunnerClient` is the same
code with `ext-curl` behind a callable transport (`tests/Support/FakeTransport.php` shows the shape).

## Development

```
composer install
php vendor/bin/phpunit
bin/exchange schemas:audit && bin/exchange suite:run
```

NESC depends on this package through a composer **path repository** (`../../exchange-contract`,
symlinked): a checkout of nescdev needs this repo as a sibling of `htdocs/` — see the NESC HANDOVER.

## PMO implementation checklist

What the PMO side must do to pass `ContractSuite` and interoperate with the NESC side:

1. **Reader/importer:** `verify` → `validateManifest` → `declaredSize` → `checkHashes` → rows, under the
   inflate cap; refuse producers other than `nesc`, directions other than `nesc_to_pmo`, unsigned or
   unknown-key manifests; skip unknown streams and higher stream majors with a warning.
2. **Receipts only after verify**, keyed by `RowKey` + `seq` (0-based line in the stream file) — never
   by batch index: translate `P2RunnerNescIngest` `errors[].index` and `WaRunnerNescIngest`'s silent
   skips into `(key, seq)` rows. Ship them in the next `pmo_to_nesc` package AND on `GET /inbound/{uuid}/receipt`.
3. **`review_decisions`:** accept the `merged` alias for `merge` (NESC's `pushMergeDecision` sends `merged`).
4. **`inspection_reports`:** `content_base64` is always null; resolve `files/{sha256}` from the package
   blobs or `GET /files/{sha256}` on NESC into `content_base64` / `file_url` before calling the existing
   ingest.
5. **Exporters:** wrap `NescSyncFeed::pullPage`, `NescPoleSnapshotService::snapshotPage` (record wins,
   candidate_key order, `set_checksum` = `keysChecksum()`), `P2RunnerStatusFeed::pull`,
   `JobMasterStatusFeed::pull`, `WaRunnerNescIngest::pull` (add `kind`), `inspection_packets_wanted`,
   `GisReferenceLayers::catalog()` + icons; **write** the three missing producers — `dailies`
   (legacy `dailies_since` row incl. `work_items[]`), `gis_features` (the full pole/job feature set in
   the lookup-candidate shape) and `pge_violations` (`{ticket, payload}`). Fold `truncated` / `more` /
   `has_more` into `X-Has-More` on `GET /outbound/{id}/streams/{stream}`; PMO cursors travel verbatim.
6. **Unscoped packages apply to program `comcast-nesc`** (`Scope::UNSCOPED`); stamp the resolved scope on
   every row and echo it in receipts.
7. **Mirror the caps** (60 / 200 / 2048 / 30 MB, 1 MiB rows, 8 MB chunks, 4000 chunks) and advertise
   them in `GET /status.limits`; mirror the `kind = rows` exemption exactly (unsigned only through
   `POST /rows/{stream}`, never through `POST /inbound`).
8. **Manifest `extras` are float-free** (strict canonical signing): `p2_runner_summary` rates as strings.
9. **Timestamps:** ISO-8601 everywhere except `latest_event_at` (SQL datetime).
10. Run `FixturesTest`'s counterpart: `ContractSuite::cases()` through the PMO reader, and the PMO
    emitters against `pmo-to-nesc-basic/expectations.json` `source` lines.
