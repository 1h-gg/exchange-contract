# texstar/exchange-contract (v1)

The wire truth of the **Texstar Exchange**: the package format PMO and NESC read and write when they
sync with or without the live HTTP bridge, the `/api/v1/exchange/*` runner API both apps expose, the
golden fixtures both apps test against, and the reference runner CLI. Plain PHP 8.3 — no Laravel in
`src/` (test-enforced) — so both apps, the owner's runner and PMO's future implementation depend on
the same code, the same JSON Schemas and the same fixtures.

Phase 1b ships this package and the NESC side (nescdev, branch `dycom-platform`). **The PMO side is
still to build.** It runs on pmotest against the same package, the same fixtures and the same OpenAPI
document. The [PMO implementation checklist](#pmo-implementation-checklist) at the end lists everything
it must do, [PMO deviations](#pmo-deviations) lists where PMO knowingly differs, and
[Owner questions](#owner-questions) lists what is still for the owner to decide. The
[Changelog](#changelog) lists what each contract minor added. NESC-side operations (keys, transport switch, first exchange by hand, caps, prune, rollback)
are in the NESC repo: `docs/HANDOVER-INVENTORY.md` §8c and `docs/04-integrations.md` "PMO ⇄ NESC exchange".

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
| `fixtures/v1/` | `keys.json` (test-only keys) and the sixteen fixture trees, each with `expectations.json` |
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
format: "texstar-exchange"          version: 1 (reader accepts == its MAJOR)      contract: "1.3.0"
kind: "package" | "rows"            package_id: uuid v4                            direction: nesc_to_pmo | pmo_to_nesc
created_at: ISO-8601                producer: {app: nesc|pmo, version, instance, contract}
scope: {tenant, contract, program, instance|null}   OPTIONAL — absent => Scope::UNSCOPED (texstar/comcast/comcast-nesc)
cursors: {<stream>: {from, to}}     opaque
files:  [{path, stream, schema: "<stream>@1", rows, sha256, bytes, mode: delta|full, set_checksum?, set_total?}]
blobs:  [{sha256, bytes, mime, path: "files/<sha256>"}]
receipts: [{package_id, path: "receipt.json", sha256, bytes}]   every entry: the same hash + length of receipt.json
extras: {readiness_feed?, p2_runner_summary?, worker_activity_summary?, gis_icons?, external_refs_skipped?, full_streams_unchanged?}
signature: {alg: "HMAC-SHA256", key_id: "<tenant>:<contract>", value: hex} | null   (null only when kind = rows)
```

Directions are the ASCII strings `nesc_to_pmo` / `pmo_to_nesc`; the plan's arrow is prose. The
manifest — `extras` included — is signed as **strict** canonical JSON, so it must not contain floats:
render rates and averages as strings or integers (the fixture `p2_runner_summary` does). Rows may
carry floats freely.

`producer.version` is informational and free-form (PMO stamps `dev` or its release, NESC
`config('version.current')`); consumers never gate on it. `producer.contract` carries the writer's
`Contract::VERSION`. `extras.full_streams_unchanged` (1.1.0) is a list of stream names; see
[Full streams](#full-streams-absence-means-unchanged).

### Streams

Row = today's bridge row byte-for-byte, plus `scope` (the builder stamps the manifest scope on rows
that lack it). `origin` / `origin_ref` are optional on every row (Phase 1e sheet-origin rows).
"Neutral name" is the source-neutral name reserved for 1.x renames; the wire name is the stream column.

| stream | neutral name | dir | mode | key | shape | PMO exporter to write |
|---|---|---|---|---|---|---|
| readiness | deliverables:readiness | nesc→pmo | delta | job_number | `push_p2_job_states` row in `P2ContractPinTest::PAYLOAD_KEYS` order + `backlog`; `tasks[]` also carry `system`, `external_task_id` (package rows only — the HTTP payload does not) | — (importer: `push_p2_job_states`) |
| readiness_feed | — | nesc→pmo | full | `feed` | one row: `P2ReadinessService::feedSummary()`; also in `extras.readiness_feed` | — |
| inspection_reports | deliverables:report | nesc→pmo | delta | external_report_id | `REPORT_KEYS`; `content_base64` MUST be null; `file_url` / `download_url` / `url` are typed `null` (1.1.0): consumers MUST ignore URL fields and resolve the PDF from `files/<sha256>` or `PUT`/`GET /files/{sha256}` only — a missing blob is the row reject `blob_missing`; consumers key storage on `job_number` (`job_id` is informational) | — (importer resolves `files/{sha}`) |
| inspection_packets | deliverables:packet | nesc→pmo | delta | external_id | `InspectionPacketOutboxService::packet()`; `photos[].sha256` nullable, `file_url` kept (built from the producer's `app.url`); consumers fetch `photos[].file_url` only over https from an allowlisted producer host — see [Consumer rules](#consumer-rules-both-importers) 6 | — |
| review_decisions | — | nesc→pmo | delta | external_decision_id | `decision` ∈ confirm, reject, merged, merge; `merged` is accepted as an alias of `merge` in the package path; a `merge`/`merged` row whose `candidate_key` PMO has never seen (neither a P2 candidate nor a durable record) is rejected `unknown_candidate`; `confirm`/`reject` of an unknown `candidate_key` are applied exactly as the HTTP ingest does (a record is minted) | — (importer accepts the `merged` alias) |
| observations | — | nesc→pmo | delta | external_id | legacy obs item `{jb, entity, entity_id, field, value, observed_at, note}` + `external_id` `nesc:obs:{jb}:{field}:{sha8(value)}`, ≤ 64 chars (`maxLength` 1.1.0; NESC's longest is ~52); PMO's exchange importer translates it to its `push_observations` ingest | — |
| worker_activity | sow_claims:presence | nesc→pmo | delta | kind\|id | `{kind: job\|assignment\|contractor, …WorkerActivityRunnerSync row}` | — |
| external_refs | external_refs | nesc→pmo | delta | entity_type\|entity_key\|system\|external_id | see below; emitted only when NESC's drift check passes (`extras.external_refs_skipped` otherwise) | — |
| jobs | jobs | pmo→nesc | delta | job_number | `NescSyncFeed::jobPayload()` + `withBridgeAliases` (`job_number`, `verdict`, `nesc_scope`, `poles.count`); only `job_number` required; `nesc_pole_candidates[].confidence` is `number\|string\|null` (1.1.0, the P2 label); cursor `{updated_since, after_id}` | exporter over `NescSyncFeed::pullPage` |
| poles | poles (+violations nested) | pmo→nesc | full | candidate_key | `NescPoleSnapshotService` candidateRow/recordRow (+ `ai_verdict`, `ai_rationale`, `ai`), record wins; rows in candidate_key order; `confidence` is `number\|string\|null` (1.1.0: PMO sends the P2 label `high\|medium\|low`, numeric when a model scored it); `set_checksum` = sha256(sorted distinct candidate_keys of the **shipped rows** joined `"\n"`) — `keysChecksum()` only as a change probe — and `set_total` | exporter over `snapshotPage` |
| p2_runner_status | external_refs:p2_runner | pmo→nesc | delta | kind\|id | `{kind: report\|note\|rfa\|job, …P2RunnerStatusFeed row}` incl. `jobs[].p2_tasks[]`; summary in `extras.p2_runner_summary` (float-free) | exporter over `P2RunnerStatusFeed::pull` |
| jobmaster_status | external_refs:jobmaster | pmo→nesc | delta | job_number | `JobMasterStatusFeed::pull` row | exporter over `JobMasterStatusFeed::pull` |
| worker_activity_status | external_refs:worker_activity | pmo→nesc | delta | kind\|id | `{kind: job\|assignment, …WaRunnerNescIngest::pull row}`; `presence` present\|missing\|unknown; summary in `extras.worker_activity_summary` | exporter over `WaRunnerNescIngest::pull` |
| dailies | — | pmo→nesc | delta | daily | legacy `dailies_since` row incl. `work_items[{seq, unit_code, quantity, qty_num, description}]`; `quantity` is a canonical decimal string without trailing zeros (`"2"`, `"1.5"`), `qty_num` its float | **yes — no PMO bridge producer exists** (fixture pins the NESC consumer shape, `PmoSyncService.php:201-283`) |
| packets_wanted | — | pmo→nesc | full | job_number | `{job_number}` (one row per `inspection_packets_wanted` job) | exporter over `inspection_packets_wanted` |
| gis_sources | — | pmo→nesc | full | id | `GisReferenceLayers::catalog()` source `{id, name, sort, layers[{id, name, role, …}]}`; icons in `extras.gis_icons` | exporter over `catalog()` + icons |
| gis_features | — | pmo→nesc | full | layer\|feature_id | `{layer_id, layer, kind: pole\|job\|feature, feature_id, ref, source, ref_raw, lng, lat, properties (≤20 keys), geometry?}` — the `NescGisController::lookup` candidate + identity; `layer_id` is null for synthetic layers (PMO's "Comcast jobs"), whose `feature_id` is `"<layer key>:<id>"`, e.g. `jobs:JB…`; pole rows use `"<layer id>:<feature_key>"` | **yes — no full-set producer exists** (the live lookup answers per ref) |
| pge_violations | — | pmo→nesc | full | ticket | `{ticket, payload}`; `payload` = `NescPgeController::violation()`; store only on NESC | **yes — no full-set producer exists** |
| updates | — | API only | — | id | `UpdateOp` (`POST /updates`); never a package stream | — |

`external_refs.entity_type` is neutral — `job | job_task | pole | inspection`, never an app class
name. `entity_key`: `job` → `job_number` (covers p2 / jobmaster / p2_runner / pmo / sphere refs,
worker_activity presence and the `assignment:<id>` booking refs, which `MirrorProjector::waBooking`
writes on the Job); `job_task` → `external_task_id`, else `synthetic_key`, else the row is omitted;
`pole` (`candidate_key`) and `inspection` (`nesc:inspection:<id>:r<rev>`) are **reserved** — no
projector writes them in 1b and PMO must not expect them. `entity_id` is informational. The JSON
column is `properties` (not `attributes`).

`RowKey::for(stream, row)` joins composite keys with `|` and prefixes kind-discriminated streams
(`job|JB000001`); receipts and verdicts address rows by `(key, seq)`, never by batch index.

#### Full streams: absence means unchanged

A full stream absent from a package means **unchanged since the last receipted set**; consumers never
treat absence as an empty set (NESC's `InboundDispatcher` runs each full-set consumer only when the
stream is present). Producers may skip an unchanged full set and list it in
`extras.full_streams_unchanged`. A refused full set (receipt stream `applied: false` or `refused`
set, or every row rejected) does not advance the producer's cursor; the set travels again.

### Receipt

`{format: "texstar-exchange-receipt", version: 1, package_id, receipt_id, produced_at, producer, scope,
streams: {<stream>: {received, accepted, rejected, accepted_all, rejected_rows: [{key, seq, code, message}], rejected_rows_omitted?, cursor_to, applied_at, applied?, refused?}},
status: applied|rejected|partial, message?}`. `applied` (boolean) and `refused` (string|null, 1.1.0,
optional): a stream with `applied: false` or a non-null `refused` had nothing applied; the producer
must not advance its cursor. `receipt.json` inside a package wraps a list:
`{format, version, receipts: [...]}`. `rejected` is the authoritative count and `accepted =
received - rejected`. `rejected_rows` lists at most 1,000 rejects per stream
(`ReceiptBuilder::MAX_LISTED_REJECTS`, 1.2.0) and `PackageBuilder::addReceipts()` lists fewer — halving
per stream until receipt.json fits under 4 MiB (`Contract::MAX_MANIFEST_BYTES`, the reader's limit) —
so one schema drift can never make a receipt refuse the next package. The rest are counted in
`rejected_rows_omitted` (integer, written only when > 0). When the list is complete
(`StreamReceipt::listComplete()`: nothing omitted and `count(rejected_rows) = rejected`), "accepted" is
every row of the stream not listed in `rejected_rows`. When it is not, a producer that settles per row
settles the listed rejects and keeps every unlisted row of the stream pending, so those rows travel
again. `seq` is the 0-based line in the stream file. `key` is `""` when the line could not be
parsed or keyed; `seq` still addresses it. The row codes NESC writes are `row_too_large`,
`invalid_json`, `schema_invalid` and `row_key` (a row that cannot be keyed for its stream), plus the
consumer's own refusals. PMO also writes `unknown_candidate`, `unknown_job`, `invalid_job_number`,
`invalid_external_id`, `invalid_value`, `invalid_row`, `unknown_kind`, `blob_missing`, `invalid_pdf`,
`hash_mismatch`, `conflict` and `rejected`; codes are open-ended and a producer treats any code as a
rejection. For `poles`, a whole-set refusal (checksum, out of order, sanity floor,
`large_drop`) makes the stream's receipt `partial`, never a failed apply.

Outbound bookkeeping, which PMO mirrors for its own outbox:
- A row's identity is `(stream, sha256(RowKey))` and its content is
  `sha256(CanonicalJson(row minus scope))`. A changed row stages a new generation, and an unchanged
  re-send stages nothing — unless the latest generation was **rejected** by a receipt: then the
  producer's next attempt stages it again (the HTTP bridge re-sends rejected rows the same way; the
  pusher's own attempt limits decide when it stops).
- A download holds every row no receipt has settled (`receipted_at` null), whichever earlier package
  carried it — never packaged, in a package whose download may never have reached the other side,
  in a failed build, or in a stream the receipt left out — except rows whose key has a newer
  generation. A new build re-stamps those rows onto itself and marks every older unreceipted
  `ready` package `superseded`; a late receipt for a superseded package settles only the rows it
  still holds. Consumers therefore see repeated rows and MUST upsert by key.
- `seq` is stamped at package time.
- `cursors[stream]` for the producer's own streams is `{from: last receipted package_id, to: this
  package_id}`. Cursors of the other side's feeds travel verbatim.
- A receipt for an older generation of a key never regresses a newer accepted payload, and replaying
  a receipt is a no-op.
- **PMO deviation (1b):** PMO keeps cursor-based deltas: a receipted package advances each delta
  stream's cursor even when rows were rejected, and a row over `max_row_bytes` is left out with a
  `row_too_large` warning; such rows travel again only when they change. A per-key retry set arrives
  with 1f. PMO's delta cursors are the bridge's keyset cursors and travel verbatim. Both cases are
  surfaced as warnings. PMO still applies Consumer rule 4 before advancing: a stream whose counts do
  not match the manifest (`received` ≠ `files[stream].rows`, or `accepted + rejected` ≠ `received`)
  does not advance (warning `counts_mismatch:<stream>`).

A receipt exists **only** for a package whose manifest verified: `applied`, or `rejected` after verify
(`out_of_order`, `unsupported_version`, `unexpected_entry`, `hash_mismatch`, `unpacked_too_large`).
Packages rejected before verify (`signature_invalid`, `signature_missing`, `unknown_key_id`,
`unknown_producer`, `direction_mismatch`, `scope_mismatch`) get none — their `rejected_code` is
visible only on `GET /inbound/{uuid}`, and they never claim their manifest's `package_id`.

### Consumer rules (both importers)

1. **Gates, in order:** signature (fail closed) → direction → producer → **scope binding**
   (`PackageReader::assertScope($local)`: `signature.key_id` must be the `"<tenant>:<contract>"` of the
   manifest's resolved scope — an unscoped manifest resolves to `texstar/comcast/comcast-nesc` — and
   that scope's tenant, contract and program must be the importer's own; else `scope_mismatch`) →
   record the `package_id` (only now) → **ordering** (a `package` whose `created_at` is older than
   one already applied is `out_of_order`: rejected with a receipt, so the producer re-sends its rows)
   → manifest schema / version / path discipline → declared size → `checkHashes()` (streams, blobs
   **and receipt.json**) → rows.
2. **Rows:** each line is `row_too_large`, `invalid_json`, `schema_invalid`, `row_key` (cannot be keyed)
   or `scope_mismatch` (its own `scope` names another tenant, contract or program), or accepted.
   Every reject is a `(key, seq)` entry of the receipt. The `malformed-rows` fixture pins the codes.
3. **Receipts carried in a package** are trusted only as far as the signed manifest: receipt.json must
   hash to `receipts[].sha256` / `bytes` (`hash_mismatch` rejects the whole package), and a receipt
   for a package id no `receipts[]` entry declares is ignored (`PackageReader::receipts()` does both).
4. **Every stream of a verified package appears in the receipt** — accepted, rejected per row, or the
   whole stream refused — with `received` equal to the rows the package carried in it and
   `accepted + rejected = received` (`rejected` counts every reject, listed or omitted; see
   [Receipt](#receipt)). A producer settles NOTHING in a stream whose counts do not add
   up (it keeps those rows pending), and a stream the receipt omits stays pending: omitting one means
   it is sent again. Streams with no ingest on the receiving side (`external_refs`, `readiness_feed`
   on PMO today) are still receipted: store them or accept-and-drop them, but answer for them.
   **Resend (decided 2026-09-28):** a row the receiver rejected travels again only when it changes
   (PMO's cursor-based deltas; see [PMO deviations](#pmo-deviations)). This is accepted until 1f's
   per-key retry set, on the condition that PMO's `ReceiptApplier` checks the counts above before it
   advances a stream.
5. **Delta ordering:** apply delta streams in package order; never store a cursor older than the one
   already stored (see rule 1's `out_of_order`).
6. **Media by hash, never by URL (SSRF):** `inspection_reports` URL fields are ignored (typed `null`);
   the PDF comes from `files/<sha256>` or `PUT`/`GET /files/{sha256}` only, and a missing blob is the
   row reject `blob_missing`. Consumers fetch `inspection_packets.photos[].file_url` only over https
   from an allowlisted producer host; otherwise media travels by sha256 (`files/<sha256>` or
   `/files/{sha256}`) and a URL-only photo is dropped (warning `photo_url_dropped`). **Production
   allowlist (decided 2026-09-28):** production PMO allows exactly `nesc.texstar.app`
   (`EXCHANGE_PHOTO_URL_HOSTS=nesc.texstar.app`); a test PMO names its own NESC test host.
7. **Full streams:** absence means unchanged; see [Full streams](#full-streams-absence-means-unchanged).
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
A valid signature is not enough on its own: `PackageReader::assertScope()` binds the key id to the
manifest's scope and the scope to the reader's instance (`scope_mismatch`), so with several pairs in
one ring a key can only sign its own (tenant, contract).

**Secrets:** at least 32 bytes — generate one with `openssl rand -hex 32` and agree it out of band. NESC
ignores (and logs by key id) any shorter secret outside its test suite; PMO should do the same. The
golden fixture keys in `fixtures/v1/keys.json` are short, public and test-only: never configure them.

**Exemption — `kind = rows`.** `POST /rows/{stream}` builds an unsigned mini package server-side
(`PackageBuilder::finalizeUnsigned()`, permitted only when the draft `kind === 'rows'`) and stages it
with the signature, producer and direction gates skipped, because the Sanctum principal holding
`exchange.apply` already authenticated the rows. **Delta streams only:** a full-mode stream replaces
the whole set, so `POST /rows` of `poles`, `packets_wanted`, `gis_sources`, `gis_features` or
`pge_violations` is 422 `full_stream_requires_package` — full sets travel only signed. The same zip pushed through `POST /inbound` is a
`kind = package` upload and is rejected `signature_invalid` (`signature_missing` on the wire): the
inbound upload crosses company lines and nothing unsigned crosses it. Fixture: `rows-kind`.

## Runner API (`/api/v1/exchange/*`, identical on both apps)

Bearer token only (a field login / pairing token or an API-client token). Every POST/PUT carries an
`Idempotency-Key` (1–128 chars); `429` answers carry `Retry-After`; every non-2xx body is the one
envelope `{"error": {"code", "message", "row"?: {stream, seq, key}, "correlation_id"?}}`; long
operations are async with a pollable status (`outbound: queued → building → ready → receipted | failed | superseded`;
`inbound: uploading → staging → previewed → applying → applied | failed | rejected`).

The reference document is `openapi/nesc-exchange.openapi.json` (OpenAPI 3.1, exported from NESC by
`php artisan scramble:export`, byte-diffed by NESC's `OpenApiSpecTest`, refreshed here with each slice that
changes the surface; served interactively at NESC's `/docs/api` for `exchange.view` holders). PMO's
implementation must satisfy the same document, `POST /updates` and `GET /updates/workbook` included (NESC
slice 8: ops as JSON or the edited XLSX workbook, verdicts `applied | unchanged | rejected | forbidden |
unknown_key | unsupported_until_1d` per op by `seq`, `op_id` and `key`; more than 500 ops answer 202 and
are polled at `GET /imports/{id}`).

```
GET  /status                                     transport, contract, scope, signing_configured, limits {max_upload_mb, max_package_mb, max_unpacked_mb, max_file_mb, max_blobs_mb, max_row_bytes, chunk_mb, max_chunks}, pending, cursors, external_refs
POST /outbound {include_blobs?, streams?, program?} -> 202 {package_id, status, status_url, reused?}
GET  /outbound/{id}   GET /outbound/{id}/download   GET /outbound/{id}/streams/{stream}?cursor=&limit=   (X-Next-Cursor, X-Has-More, X-Stream-Schema)
POST /inbound  multipart package (≤ max_upload_mb) + auto_apply?           -> 202 {import_id, status}
POST /inbound/{uuid}/chunks   POST /inbound/{uuid}/finalize   GET /inbound/{uuid}   POST /inbound/{uuid}/apply   GET /inbound/{uuid}/receipt
HEAD|GET|PUT /files/{sha256}                       files by hash; PUT re-hashes the body
POST /rows/{stream} (≤ 500 rows, ?dry_run=)        POST /updates (ops ≤ 2000 or a workbook)   GET /updates/workbook   GET /imports   GET /imports/{id}
```

- `limits` carries all eight caps as integers (`max_row_bytes` in bytes, the rest in MB or a count);
  `RunnerClient` reads any numeric limit it knows.
- `POST /outbound`: a producer may answer **200** with an unchanged `ready` unreceipted package (NESC)
  or **202** `reused: true` naming a build already in flight for the same scope (PMO); runners just
  poll `package_id`.
- `POST /rows/{stream}` takes `{rows: [...]}` (NESC also parses `application/x-ndjson`); rows never
  carry media (a non-null `content_base64` → 422 `validation_failed` with `row`); a stream that is
  unknown or of the wrong direction → 422 `validation_failed`; a full-mode stream → 422
  `full_stream_requires_package`.

### Auth, middleware order, idempotency, rate limits

- **Auth:** a Sanctum bearer only, either a field login / pairing token (ability `field`, `via: runner`)
  or an API-client token (ability `exchange`, `via: client`). There is no session fallback.
  API clients are optional (NESC 1b-10: a Super-Admin page issues them): a non-login service-account
  user holding only `exchange.view` / `exchange.apply` / `updates.bulk`, a token shown once, an
  expiry, an optional IP allowlist (decided on REMOTE_ADDR, never a forwarded header) and rate limit,
  and rotation that keeps the old token for 24 h. A disabled or deleted client is 401 `client_disabled`;
  an expired client is 401 `client_expired`; a rotated-out token past its grace is 401
  `token_rotated` (use the new token); a service-account token that is neither the client's current
  nor its rotated-out one is 401 `token_invalid`; a wrong address is 403 `ip_not_allowed` (the IP
  allowlist is decided on the connecting address only; forwarded headers are never trusted).
  Impersonation tokens, tokens with neither ability, inactive accounts and demo-mode accounts are
  refused. Permissions: `exchange.view` (reads, `POST /outbound`), `exchange.apply` (inbound, apply,
  `PUT /files`, rows), `updates.bulk` (updates, workbook). Every mutating call is audited with `via`
  and `token_name`.
- **Order** (as physical nested groups, since the framework only sorts priority-listed middleware):
  1. a pre-auth limiter per (client address, presented token id) (60/min, counted on 401s) — the
     token id is in the key so a flood of bad tokens from a (possibly spoofed) address cannot lock
     out a different, valid token
  2. `auth:sanctum`
  3. the access rules — including: the principal holds at least one of `exchange.view`,
     `exchange.apply`, `updates.bulk` (a field token without any is 403 `forbidden` here)
  4. `throttle:exchange` (120/min)
  5. per group `exchange-heavy` 10/min (build, single-shot upload, finalize, apply, rows, updates,
     the workbook export), `exchange-upload` 120/min (chunks) or `exchange-files` 240/min
     (download, files)
  6. the route's permission check
  7. Idempotency-Key — last, so a throttled or unauthorized request never streams a body, hashes an
     upload or creates an idempotency row.
- **Idempotency-Key** (1–128 chars) is required on every POST/PUT. Scope: (principal user, key).
  - Fingerprint: sha256 of method + path + canonical JSON of the non-file inputs + the sha256 of each
    uploaded file (in field order) + the raw-body sha256.
  - Same fingerprint → the stored answer with `Idempotency-Replayed: true`. A different fingerprint →
    409 `idempotency_conflict`. An attempt still inside its 15-minute lease → 409
    `idempotency_in_progress`.
  - Only 2xx and 4xx answers other than 401 / 403 / 429 are stored, for 24 h, under their original
    status (a stored 202 replays as 202, also when the body is replaced by the `{id, status,
    status_url}` summary). A retry after a 401, 403, 429 or 5xx runs for real.
    `RunnerClient` reuses one v4 key for retries of the same request (same method, path, inputs and file hash) and mints a fresh one when anything changes.

### Errors

Every non-2xx is `{"error": {"code", "message", "row"?: {stream, seq, key}, "correlation_id"?}}`.
NESC answers:

| status | codes |
|---|---|
| 400 | `idempotency_key_required`, `hash_mismatch` |
| 401 | `unauthenticated` (a missing, expired or revoked token; there is no `token_expired`); API clients: `client_disabled` (disabled or deleted), `client_expired` (the client expired — an admin must extend it), `token_rotated` (a rotated-out token past its 24 h grace — use the new one), `token_invalid` (a service-account token that is neither current nor previous); the IP allowlist is decided on the connecting address only |
| 403 | `forbidden` (also: no exchange permission at all), `impersonation_token`, `account_disabled`, `demo_mode`, `not_owner`, `ip_not_allowed` (API clients) |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 409 | `conflict` (also: an apply already claimed), `idempotency_conflict`, `idempotency_in_progress`, `too_many_uploads` (more than three unfinished chunked uploads per principal) |
| 413 | `package_too_large`, `unpacked_too_large`, `file_too_large` |
| 422 | `validation_failed` (with `row` for `rows.N.*` / `ops.N.*`), `full_stream_requires_package` (`POST /rows` of a full-mode stream), `signature_invalid`, `unknown_producer`, `unsupported_version`, `direction_mismatch`, `unexpected_entry`, `unsupported_media` |
| 429 | `rate_limited` + `Retry-After` |
| 503 | `signing_not_configured` |
| any | `http_<status>` for any other HTTP exception |
| 500 | `server_error` with a fixed message and a `correlation_id`; the exception text never reaches the body |

An import's `rejected_code` (not an HTTP status) is one of `signature_invalid`, `direction_mismatch`,
`unknown_producer`, `scope_mismatch`, `out_of_order`, `unsupported_version`, `unexpected_entry`,
`unpacked_too_large`, `hash_mismatch`. A token holding neither ability is 403 `forbidden` on both
apps. `RunnerClient` stops on any 401/403, so the distinct 401 codes are operational only.

### Inbound: 200 vs 202

- `POST /inbound` for bytes (sha256) or a producer `package_id` already
  `uploading|staging|previewed|applying|applied` answers **200** with that import and never re-stages
  it.
- A `rejected|failed` import re-sent by its owner is re-staged in place (**202**). From anyone else it
  is **409** `conflict`.
- A new package is **202** `{import_id, status}`, and `status_url` is polled until `previewed` (or
  `applied` with `auto_apply`), `rejected` or `failed`. `rejected_code` on `GET /inbound/{uuid}` names
  a pre-verify refusal, which has no receipt.
- A verified import's `package_id` under different bytes → **200** with that settled import at
  accept (nothing re-staged); if it reaches staging while the other import is unsettled → **409**
  `conflict`.

### Chunk lifecycle (client uuid)

The **client** generates a v4 uuid (a principal may hold at most three unfinished uploads: 409
`too_many_uploads`; an upload idle for 24 h is removed by `exchange:prune`). The first
`POST /inbound/{uuid}/chunks` creates the `uploading`
import owned by the caller (`409 conflict` when the uuid exists under another owner); later chunks,
`finalize` and `apply` require the same owner; `finalize`/`apply` on an unknown uuid → `404`. Each
chunk carries `chunk_index`, `total_chunks` (≤ `max_chunks`) and `checksum` (sha256 of the chunk);
resending an index with the same checksum is idempotent, a different checksum is `409`. Chunk bodies
are ≤ `max_upload_mb`; the running assembled total is ≤ `max_package_mb` (`413 package_too_large` at
chunk time). `finalize {total_chunks, sha256, auto_apply?}` checks the assembled hash
(`400 hash_mismatch`). `RunnerClient::uploadInbound()` does all of this from the `limits` in `GET /status`.

| situation | answer |
|---|---|
| `total_chunks` outside 1..`max_chunks`, `chunk_index` outside 0..total-1 | 422 `validation_failed` |
| a chunk body over `max_upload_mb` (checked on every chunk) | 413 `package_too_large` |
| running total over `max_package_mb` (checked on every chunk; the import is failed, chunks removed) | 413 `package_too_large` |
| chunk body sha256 ≠ `checksum` | 400 `hash_mismatch` |
| a 4th concurrent `uploading \| staging` import of the principal | 409 `too_many_uploads` |
| later chunks for a uuid owned by another principal | 409 `conflict` |
| `total_chunks` differing from the value the first chunk pinned | 409 `conflict` |
| the same index re-sent with the same checksum | 200 `idempotent: true` |
| the same index re-sent with a different checksum | 409 `conflict` |
| finalize / apply by another principal | 403 `not_owner` |
| finalize of an import that is no longer `uploading` | 409 `conflict` |
| a missing or extra index at finalize | 409 `conflict` (message names the counts) |
| assembled sha256 ≠ the declared `sha256` | 400 `hash_mismatch` (chunks kept for a retry) |
| Idempotency-Key reused with a different body | 409 `idempotency_conflict` |
| `PUT /files` over `max_file_mb` / mime outside the allowlist | 413 `file_too_large` / 422 `unsupported_media` |
| a stream the built package does not carry (`GET /outbound/{id}/streams/{stream}`) | 404 `not_found` |

An idle `uploading` import is removed after 24 h (`exchange:prune`, `upload_ttl_hours`); the uuid may
then be reused.

### Size caps

| cap | default | applies to |
|---|---|---|
| `max_upload_mb` | **60** | any single HTTP body: a single-shot package, one chunk, a page upload |
| `max_package_mb` | **200** | the assembled total of a chunked package |
| `max_unpacked_mb` | **2048** | the declared-size pre-check AND the inflated-bytes guard in `PackageReader` (`413 unpacked_too_large`) |
| `max_file_mb` | **30** | `PUT /files/{sha256}` and blob entries |
| `max_blobs_mb` | 200 | all blobs of one outbound package (`include_blobs`) |
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
(`kind`, `direction`, `key_id`, `scope`, `verify`, `scope_binding`, `validate`/`violations`, `hashes`,
per-stream `rows`/`rejects`/`rejected_rows`, `skipped`, `receipts`, `source` = the producer file:line
each stream was derived from, `pins`, and the `build` block `fixtures:sign` uses). Every manifest is
signed with `fixtures/v1/keys.json` (`texstar:comcast` = `golden-fixture-key`, plus `other:pair` for
the key-binding negative; test-only, short on purpose — never configure them).

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
| `tampered-receipt` | signed; receipt.json edited afterwards (a rejected row turned accepted) → `hash_mismatch`, `receipts()` refuses it |
| `malformed-rows` | one `dailies` stream: a valid row, then `schema_invalid`, `invalid_json`, `row_key` at seq 1-3 (`rejected_rows`) |
| `foreign-program` | correctly signed for texstar/comcast but program `comcast-other` → `scope_mismatch` |
| `key-scope-mismatch` | declares texstar/comcast, signed with the ring's `other:pair` key → verifies, then `scope_mismatch` |

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
bin/exchange sync --from=nesc --to=pmo --from-url=https://nesc.example --to-url=https://pmo.example [--both] [--include-blobs] [--no-files] [--program=comcast-nesc] [--timeout=600] [--keep]
bin/exchange push-updates changes.xlsx --url=https://nesc.example [--dry-run] [--create]
bin/exchange fixtures:sign [--fixture=NAME]   bin/exchange fixtures:build NAME [--out=ZIP]   bin/exchange suite:run
bin/exchange schemas:audit                    bin/exchange package:inspect some.txx.zip --keys=texstar:comcast=<secret> --max-unpacked-mb=2048
```

Tokens come, best first, from `--from-token-file` / `--to-token-file` / `--token-file`, the
environment (`EXCHANGE_FROM_TOKEN`, `EXCHANGE_TO_TOKEN`, `EXCHANGE_TOKEN`), or the flags
`--from-token` / `--to-token` / `--token` — accepted with a warning, since a flag shows in `ps` and
shell history. `RunnerClient` refuses a non-https base URL unless the host is localhost / 127.0.0.1 /
::1 or `--insecure` (`allowInsecure: true`) is given. `sync`
builds "everything not yet receipted" on the FROM app, waits, downloads the signed zip (checked
against `X-Package-Sha256`), copies the files the package references but does not carry, uploads it
to the TO app with `auto_apply`, waits, prints the receipt;
`--both` runs the opposite direction next, which carries the receipt back. The runner talks to both
`/api/v1/exchange` surfaces itself — neither app needs to reach the other. `RunnerClient` is the same
code with `ext-curl` behind a callable transport (`tests/Support/FakeTransport.php` shows the shape).

**Files (1.1.x):** a package built without `include_blobs` names each report PDF by `sha256` only, and
the consumer rejects such a row `blob_missing` unless the file is already in its store. So between the
download and the upload `sync` reads every `inspection_reports` row's `sha256`
(`SyncCommand::FILE_REF_STREAMS`) that is not a `files/<sha256>` entry, sends `HEAD /files/{sha256}` to
the TO app and, only for a file it lacks, `GET /files/{sha256}` from the FROM app and
`PUT /files/{sha256}` to the TO app. It prints `files: N referenced, N in package, N already on <to>,
N copied, N failed`. A failed copy is printed and the upload still goes ahead, so the TO app rejects just
those rows. `--no-files` skips the step. Packet photos are not copied: they travel by
`photos[].file_url` (Consumer rule 6).

## Development

```
composer install
php vendor/bin/phpunit
bin/exchange schemas:audit && bin/exchange suite:run
```

NESC depends on this package through a composer **path repository** (`../../exchange-contract`,
symlinked): a checkout of nescdev needs this repo as a sibling of `htdocs/` — see the NESC HANDOVER.

## Deviations from the plan

1. **No multi-GB uploads.** The plan's "a chunked 2 GB upload resumes after an interruption" is replaced
   by the caps: 60 MB per single body, 200 MB per assembled package, 2048 MB unpacked. Multi-GB
   packages are out of scope, and media travels by sha256 through `/files/{sha256}`.
2. **Readiness task fields are package-only.** `readiness` `tasks[].system` / `tasks[].external_task_id`
   ship in package rows only. The HTTP `push_p2_job_states` payload is unchanged.
3. **Neutral names are reserved.** The neutral stream names are reserved for 1.x; the system-specific
   streams ship byte-for-byte as today's bridge rows.
4. **ASCII directions.** Wire directions are `nesc_to_pmo` / `pmo_to_nesc`.

## PMO deviations

Where PMO knowingly differs from NESC. Each is interoperable: the runner and the NESC importer read
both forms.

| area | NESC | PMO (1b) | why it interoperates |
|---|---|---|---|
| outbox (delta streams) | per-key generations; a rejected row re-stages on the next attempt | cursor-based deltas: a receipted package advances the cursor even when rows were rejected; a row over `max_row_bytes` is left out (`row_too_large` warning); such rows travel again when they change; per-key retry set in 1f | NESC's receipt applier only settles NESC's own packages; NESC's importer answers `cursor_to` = the manifest cursor even with rejects; PMO's cursors are the bridge's keyset cursors (see [Receipt](#receipt)). PMO still enforces Consumer rule 4 (`counts_mismatch:<stream>`) |
| `POST /outbound` reuse | 200 with an unchanged ready unreceipted package | 202 `reused: true` for a queued/building build of the same scope | runners read `package_id` and poll |
| `POST /rows` body | JSON `{rows}` or NDJSON | JSON `{rows}` only | `RunnerClient` always sends JSON |
| `GET /files/{sha256}` of a report | `exchange.view` | also needs `exchange.apply` (403 `forbidden`); `HEAD` needs view | PMO-only hardening; NESC never fetches PMO reports |
| `review_decisions` over HTTP | `PmoReviewPushService` sends `merged` | the frozen HTTP ingest rejects `merged`; the package path aliases it | see [Owner questions](#owner-questions) |
| full streams | always ships the set it has | may skip an unchanged set and list it in `extras.full_streams_unchanged` | absence means unchanged on both sides |
| `producer.version` | `config('version.current')` | `dev` or its release | informational; nobody gates on it |

## Owner questions

| question | options | state |
|---|---|---|
| `review_decisions` `merged` over HTTP | NESC's HTTP `pushMergeDecision` (`PmoReviewPushService`) sends `merged`, which PMO's frozen HTTP ingest (`NescDecisionIngest`) rejects; PMO maps `merge` to `confirmed` on the absorbed candidate and ignores `merged_into_record_id`. (a) NESC sends `merge` over HTTP — changes production data; (b) PMO's HTTP ingest accepts the `merged` alias; (c) leave it until the transport switch | **decided 2026-09-28: (c)** — left as it is; NESC pins the absorbed record. The package path already aliases `merged` → `merge` and limits `unknown_candidate` to merge rows |
| API-client refusal codes | NESC 1.1.0 answers `token_rotated` / `token_invalid` as PMO does, and PMO answers 403 `forbidden` for a token with neither ability as NESC does | **decided 2026-09-28:** PMO adopts NESC's separate 401 codes `token_rotated` and `token_invalid` (each tells the operator something different); shipped on NESC (1b), PMO follows in 1d |
| `permit` as an `UpdateOp` entity | add `permit` to `UpdateOp::ENTITIES` and the schema enum in 1d, or keep permits out of the bulk surface | **decided 2026-09-28:** added in 1.3.0, keyed by permit number (`JB…\|permit_number` when a number repeats). PMO applies it through its permit override path; NESC keeps no permits and rejects the op |
| confidence transport switch | PMO drops `confidence_label` and sends the label in `confidence` only after both apps run 1.1.0 (a 1.0.0 reader rejects a string confidence as `schema_invalid`) | **decided 2026-09-28:** no switch to package sync for poles until both apps run contract 1.2.0 or later |

## Changelog

### 1.3.0 (2026-09-28)

Additive, but still a wire change; manifest `version` / `MAJOR` stays 1 and no fixture changes.

- **`permit` is an `UpdateOp` entity:** `UpdateOp::ENTITIES` and the `entity` enum of
  `updates.schema.json` gain `permit`. The key is the permit number, or `JB…|permit_number` when a
  number repeats. A 1.2.0 reader fails the whole request 422 `validation_failed` on a permit op, so
  **do not send permit ops to a 1.2.0 reader** (check `producer.contract` or `GET /status`
  `contract.version` first).
- Which ops, entities and fields a receiver applies is its own capability: an op it cannot apply yet is
  answered `unsupported_until_1d`, and an op on an entity it keeps no rows of is answered `rejected`
  whatever its key (never `unknown_key`). PMO applies permit ops through its permit override
  path; NESC keeps no permits.
- Owner decisions of 2026-09-28 recorded: [Owner questions](#owner-questions), Consumer rules 4
  (resend on change until 1f) and 6 (production photo allowlist `nesc.texstar.app`).

### 1.2.0 (2026-09-28)

Additive, plus one schema correction; manifest `version` / `MAJOR` stays 1. `Contract::VERSION` is
1.2.0 so `producer.contract` tells a peer on the corrected `p2_runner_status` schema from one on
1.1.0 (ed04328), which rejects every PMO job row with a `target_task` as `schema_invalid`.

- **Wire schema change:** `p2_runner_status.target_task` is typed `object|null` (`{record_id, name,
  type, status, hold}`, what PMO stores and NESC's mirror keeps) instead of `string|null`. The string
  type was a skeleton mistake: the first live round trip rejected 2,668 of PMO's 4,099 job rows
  `schema_invalid`. The `pmo-to-nesc-basic` fixture now ships the object and is re-signed. Both apps
  must run 1.2.0 before PMO's first production `pmo_to_nesc` build.
- **Receipt size bound:** `receipt.schema.json` `$defs.stream_receipt` gains optional
  `rejected_rows_omitted` (integer). `ReceiptBuilder` lists at most 1,000 rejected rows per stream
  (`MAX_LISTED_REJECTS`, `listAtMost()`) and counts the rest; `rejected` is always the full count.
  `PackageBuilder::addReceipts()` halves the per-stream listing until receipt.json fits under
  `MAX_MANIFEST_BYTES` and throws `receipt_too_large` only if it cannot fit with nothing listed.
  Before this a receipt with ~30k rejects (about 141 bytes each) passed the reader's 4 MiB limit and
  every later package carrying it was refused `package_too_large`. `StreamReceipt::listComplete()`
  tells a consumer whether unlisted rows may be treated as accepted; NESC's receipt applier keeps the
  unlisted rows of an incomplete list pending.
- `bin/exchange sync` copies the report files a package references but does not carry
  (`HEAD`/`GET`/`PUT /files/{sha256}`) before the upload, and gains `--no-files`. Found in the first
  live nescdev ⇄ pmotest round trip: without it every report row was rejected `blob_missing`. The wire
  contract does not change (reference client only).

### 1.1.0 (2026-09-28)

Additive or widening; manifest `version` / `MAJOR` stays 1 and nothing is renamed or removed. Every
fixture except `pmo-to-nesc-basic` stays at contract 1.0.0 to prove backward reads.

- `poles.confidence` and `jobs.nesc_pole_candidates[].confidence` widen to `number|string|null` (the
  P2 label `high|medium|low`). **Sequencing:** a 1.0.0 reader rejects a string as `schema_invalid`,
  so PMO sends the label only after both apps run 1.1.0. NESC stores `(string) confidence` as the HTTP
  bridge does, so the row checksum does not jump at the transport switch.
- `receipt.schema.json` `$defs.stream_receipt` gains optional `applied` (boolean) and `refused`
  (string|null).
- `manifest.schema.json` `extras` gains optional `full_streams_unchanged` (list of stream names).
- Tightened, no current producer affected: `observations.external_id` `maxLength` 64 (NESC's ids are
  ~52 chars); `inspection_reports` `file_url` / `download_url` / `url` typed `null` (NESC never sends them).
- Descriptions: `inspection_packets.photos[].file_url` (https + allowlisted host only), `dailies`
  `work_items[].quantity` (trimmed decimal string), `gis_features` synthetic layers.
- Fixture `pmo-to-nesc-basic` (contract 1.1.0): the receipt answers for `readiness_feed`; pole row 1 and
  job row 0's first candidate carry `confidence: "high"`; the `gis_features` job row is PMO's synthetic
  layer (`layer_id: null`, `feature_id: "jobs:JB0002685018"`); `poles_set_checksum_note` says "over the
  shipped rows". `set_checksum` is unchanged.
- `ContractSuite::build()` records its `tempnam()` placeholder so `cleanup()` removes it.
- NESC: `GET /status.limits` lists all eight caps; API clients answer `token_rotated` /
  `token_invalid`; OpenAPI refreshed.
- README: Consumer rules 6-7, full-stream absence, receipt code list, chunk lifecycle table, inbound
  same-`package_id` rule, `POST /outbound` reuse, `POST /rows` body rules, `POST /updates` verdict
  shape, PMO deviations, owner questions.

### 1.0.0

Phase 1b initial release.

## PMO implementation checklist

What the PMO side must do to pass `ContractSuite` and interoperate with the NESC side:

1. **Reader/importer:** the [Consumer rules](#consumer-rules-both-importers): `verify` → direction →
   producer → `assertScope` → package_id → ordering → `validateManifest` → `declaredSize` →
   `checkHashes` (receipt.json included) → rows, under the inflate cap; refuse producers other than
   `nesc`, directions other than `nesc_to_pmo`, unsigned or unknown-key manifests, a key that is not
   the scope's own and a foreign program; skip unknown streams and higher stream majors with a
   warning; answer for every stream in the receipt.
2. **Receipts only after verify**, keyed by `RowKey` + `seq` (0-based line in the stream file) — never
   by batch index: translate `P2RunnerNescIngest` `errors[].index` and `WaRunnerNescIngest`'s silent
   skips into `(key, seq)` rows. Ship them in the next `pmo_to_nesc` package AND on `GET /inbound/{uuid}/receipt`.
3. **`review_decisions`:** `merged` is accepted as an alias of `merge` in the package path; a
   `merge`/`merged` row whose `candidate_key` PMO has never seen (neither a P2 candidate nor a durable
   record) is rejected `unknown_candidate`; `confirm`/`reject` of an unknown `candidate_key` are applied
   exactly as the HTTP ingest does (a record is minted). PMO's `StreamApplier` applies the
   `unknown_candidate` pre-check only when the scrubbed decision is `merge`.
4. **`inspection_reports`:** `content_base64` is always null; consumers MUST ignore URL fields
   (`file_url` / `download_url` / `url`, typed `null`) and resolve the PDF from `files/<sha256>` or
   `PUT`/`GET /files/{sha256}` only, into `content_base64` before calling the existing ingest; a missing
   blob is the row reject `blob_missing`. Key storage on `job_number`.
5. **Exporters:** wrap `NescSyncFeed::pullPage`, `NescPoleSnapshotService::snapshotPage` (record wins,
   candidate_key order, `set_checksum` over the shipped rows' distinct candidate_keys — `keysChecksum()`
   only as a change probe), `P2RunnerStatusFeed::pull`,
   `JobMasterStatusFeed::pull`, `WaRunnerNescIngest::pull` (add `kind`), `inspection_packets_wanted`,
   `GisReferenceLayers::catalog()` + icons; **write** the three missing producers — `dailies`
   (legacy `dailies_since` row incl. `work_items[]`), `gis_features` (the full pole/job feature set in
   the lookup-candidate shape) and `pge_violations` (`{ticket, payload}`). Fold `truncated` / `more` /
   `has_more` into `X-Has-More` on `GET /outbound/{id}/streams/{stream}`; PMO cursors travel verbatim.
6. **Unscoped packages apply to program `comcast-nesc`** (`Scope::UNSCOPED`); stamp the resolved scope on
   every row and echo it in receipts.
7. **Mirror the caps** (60 / 200 / 2048 / 30 / 200 MB, 1 MiB rows, 8 MB chunks, 4000 chunks) and advertise
   all eight in `GET /status.limits`; mirror the `kind = rows` exemption exactly (unsigned only through
   `POST /rows/{stream}`, never through `POST /inbound`).
8. **Manifest `extras` are float-free** (strict canonical signing): `p2_runner_summary` rates as strings.
9. **Timestamps:** ISO-8601 everywhere except `latest_event_at` (SQL datetime).
10. Run `FixturesTest`'s counterpart: `ContractSuite::cases()` through the PMO reader, and the PMO
    emitters against `pmo-to-nesc-basic/expectations.json` `source` lines.
11. **Observations:** a row is the legacy obs item. Map `field nesc_pole_count` → `observation_type
    pole_count` and every other field → `note` (per-pole `field_gps` expansion is a later seam).
    `external_id` is ≤ 64 chars (schema `maxLength`); a longer one is a `(key, seq)` reject.
12. **The same `/api/v1/exchange/*` surface as `openapi/nesc-exchange.openapi.json`:**
    - bearer-only auth with the same refusals, and the same three permissions on the owner's role
    - the limiters in the same physical order (pre-auth IP limiter first, heavy limiters before
      Idempotency-Key)
    - the same Idempotency-Key fingerprint and lease
    - the envelope and its codes
    - the async status models (`outbound: queued → building → ready → receipted | failed | superseded`;
      `inbound: uploading → staging → previewed → applying → applied | failed | rejected`), 202
      `{id, status, status_url}`
    - the client-uuid chunk lifecycle with owner checks
    - `GET /files/{sha256}` serving registered shas only, and `PUT /files` re-hashing the body and
      sniffing the mime against `[application/pdf, image/jpeg, image/png, image/webp, application/zip]`
    - optionally, API clients as NESC ships them: service accounts that can never sign in, be paired,
      impersonated, approved or given a role; `GRANTABLE` permissions only; the token stored hashed;
      the same 401 / 403 codes above
13. **Keys:** the same secret under the same key id (`texstar:comcast`), parsed the same way (split on
    `,`, then the first `=`). A blank or malformed ring fails closed (no build, and every package is
    `signature_invalid`). Never log the secret.
14. **Outbox semantics** as in [Receipt](#receipt): identity + fingerprint + generation (a rejected
    latest generation is re-staged on the next attempt), `seq` stamped at package time, and every
    unsettled row per download with older unreceipted packages superseded. PMO's receipt for a NESC
    package rides in PMO's next package (receipt.json bound by `receipts[].sha256` / `bytes`) AND is
    served on `GET /inbound/{uuid}/receipt`. PMO (1b) keeps cursor-based deltas instead (a receipted
    package advances each delta stream's cursor even when rows were rejected; a row over
    `max_row_bytes` is left out with a `row_too_large` warning; such rows travel again only when they
    change; a per-key retry set arrives with 1f; PMO's delta cursors are the bridge's keyset cursors and
    travel verbatim) — see [PMO deviations](#pmo-deviations). PMO's `ReceiptApplier` must enforce
    Consumer rule 4: mismatched counts → do not advance that stream, warning `counts_mismatch:<stream>`.
15. **Bulk updates** (`POST /updates`, `GET /updates/workbook`):
    - the same verdict vocabulary (`applied | unchanged | rejected | forbidden | unknown_key |
      unsupported_until_1d`), ≤ 500 ops synchronous and more queued (202), ≤ 2000 ops per request
    - blank = unchanged, `CLEAR` = clear
    - PMO decides which of its fields are writable. Everything else answers `unsupported_until_1d`
      until the EntityMutator equivalent exists, and an entity PMO keeps no rows of answers `rejected`.
    - each verdict is `{seq, op_id, key, entity, field, op, outcome, message}` (`RowVerdict::toArray()`
      + extra); an op failing `updates.schema.json` fails the whole request 422 `validation_failed`
      with `row {stream: null, seq, key: null}`
    - `permit` is an `UpdateOp` entity from 1.3.0 (decided 2026-09-28; see [Owner questions](#owner-questions)):
      key = the permit number, or `JB…|permit_number` when a number repeats; PMO applies it through its
      permit override path (`PermitFieldOverrides`, `origin` `exchange`). A PMO reader must be on 1.3.0
      before it is sent permit ops.
16. **Package-mode schedule:** with PMO's bridge unused, PMO's own pulls from NESC do not exist (PMO never
    called NESC). Nothing on PMO needs a transport switch, only the Import Center kind `nesc:exchange`
    and a "Download for NESC" button.
17. **Proof:** one full loop `bin/exchange sync --from=nesc --to=pmo --both` between nescdev and pmotest,
    with the end state compared to the HTTP bridge (the plan's verification item; NESC's
    `RoundTripParityTest` covers the NESC half).

Package distribution to PMO: PMO consumes this repo through the read-only bare mirror
`/srv/texstar/exchange-contract.git` (composer vcs, `dev-main`). After every commit here, refresh it:
`git --git-dir=/srv/texstar/exchange-contract.git fetch /home/texstarnescdev/exchange-contract '+refs/heads/*:refs/heads/*'`,
then run `composer update texstar/exchange-contract` on PMO.
