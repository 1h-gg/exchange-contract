<?php

declare(strict_types=1);

namespace Texstar\Exchange\Stream;

use Texstar\Exchange\Contract;
use Texstar\Exchange\ExchangeException;

/**
 * The 18 file streams of v1 plus the API-only `updates` stream. Each row is today's bridge row byte-for-byte
 * plus `scope`; `origin`/`origin_ref` are optional on every row (Phase 1e sheet-origin rows).
 *
 * key: a list of row fields joined with '|', or ['kind' => [kind => field]] for kind-discriminated streams,
 * or ['const' => 'feed'] for single-row streams. See {@see RowKey}.
 */
final class StreamCatalogue
{
    public const MODE_DELTA = 'delta';

    public const MODE_FULL = 'full';

    /** Every stream's schema major in this contract version. */
    public const SCHEMA_MAJOR = 1;

    /** @var array<string, array<string, mixed>> */
    private const STREAMS = [
        'readiness' => ['neutral' => 'deliverables:readiness', 'direction' => Contract::DIRECTION_NESC_TO_PMO, 'mode' => self::MODE_DELTA, 'key' => ['job_number'], 'action' => 'push_p2_job_states',
            'notes' => 'push_p2_job_states row in P2ContractPinTest::PAYLOAD_KEYS order + backlog; tasks[] also carry system/external_task_id in package rows only'],
        'readiness_feed' => ['neutral' => null, 'direction' => Contract::DIRECTION_NESC_TO_PMO, 'mode' => self::MODE_FULL, 'key' => ['const' => 'feed'], 'action' => 'push_p2_job_states',
            'notes' => 'one row: P2ReadinessService::feedSummary()'],
        'inspection_reports' => ['neutral' => 'deliverables:report', 'direction' => Contract::DIRECTION_NESC_TO_PMO, 'mode' => self::MODE_DELTA, 'key' => ['external_report_id'], 'action' => 'push_inspection_reports',
            'notes' => 'REPORT_KEYS; content_base64 MUST be null; sha256 required; blob optional (files/<sha256>)'],
        'inspection_packets' => ['neutral' => 'deliverables:packet', 'direction' => Contract::DIRECTION_NESC_TO_PMO, 'mode' => self::MODE_DELTA, 'key' => ['external_id'], 'action' => 'push_inspection_packets',
            'notes' => 'PACKET_KEYS; photos[].sha256 nullable; file_url kept'],
        'review_decisions' => ['neutral' => null, 'direction' => Contract::DIRECTION_NESC_TO_PMO, 'mode' => self::MODE_DELTA, 'key' => ['external_decision_id'], 'action' => 'push_review_decisions',
            'notes' => 'decision enum confirm|reject|merged|merge (PMO accepts the merged alias)'],
        'observations' => ['neutral' => null, 'direction' => Contract::DIRECTION_NESC_TO_PMO, 'mode' => self::MODE_DELTA, 'key' => ['external_id'], 'action' => 'push_observations',
            'notes' => 'legacy obs item + external_id nesc:obs:{jb}:{field}:{sha8(value)}'],
        'worker_activity' => ['neutral' => 'sow_claims:presence', 'direction' => Contract::DIRECTION_NESC_TO_PMO, 'mode' => self::MODE_DELTA, 'key' => ['kind' => ['job' => 'job_number', 'assignment' => 'nesc_assignment_id', 'contractor' => 'id']], 'action' => 'push_worker_activity',
            'notes' => '{kind: job|assignment|contractor, ...WorkerActivityRunnerSync row}'],
        'external_refs' => ['neutral' => 'external_refs', 'direction' => Contract::DIRECTION_NESC_TO_PMO, 'mode' => self::MODE_DELTA, 'key' => ['entity_type', 'entity_key', 'system', 'external_id'], 'action' => null,
            'notes' => 'entity_key: Job -> job_number (incl. assignment:<id> bookings); JobTask -> external_task_id else synthetic_key else omitted; Pole/Inspection RESERVED. Emitted only when the drift check passes'],
        'jobs' => ['neutral' => 'jobs', 'direction' => Contract::DIRECTION_PMO_TO_NESC, 'mode' => self::MODE_DELTA, 'key' => ['job_number'], 'action' => 'pull_jobs',
            'notes' => 'NescSyncFeed::jobPayload + withBridgeAliases; only job_number required; cursor {updated_since, after_id}'],
        'poles' => ['neutral' => 'poles', 'direction' => Contract::DIRECTION_PMO_TO_NESC, 'mode' => self::MODE_FULL, 'key' => ['candidate_key'], 'action' => 'pull_pole_snapshot',
            'notes' => 'NescPoleSnapshotService record shape; set_checksum = sha256(sorted distinct candidate_keys joined "\n"); set_total'],
        'p2_runner_status' => ['neutral' => 'external_refs:p2_runner', 'direction' => Contract::DIRECTION_PMO_TO_NESC, 'mode' => self::MODE_DELTA, 'key' => ['kind' => ['report' => 'external_report_id', 'note' => 'pmo_note_id', 'rfa' => 'pmo_operation_id', 'job' => 'job_number']], 'action' => 'pull_p2_runner_status',
            'notes' => '{kind: report|note|rfa|job, ...P2RunnerStatusFeed row}; summary in extras.p2_runner_summary'],
        'jobmaster_status' => ['neutral' => 'external_refs:jobmaster', 'direction' => Contract::DIRECTION_PMO_TO_NESC, 'mode' => self::MODE_DELTA, 'key' => ['job_number'], 'action' => 'pull_jobmaster_status',
            'notes' => 'JobMasterStatusFeed row'],
        'worker_activity_status' => ['neutral' => 'external_refs:worker_activity', 'direction' => Contract::DIRECTION_PMO_TO_NESC, 'mode' => self::MODE_DELTA, 'key' => ['kind' => ['job' => 'job_number', 'assignment' => 'nesc_assignment_id']], 'action' => 'pull_worker_activity_status',
            'notes' => '{kind: job|assignment, ...WaRunnerNescIngest::pull row}; presence present|missing|unknown; summary in extras.worker_activity_summary'],
        'dailies' => ['neutral' => null, 'direction' => Contract::DIRECTION_PMO_TO_NESC, 'mode' => self::MODE_DELTA, 'key' => ['daily'], 'action' => 'dailies_since',
            'notes' => 'legacy dailies_since row incl. work_items[]; PMO exporter to be written'],
        'packets_wanted' => ['neutral' => null, 'direction' => Contract::DIRECTION_PMO_TO_NESC, 'mode' => self::MODE_FULL, 'key' => ['job_number'], 'action' => 'inspection_packets_wanted',
            'notes' => '{job_number}'],
        'gis_sources' => ['neutral' => null, 'direction' => Contract::DIRECTION_PMO_TO_NESC, 'mode' => self::MODE_FULL, 'key' => ['id'], 'action' => 'gis_sources',
            'notes' => '{id, name, layers:[{id, name, role, ...}]}; icons in extras.gis_icons'],
        'gis_features' => ['neutral' => null, 'direction' => Contract::DIRECTION_PMO_TO_NESC, 'mode' => self::MODE_FULL, 'key' => ['layer', 'feature_id'], 'action' => 'gis_lookup',
            'notes' => '{layer_id, layer, kind: pole|job|feature, feature_id, ref, lng, lat, properties (<=20 keys), geometry?}; PMO exporter to be written'],
        'pge_violations' => ['neutral' => null, 'direction' => Contract::DIRECTION_PMO_TO_NESC, 'mode' => self::MODE_FULL, 'key' => ['ticket'], 'action' => 'pge_lookup',
            'notes' => '{ticket, payload}; store only on NESC; PMO exporter to be written'],
        'updates' => ['neutral' => null, 'direction' => 'both', 'mode' => null, 'key' => ['id'], 'action' => null, 'api_only' => true,
            'notes' => 'UpdateOp rows; API only in 1b (POST /updates), never a package stream'],
    ];

    private function __construct() {}

    /** File stream names (the closed set a package may carry); `updates` is API-only and excluded. @return list<string> */
    public static function names(): array
    {
        return array_keys(array_filter(self::STREAMS, static fn (array $s) => ! ($s['api_only'] ?? false)));
    }

    /** @return list<string> */
    public static function allNames(): array
    {
        return array_keys(self::STREAMS);
    }

    public static function has(string $name): bool
    {
        return isset(self::STREAMS[$name]);
    }

    public static function isPackageStream(string $name): bool
    {
        return isset(self::STREAMS[$name]) && ! (self::STREAMS[$name]['api_only'] ?? false);
    }

    /** @return array{name: string, neutral: ?string, direction: string, mode: ?string, key: array, action: ?string, schema: string, notes: string, api_only: bool} */
    public static function get(string $name): array
    {
        $s = self::STREAMS[$name] ?? throw new ExchangeException('Unknown stream '.$name, 'unknown_stream');

        return ['name' => $name, 'schema' => self::schemaId($name), 'api_only' => (bool) ($s['api_only'] ?? false)] + $s;
    }

    /** @return list<string> */
    public static function forDirection(string $direction): array
    {
        return array_keys(array_filter(self::STREAMS, static fn (array $s) => $s['direction'] === $direction && ! ($s['api_only'] ?? false)));
    }

    public static function direction(string $name): string
    {
        return self::get($name)['direction'];
    }

    public static function mode(string $name): string
    {
        return self::get($name)['mode'] ?? self::MODE_DELTA;
    }

    /** "<stream>@<major>" as written in manifest files[].schema. */
    public static function schemaId(string $name): string
    {
        if (! isset(self::STREAMS[$name])) {
            throw new ExchangeException('Unknown stream '.$name, 'unknown_stream');
        }

        return $name.'@'.self::SCHEMA_MAJOR;
    }

    /** @return array<string, mixed> */
    public static function keySpec(string $name): array
    {
        return self::get($name)['key'];
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::STREAMS) as $name) {
            $out[$name] = self::get($name);
        }

        return $out;
    }
}
