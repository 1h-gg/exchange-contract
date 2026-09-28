# texstar/exchange-contract (v1)

The wire truth of the **Texstar Exchange**: the package format PMO and NESC read and write when they
sync with or without the live HTTP bridge. Plain PHP 8.3 — no Laravel in `src/` (test-enforced) — so
both apps, the owner's runner and PMO's future implementation depend on the same code and the same
JSON Schemas.

Slice 1 (this version) ships the contract core: schemas, DTOs, validator, canonical JSON, fail-closed
signing and the streaming zip builder/reader. The runner client, CLI sync commands, golden fixtures and
the `ContractSuite` follow in later slices of Phase 1b.

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
| `src/Testing/ZipTamper.php` | test-only central-directory surgery for the inflate-cap tests |
| `bin/exchange` | reference CLI: `schemas:audit`, `package:inspect <zip>` |

Every schema declares `$schema` 2020-12 and uses only the keywords `SchemaValidator` implements
(`KeywordAudit` is test-enforced): `type`, `required`, `properties`, `enum`, `const`, `pattern`,
`minLength`/`maxLength`, `minimum`/`maximum`, `minItems`/`maxItems`, `items`, `format`
(`date-time`, `date`, `uuid`), `$ref`, `allOf`/`anyOf`/`oneOf`. `additionalProperties` is never
used: unknown fields are ignored on the wire by design. PMO may run a full validator over the same files.

## Package (`*.txx.zip`)

Closed entry set: `manifest.json`, `streams/<stream>.ndjson`, `files/<sha256>`, `receipt.json`.
`PathDiscipline` refuses any other entry (`unexpected_entry`) and any manifest path outside that
layout (`bad_path`) **before** a stream or blob entry is read; entry names are never used as filesystem
path components.

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

Directions are the ASCII strings `nesc_to_pmo` / `pmo_to_nesc`; the plan's arrow is prose.

### Streams

Row = today's bridge row byte-for-byte, plus `scope` (the builder stamps the manifest scope on rows
that lack it). `origin` / `origin_ref` are optional on every row (Phase 1e sheet-origin rows).

| stream | neutral name | dir | mode | key | shape |
|---|---|---|---|---|---|
| readiness | deliverables:readiness | nesc→pmo | delta | job_number | `P2ReadinessService::payload()` + `backlog`; `tasks[]` also carry `system`, `external_task_id` (package rows only) |
| readiness_feed | — | nesc→pmo | full | `feed` | one row: `P2ReadinessService::feedSummary()` |
| inspection_reports | deliverables:report | nesc→pmo | delta | external_report_id | `P2ReportOutboxService` row; `content_base64` MUST be null; PDF under `files/<sha256>` or via `/files/{sha256}` |
| inspection_packets | deliverables:packet | nesc→pmo | delta | external_id | `InspectionPacketOutboxService::packet()`; `photos[].sha256` nullable |
| review_decisions | — | nesc→pmo | delta | external_decision_id | `decision` ∈ confirm, reject, merged, merge |
| observations | — | nesc→pmo | delta | external_id | legacy obs item + `external_id` `nesc:obs:{jb}:{field}:{sha8(value)}` |
| worker_activity | sow_claims:presence | nesc→pmo | delta | kind\|id | `{kind: job\|assignment\|contractor, …WorkerActivityRunnerSync}` |
| external_refs | external_refs | nesc→pmo | delta | entity_type\|entity_key\|system\|external_id | see below |
| jobs | jobs | pmo→nesc | delta | job_number | `NescSyncFeed::jobPayload()` + bridge aliases; only `job_number` required |
| poles | poles | pmo→nesc | full | candidate_key | `NescPoleSnapshotService` record; `set_checksum` = sha256(sorted distinct candidate_keys joined `"\n"`) |
| p2_runner_status | external_refs:p2_runner | pmo→nesc | delta | kind\|id | `{kind: report\|note\|rfa\|job, …P2RunnerStatusFeed}`; summary in `extras.p2_runner_summary` |
| jobmaster_status | external_refs:jobmaster | pmo→nesc | delta | job_number | `JobMasterStatusFeed` row |
| worker_activity_status | external_refs:worker_activity | pmo→nesc | delta | kind\|id | `{kind: job\|assignment, …WaRunnerNescIngest::pull}`; summary in extras |
| dailies | — | pmo→nesc | delta | daily | legacy `dailies_since` row incl. `work_items[]` — **PMO exporter to be written** |
| packets_wanted | — | pmo→nesc | full | job_number | `{job_number}` |
| gis_sources | — | pmo→nesc | full | id | `{id, name, layers[{id, name, role}]}`; icons in `extras.gis_icons` |
| gis_features | — | pmo→nesc | full | layer\|feature_id | lookup candidate shape — **PMO exporter to be written** |
| pge_violations | — | pmo→nesc | full | ticket | `{ticket, payload}` — **PMO exporter to be written** |
| updates | — | API only | — | id | `UpdateOp` (POST /updates); never a package stream |

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
`{format, version, receipts: [...]}`. A receipt exists only for a package whose manifest verified.

## Signing (fail closed)

`Signer::sign()` = HMAC-SHA256 hex over `CanonicalJson::encode(manifest minus signature, strict: true)`.
Canonical JSON: keys sorted bytewise (lists keep order), no whitespace, unescaped slashes/unicode,
`serialize_precision = -1` pinned for the call; an empty object and an empty array both canonicalise to
`[]` (the array form of `json_decode` cannot tell them apart); `strict` refuses floats.

`KeyRing::fromEnvString("texstar:comcast=<secret>,other:pair=<secret>")` splits pairs on `,` and each
pair on the **first** `=` (base64 padding survives; a `,` cannot appear in a secret). A blank key id
or secret throws, so an unset environment yields an **empty** ring: `Signer` throws
(`unknown_key_id`), `Verifier` always fails. `Verifier` requires `alg`, a known non-empty `key_id`
and a hex value, compares with `hash_equals`, and throws `SignatureException` with
`signature_missing` (absent, `null` or incomplete), `unknown_key_id` or `signature_invalid`.

Exemption: `PackageBuilder::finalizeUnsigned()` writes `signature: null` and is permitted **only** when
the draft manifest `kind === 'rows'` (`UnsignedPackageException` otherwise). A rows mini-package is
staged with the gates skipped because the API principal already authenticated the rows; pushed through
the inbound upload as `kind = package` it is rejected.

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
record declares is cut off within one read buffer (`InflatedSizeException`, reason
`entry_overflow`) — libzip inflates a lying entry in full, so only the counter makes the cap real;
a stream whose bytes hash differently from the manifest, or a blob whose bytes/length differ from the
manifest and the entry name, is `HashMismatchException`. All of these carry the wire code
`unpacked_too_large` / `hash_mismatch` via `wireCode()`.

## Versioning

- manifest `version` must equal the reader's `MAJOR` (else `unsupported_version`);
- per-stream `schema` `"<stream>@<major>"`: unknown stream or higher stream major → skipped with a
  warning, never rejected (`Negotiation::evaluate()`);
- unknown fields are ignored; missing required fields reject the row `(key, seq)`; oversize or
  malformed lines reject the row;
- additive changes bump `contract` minor; renames/removals bump major. Ids stay un-namespaced in v1
  (`nesc:<tenant>:…` is v2). `latest_event_at` is a SQL datetime; every other timestamp is ISO-8601.

## Development

```
composer install
php vendor/bin/phpunit
bin/exchange schemas:audit
bin/exchange package:inspect some.txx.zip --keys=texstar:comcast=<secret> --max-unpacked-mb=2048
```
