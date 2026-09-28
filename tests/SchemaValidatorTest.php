<?php

declare(strict_types=1);

namespace Texstar\Exchange\Tests;

use PHPUnit\Framework\TestCase;
use Texstar\Exchange\Schema\KeywordAudit;
use Texstar\Exchange\Schema\SchemaException;
use Texstar\Exchange\Schema\SchemaRegistry;
use Texstar\Exchange\Schema\SchemaValidator;
use Texstar\Exchange\Stream\StreamCatalogue;
use Texstar\Exchange\Tests\Support\Packages;

final class SchemaValidatorTest extends TestCase
{
    use Packages;

    private function keywords(array $violations): array
    {
        return array_map(static fn ($v) => $v->path.':'.$v->keyword, $violations);
    }

    public function test_types_required_enum_const_and_bounds(): void
    {
        $schema = [
            'type' => 'object', 'required' => ['a', 'b'],
            'properties' => [
                'a' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'b' => ['type' => ['string', 'null'], 'minLength' => 2, 'maxLength' => 3, 'pattern' => '^[a-z]+$'],
                'c' => ['enum' => ['x', 'y']], 'd' => ['const' => 'texstar-exchange'],
                'e' => ['type' => 'array', 'items' => ['type' => 'boolean'], 'minItems' => 1, 'maxItems' => 2],
                'f' => ['type' => 'number'],
            ],
        ];
        $v = new SchemaValidator;

        $this->assertSame([], $v->validate(['a' => 3, 'b' => null, 'c' => 'x', 'd' => 'texstar-exchange', 'e' => [true], 'f' => 1.5, 'unknown' => 'ignored'], $schema));
        $this->assertSame(
            ['$.b:required', '$.a:minimum', '$.c:enum', '$.d:const', '$.e:maxItems', '$.e[2]:type', '$.f:type'],
            $this->keywords($v->validate(['a' => 0, 'c' => 'z', 'd' => 'other', 'e' => [true, false, 'no'], 'f' => '1'], $schema)),
        );
        $this->assertSame(['$.b:minLength', '$.b:pattern'], $this->keywords($v->validate(['a' => 1, 'b' => 'A'], $schema)));
        $this->assertSame(['$:type'], $this->keywords($v->validate('not an object', $schema)));
    }

    public function test_integer_accepts_whole_floats_and_objects_versus_arrays(): void
    {
        $v = new SchemaValidator;
        $this->assertSame([], $v->validate(2.0, ['type' => 'integer']));
        $this->assertSame(['$:type'], $this->keywords($v->validate(2.5, ['type' => 'integer'])));
        $this->assertSame([], $v->validate([], ['type' => 'object']));
        $this->assertSame([], $v->validate([], ['type' => 'array']));
        $this->assertSame(['$:type'], $this->keywords($v->validate([1, 2], ['type' => 'object'])));
        $this->assertSame(['$:type'], $this->keywords($v->validate(['a' => 1], ['type' => 'array'])));
        $this->assertSame(['$:type'], $this->keywords($v->validate(true, ['type' => 'number'])));
    }

    public function test_formats(): void
    {
        $v = new SchemaValidator;
        $dt = ['type' => 'string', 'format' => 'date-time'];
        $this->assertSame([], $v->validate('2026-09-27T12:00:00Z', $dt));
        $this->assertSame([], $v->validate('2026-09-27T12:00:00.123456-07:00', $dt));
        $this->assertSame([], $v->validate('2026-09-27 12:00:00', $dt), 'SQL datetime (latest_event_at) is accepted');
        $this->assertSame(['$:format'], $this->keywords($v->validate('27/09/2026', $dt)));
        $this->assertSame([], $v->validate('0f3a1b2c-4d5e-4f60-8a71-8293a4b5c6d7', ['format' => 'uuid']));
        $this->assertSame(['$:format'], $this->keywords($v->validate('nope', ['type' => 'string', 'format' => 'uuid'])));
        $this->assertSame([], $v->validate('2026-09-27', ['format' => 'date']));
        $this->assertSame([], $v->validate('anything', ['format' => 'email']), 'unknown formats are annotations');
    }

    public function test_refs_local_and_sibling_and_combinators(): void
    {
        $common = ['$defs' => ['jb' => ['type' => 'string', 'pattern' => '^JB\\d{6,}$']]];
        $v = new SchemaValidator(fn (string $file) => $file === 'common.schema.json' ? $common : throw new SchemaException('no '.$file));
        $schema = [
            '$defs' => ['pos' => ['type' => 'integer', 'minimum' => 0]],
            'type' => 'object',
            'properties' => [
                'n' => ['$ref' => '#/$defs/pos'],
                'jb' => ['$ref' => '../common.schema.json#/$defs/jb'],
                'k' => ['anyOf' => [['type' => 'integer'], ['type' => 'string']]],
                'kind' => ['type' => 'string'],
            ],
            'oneOf' => [
                ['properties' => ['kind' => ['const' => 'a']], 'required' => ['kind', 'n']],
                ['properties' => ['kind' => ['const' => 'b']], 'required' => ['kind', 'jb']],
            ],
            'allOf' => [['required' => ['kind']]],
        ];
        $this->assertSame([], $v->validate(['kind' => 'a', 'n' => 1, 'k' => 'x'], $schema));
        $this->assertSame([], $v->validate(['kind' => 'b', 'jb' => 'JB123456', 'k' => 2], $schema));
        $this->assertSame(['$.n:minimum', '$.jb:pattern', '$.k:anyOf'], $this->keywords($v->validate(['kind' => 'a', 'n' => -1, 'jb' => 'x', 'k' => true], $schema)));
        $this->assertSame(['$:oneOf'], $this->keywords($v->validate(['kind' => 'c'], $schema)));
        $this->assertSame(['$.kind:required', '$:oneOf'], $this->keywords($v->validate([], $schema)));
    }

    public function test_unresolvable_ref_throws(): void
    {
        $this->expectException(SchemaException::class);
        (new SchemaValidator)->validate(1, ['$ref' => '#/$defs/nope']);
    }

    public function test_keyword_audit_is_clean_and_every_schema_declares_2020_12(): void
    {
        $registry = new SchemaRegistry;
        $this->assertSame([], (new KeywordAudit($registry))->run());
        $this->assertCount(23, $registry->files(), '5 top-level documents + 18 streams');
        foreach ($registry->files() as $name => $path) {
            $doc = $registry->document($name);
            $this->assertSame(KeywordAudit::DIALECT, $doc['$schema'], $name);
            $this->assertStringEndsWith($name, $doc['$id'], $name);
        }
    }

    public function test_keyword_audit_reports_a_keyword_outside_the_subset(): void
    {
        $dir = sys_get_temp_dir().'/txx-schemas-'.getmypid();
        @mkdir($dir);
        @mkdir($dir.'/streams');
        file_put_contents($dir.'/manifest.schema.json', json_encode(['$schema' => KeywordAudit::DIALECT, 'type' => 'object', 'additionalProperties' => false, 'properties' => ['x' => ['$ref' => 'nope.schema.json#/$defs/x']], 'dependentRequired' => []]));
        file_put_contents($dir.'/streams/jobs.schema.json', json_encode(['type' => 'object', 'properties' => ['d' => ['format' => 'email']]]));
        try {
            $problems = (new KeywordAudit(new SchemaRegistry($dir)))->run();
        } finally {
            array_map('unlink', glob($dir.'/streams/*') ?: []);
            array_map('unlink', glob($dir.'/*.json') ?: []);
            rmdir($dir.'/streams');
            rmdir($dir);
        }
        $this->assertContains('manifest.schema.json #: keyword "additionalProperties" is outside the validator subset', $problems);
        $this->assertContains('manifest.schema.json #: keyword "dependentRequired" is outside the validator subset', $problems);
        $this->assertContains('manifest.schema.json #/properties/x: $ref "nope.schema.json#/$defs/x" file not found', $problems);
        $this->assertContains('jobs.schema.json: $schema must be '.KeywordAudit::DIALECT, $problems);
        $this->assertContains('jobs.schema.json #/properties/d: format "email" is not implemented', $problems);
    }

    public function test_every_catalogue_stream_has_a_schema_and_validates_its_key_fields(): void
    {
        $registry = new SchemaRegistry;
        foreach (StreamCatalogue::names() as $stream) {
            $this->assertTrue($registry->hasStream($stream), $stream);
            $doc = $registry->forStream($stream);
            $this->assertSame('object', $doc['type'], $stream);
            $this->assertArrayHasKey('scope', $doc['properties'], $stream.' rows carry scope');
            $this->assertArrayHasKey('origin_ref', $doc['properties'], $stream.' rows may carry origin_ref');
        }
        $this->assertSame('UpdateOp', $registry->forStream('updates')['title']);
    }

    public function test_p2_runner_job_target_task_is_the_task_object_pmo_stores(): void
    {
        $registry = new SchemaRegistry;
        $job = null;
        foreach (file(__DIR__.'/../fixtures/v1/pmo-to-nesc-basic/streams/p2_runner_status.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            $job ??= ($row['kind'] ?? null) === 'job' ? $row : null;
        }
        $this->assertIsArray($job, 'the fixture carries a job row');
        $this->assertSame(['record_id' => 'a0pcw00000MLNwE', 'name' => 'TSK0034669922', 'type' => 'Create Estimate', 'status' => 'Accepted', 'hold' => false], $job['target_task'], 'the fixture ships the object PMO stores');
        $this->assertSame([], $registry->validateRow('p2_runner_status', $job));
        $job['target_task']['partner'] = 'TEXSTAR ENTERPRISES INC';
        $this->assertSame([], $registry->validateRow('p2_runner_status', $job), 'unknown task keys are ignored');
        $this->assertSame([], $registry->validateRow('p2_runner_status', ['target_task' => null] + $job));
        $this->assertSame(['$.target_task:type'], $this->keywords($registry->validateRow('p2_runner_status', ['target_task' => 'TSK0032627183'] + $job)));
        $this->assertSame(['$.target_task.hold:type'], $this->keywords($registry->validateRow('p2_runner_status', ['target_task' => ['hold' => 'no']] + $job)));
    }

    public function test_readiness_row_validates_and_a_missing_key_is_reported(): void
    {
        $registry = new SchemaRegistry;
        $row = $this->readinessRow();
        $this->assertSame([], $registry->validateRow('readiness', $row));

        unset($row['backlog']);
        $row['latest_event_at'] = '2026-09-01T12:00:00Z';
        $row['tasks'][0]['status_base'] = 5;
        $this->assertSame(['$.backlog:required', '$.tasks[0].status_base:type', '$.latest_event_at:pattern'], $this->keywords($registry->validateRow('readiness', $row)));
    }

    public function test_inspection_reports_row_must_have_null_content(): void
    {
        $registry = new SchemaRegistry;
        $row = [
            'external_report_id' => 'nesc:inspection:1:r1', 'job_id' => 'JB000001', 'job_number' => 'JB000001', 'pole_identifier' => 'P1', 'variant' => 'nesc', 'revision' => 1,
            'nesc_inspection_id' => 1, 'p2_job_record_id' => null, 'p2_task_record_id' => null, 'p2_task_name' => null, 'task_type' => null, 'comcast_task_owner' => null,
            'filename' => 'x.pdf', 'inspected_at' => '2026-09-01T12:00:00Z', 'inspector_name' => null, 'sha256' => str_repeat('a', 64), 'content_base64' => null, 'replace' => true,
        ];
        $this->assertSame([], $registry->validateRow('inspection_reports', $row));
        $row['content_base64'] = 'QUJD';
        $this->assertSame(['$.content_base64:type'], $this->keywords($registry->validateRow('inspection_reports', $row)));
    }

    public function test_contract_1_1_0_bounds_and_widenings(): void
    {
        $registry = new SchemaRegistry;
        $obs = ['external_id' => 'nesc:obs:JB0002685018:nesc_pole_coordinates:3ce8b3ab', 'jb' => 'JB0002685018', 'entity' => 'job', 'entity_id' => '1', 'field' => 'nesc_pole_coordinates', 'value' => '[]', 'observed_at' => '2026-09-01T12:00:00Z'];
        $this->assertSame([], $registry->validateRow('observations', $obs), 'NESC\'s longest external_id fits');
        $obs['external_id'] = 'nesc:obs:JB0002685018:'.str_repeat('x', 34).':3ce8b3ab';
        $this->assertSame(65, strlen($obs['external_id']));
        $this->assertSame(['$.external_id:maxLength'], $this->keywords($registry->validateRow('observations', $obs)));

        $report = [
            'external_report_id' => 'nesc:inspection:1:r1', 'job_id' => 'JB000001', 'job_number' => 'JB000001', 'pole_identifier' => 'P1', 'variant' => 'nesc', 'revision' => 1,
            'nesc_inspection_id' => 1, 'p2_job_record_id' => null, 'p2_task_record_id' => null, 'p2_task_name' => null, 'task_type' => null, 'comcast_task_owner' => null,
            'filename' => 'x.pdf', 'inspected_at' => '2026-09-01T12:00:00Z', 'inspector_name' => null, 'sha256' => str_repeat('a', 64), 'content_base64' => null, 'replace' => true,
            'file_url' => null, 'download_url' => null, 'url' => null,
        ];
        $this->assertSame([], $registry->validateRow('inspection_reports', $report));
        $report['file_url'] = 'http://169.254.169.254/latest';
        $report['url'] = 'https://nesc.example/x.pdf';
        $this->assertSame(['$.file_url:type', '$.url:type'], $this->keywords($registry->validateRow('inspection_reports', $report)));

        $pole = json_decode(explode("\n", (string) file_get_contents(dirname(__DIR__).'/fixtures/v1/pmo-to-nesc-basic/streams/poles.ndjson'))[0], true);
        foreach (['high', 0.92, null] as $confidence) {
            $this->assertSame([], $registry->validateRow('poles', ['confidence' => $confidence] + $pole));
        }
        $this->assertSame(['$.confidence:type'], $this->keywords($registry->validateRow('poles', ['confidence' => true] + $pole)));
        $this->assertSame([], $registry->validateRow('jobs', ['job_number' => 'JB000001', 'nesc_pole_candidates' => [['candidate_key' => str_repeat('a', 64), 'confidence' => 'medium']]]));

        $receipt = json_decode((string) file_get_contents(dirname(__DIR__).'/schemas/v1/receipt.schema.json'), true);
        $stream = ['received' => 3, 'accepted' => 0, 'rejected' => 3, 'accepted_all' => false, 'rejected_rows' => [], 'applied' => false, 'refused' => 'large_drop'];
        $this->assertSame([], (new SchemaValidator)->validate($stream, $receipt['$defs']['stream_receipt']));
        $stream['applied'] = 'no';
        $this->assertSame(['$.applied:type'], $this->keywords((new SchemaValidator)->validate($stream, $receipt['$defs']['stream_receipt'])));

        $manifest = json_decode(json_encode($this->draft()->toArray() + ['signature' => null]), true);
        $manifest['extras'] = ['full_streams_unchanged' => ['gis_features', 'pge_violations']];
        $this->assertSame([], $registry->validateManifest($manifest));
    }

    public function test_kind_discriminated_streams_use_one_of(): void
    {
        $registry = new SchemaRegistry;
        $this->assertSame([], $registry->validateRow('worker_activity', ['kind' => 'job', 'job_number' => 'JB000001', 'wanted' => false]));
        $this->assertSame(['$:oneOf'], $this->keywords($registry->validateRow('worker_activity', ['kind' => 'assignment', 'job_number' => 'JB000001'])));
        $this->assertSame([], $registry->validateRow('worker_activity_status', ['kind' => 'job', 'job_number' => 'JB000001', 'nesc_job_id' => 1, 'presence' => 'present', 'reason' => null, 'scope_of_work_id' => null, 'scope_status' => null, 'checked_at' => '2026-09-01T12:00:00Z']));
        $this->assertSame(['$.presence:enum'], $this->keywords($registry->validateRow('worker_activity_status', ['kind' => 'job', 'job_number' => 'JB000001', 'nesc_job_id' => 1, 'presence' => 'maybe', 'reason' => null, 'scope_of_work_id' => null, 'scope_status' => null, 'checked_at' => null])));
    }

    public function test_manifest_and_receipt_schemas(): void
    {
        $registry = new SchemaRegistry;
        $manifest = $this->draft()->toArray();
        $manifest['signature'] = ['alg' => 'HMAC-SHA256', 'key_id' => 'texstar:comcast', 'value' => str_repeat('0', 64)];
        $manifest = json_decode(json_encode($manifest), true);
        $this->assertSame([], $registry->validateManifest($manifest));

        $manifest['signature'] = null;
        $this->assertSame([], $registry->validateManifest($manifest), 'null signature is schema-valid (kind rows); the Verifier refuses it');
        $manifest['direction'] = 'nesc→pmo';
        $manifest['files'] = [['path' => 'streams/../x.ndjson', 'stream' => 'x', 'schema' => 'x@1', 'rows' => 1, 'sha256' => 'zz', 'mode' => 'delta']];
        $this->assertSame(['$.direction:enum', '$.files[0].path:pattern', '$.files[0].sha256:pattern'], $this->keywords($registry->validateManifest($manifest)));

        $receipt = ['format' => 'texstar-exchange-receipt', 'version' => 1, 'package_id' => '0f3a1b2c-4d5e-4f60-8a71-8293a4b5c6d7', 'receipt_id' => '1f3a1b2c-4d5e-4f60-8a71-8293a4b5c6d7', 'produced_at' => '2026-09-27T12:00:00Z', 'producer' => ['app' => 'pmo'], 'scope' => ['tenant' => 'texstar'], 'streams' => [], 'status' => 'applied'];
        $this->assertSame([], $registry->validateReceipt($receipt));
        $receipt['status'] = 'meh';
        $this->assertSame(['$.status:enum'], $this->keywords($registry->validateReceipt($receipt)));
    }

    public function test_registry_refuses_bad_names(): void
    {
        $this->expectException(SchemaException::class);
        (new SchemaRegistry)->forStream('../etc/passwd');
    }
}
