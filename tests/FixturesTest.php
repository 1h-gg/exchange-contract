<?php

declare(strict_types=1);

namespace Texstar\Exchange\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Texstar\Exchange\Dto\Scope;
use Texstar\Exchange\Package\PackageReader;
use Texstar\Exchange\Signing\KeyRing;
use Texstar\Exchange\Signing\SignatureException;
use Texstar\Exchange\Stream\RowKey;
use Texstar\Exchange\Stream\StreamCatalogue;
use Texstar\Exchange\Testing\ContractSuite;
use Texstar\Exchange\Testing\FixtureSigner;

/** The golden fixtures through the reader, exactly as NESC's ContractFixturesTest and PMO's counterpart run them. */
final class FixturesTest extends TestCase
{
    public const READINESS_HASH = '5dd819e65ca4a7363f3fd692ad543f4d51a7ba6cf94c582024d8307c8fd7975a';

    public const PACKET_FINGERPRINT = '0661230901bb5509b03b1af11edf14bd2160a9415a865223402c2b6af3a9a097';

    public const FIXTURES = ['bad-blob', 'bad-path', 'bad-signature', 'nesc-to-pmo-basic', 'oversize-row', 'pmo-to-nesc-basic', 'rows-kind', 'tampered-stream', 'unknown-stream', 'unscoped', 'unsigned-package', 'v2-major'];

    public static function tearDownAfterClass(): void
    {
        ContractSuite::cleanup();
    }

    public function test_the_twelve_fixtures_exist(): void
    {
        $this->assertSame(self::FIXTURES, ContractSuite::names());
        $this->assertSame(['texstar:comcast'], ContractSuite::keyRing()->keyIds());
    }

    /** @return iterable<string, array{0: string}> */
    public static function fixtures(): iterable
    {
        foreach (self::FIXTURES as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('fixtures')]
    public function test_fixture_meets_its_expectations(string $name): void
    {
        $path = ContractSuite::build($name);
        $expected = ContractSuite::expected($name);
        $reader = PackageReader::open($path, 64 * 1024 * 1024);
        ContractSuite::assertReader($reader, $expected, fn (string $problem) => $this->fail($name.': '.$problem));
        $this->assertSame([], ContractSuite::check($reader, $expected, ContractSuite::keyRing()));
    }

    public function test_cases_yields_every_fixture_with_a_ring_and_expectations(): void
    {
        $seen = [];
        foreach (ContractSuite::cases() as $name => $case) {
            $seen[] = $name;
            $this->assertSame($name, $case['name']);
            $this->assertFileExists($case['path']);
            $this->assertInstanceOf(KeyRing::class, $case['keyRing']);
            $this->assertArrayHasKey('verify', $case['expected']);
        }
        $this->assertSame(self::FIXTURES, $seen);
    }

    public function test_committed_manifests_are_the_signer_output(): void
    {
        foreach (self::FIXTURES as $name) {
            $before = file_get_contents(ContractSuite::dir($name).'/manifest.json');
            FixtureSigner::sign($name);
            $this->assertSame($before, file_get_contents(ContractSuite::dir($name).'/manifest.json'), $name.': manifest.json is not what fixtures:sign writes');
        }
    }

    public function test_every_fixture_manifest_carries_the_golden_key_id_ascii_direction_and_program(): void
    {
        foreach (self::FIXTURES as $name) {
            $m = PackageReader::open(ContractSuite::build($name))->manifestDto();
            $this->assertContains($m->direction, ['nesc_to_pmo', 'pmo_to_nesc'], $name);
            $this->assertSame('comcast-nesc', $m->resolvedScope()->program, $name);
            if ($m->isSigned()) {
                $this->assertSame('texstar:comcast', $m->signature['key_id'], $name);
                $this->assertSame('texstar:comcast', $m->resolvedScope()->keyId(), $name);
            } else {
                $this->assertContains($name, ['rows-kind', 'unsigned-package']);
            }
        }
    }

    public function test_the_readiness_row_hashes_to_the_pinned_literals_once_the_package_only_fields_are_stripped(): void
    {
        $reader = PackageReader::open(ContractSuite::build('nesc-to-pmo-basic'));
        $rows = iterator_to_array($reader->rows('readiness'));
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(['tenant' => 'texstar', 'contract' => 'comcast', 'program' => 'comcast-nesc', 'instance' => null], $row['scope']);
        $this->assertSame('p2', $row['tasks'][0]['system']);
        $this->assertSame('TSK0034669922', $row['tasks'][0]['external_task_id']);
        unset($row['scope']);
        foreach ($row['tasks'] as &$task) {
            unset($task['system'], $task['external_task_id']);
        }
        unset($task);
        $this->assertSame(self::READINESS_HASH, hash('sha256', json_encode($row)));
        $this->assertFalse($row['backlog']);
        $this->assertSame('2026-09-01 12:00:00', $row['latest_event_at']);

        $packets = iterator_to_array($reader->rows('inspection_packets'));
        $packet = $packets[0];
        unset($packet['scope']);
        $this->assertSame(self::PACKET_FINGERPRINT, hash('sha256', json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

        $pins = ContractSuite::expected('nesc-to-pmo-basic')['pins'];
        $this->assertSame(self::READINESS_HASH, $pins['readiness_hash']);
        $this->assertSame(self::PACKET_FINGERPRINT, $pins['packet_fingerprint']);
    }

    public function test_nesc_to_pmo_blobs_are_the_report_pdfs_and_reports_carry_no_base64(): void
    {
        $reader = PackageReader::open(ContractSuite::build('nesc-to-pmo-basic'));
        $reader->verify(ContractSuite::keyRing());
        $shas = array_map(fn ($b) => $b->sha256, $reader->manifestDto()->blobs);
        foreach ($reader->rows('inspection_reports') as $row) {
            $this->assertNull($row['content_base64']);
            $this->assertContains($row['sha256'], $shas);
            $bytes = stream_get_contents($reader->blob($row['sha256']));
            $this->assertSame($row['sha256'], hash('sha256', $bytes));
            $this->assertStringStartsWith('%PDF-1.4 stub ', $bytes);
        }
        $this->assertSame(['readiness_feed'], array_keys($reader->manifestDto()->extras));
    }

    public function test_pmo_to_nesc_rows_carry_the_emitter_keys(): void
    {
        $reader = PackageReader::open(ContractSuite::build('pmo-to-nesc-basic'));
        $reader->verify(ContractSuite::keyRing());
        $jobs = iterator_to_array($reader->rows('jobs'));
        $this->assertSame([
            'job_id', 'status', 'internal_status', 'gate_state', 'eccd', 'description', 'site_address', 'city', 'state', 'zip', 'area', 'market', 'region', 'job_owner',
            'source_last_seen_at', 'updated_at', 'inspectability', 'tasks', 'permits', 'nesc_poles', 'nesc_pole_candidates', 'poles', 'p2_nesc', 'job_number', 'verdict', 'nesc_scope', 'scope',
        ], array_keys($jobs[0]), 'NescSyncFeed::jobPayload key order + withBridgeAliases + scope');
        $this->assertSame(['task_id', 'task_type', 'status', 'job_status', 'milestone', 'gate_state', 'due_date', 'approved_at', 'updated_at'], array_keys($jobs[0]['tasks'][0]));
        $this->assertSame(['inspectable', 'reason', 'active_field_tasks', 'computed_at'], array_keys($jobs[0]['inspectability']));
        $this->assertSame(['checksum', 'candidates', 'confirmed', 'count'], array_keys($jobs[0]['poles']));
        $this->assertSame($jobs[0]['inspectability'], $jobs[0]['verdict']);
        $this->assertSame($jobs[0]['job_id'], $jobs[0]['job_number']);

        $poles = iterator_to_array($reader->rows('poles'));
        $this->assertSame(['candidate_key', 'job_id', 'pole_identifier', 'state', 'source', 'confidence', 'violation_codes', 'violations', 'lat', 'lng', 'updated_at', 'ai_verdict', 'ai_rationale', 'ai', 'scope'], array_keys($poles[0]));
        $this->assertSame(['code', 'description', 'measurement', 'location', 'ticket_number', 'due_date', 'pole_owner'], array_keys($poles[0]['violations'][0]));
        $keys = array_column($poles, 'candidate_key');
        $this->assertSame(hash('sha256', 'JB0002685018|C6228D-2681'), $keys[0] < $keys[1] ? min($keys) : min($keys));
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys, 'poles travel in candidate_key order');
        $file = $reader->manifestDto()->files[1];
        $this->assertSame('poles', $file->stream);
        $this->assertSame('full', $file->mode);
        $this->assertSame(hash('sha256', implode("\n", $sorted)), $file->setChecksum);
        $this->assertSame(3, $file->setTotal);

        $kinds = [];
        foreach ($reader->rows('p2_runner_status') as $row) {
            $kinds[] = $row['kind'];
            $this->assertSame('texstar:comcast', Scope::resolve($row['scope'])->keyId());
        }
        $this->assertSame(['report', 'note', 'rfa', 'job'], $kinds);
        $runnerJob = iterator_to_array($reader->rows('p2_runner_status'))[3];
        $this->assertSame(['record_id', 'name', 'type', 'status', 'hold', 'partner'], array_keys($runnerJob['p2_tasks'][0]));
        $this->assertSame(['job', 'assignment'], array_map(fn ($r) => $r['kind'], iterator_to_array($reader->rows('worker_activity_status'))));
        $extras = $reader->manifestDto()->extras;
        $this->assertSame(['p2_runner_summary', 'worker_activity_summary', 'gis_icons'], array_keys($extras));

        foreach (StreamCatalogue::forDirection('pmo_to_nesc') as $stream) {
            $this->assertContains($stream, array_map(fn ($f) => $f->stream, $reader->manifestDto()->files), 'pmo-to-nesc-basic covers every pmo_to_nesc stream');
            foreach ($reader->rows($stream) as $row) {
                $this->assertNotSame('', RowKey::for($stream, $row), $stream.' rows are keyable');
            }
        }
        foreach (StreamCatalogue::forDirection('nesc_to_pmo') as $stream) {
            $this->assertContains($stream, array_map(fn ($f) => $f->stream, PackageReader::open(ContractSuite::build('nesc-to-pmo-basic'))->manifestDto()->files), 'nesc-to-pmo-basic covers every nesc_to_pmo stream');
        }
    }

    public function test_external_refs_rows_are_job_and_task_only_with_the_booking_ref(): void
    {
        $reader = PackageReader::open(ContractSuite::build('nesc-to-pmo-basic'));
        $rows = iterator_to_array($reader->rows('external_refs'));
        $this->assertCount(7, $rows);
        $types = array_unique(array_column($rows, 'entity_type'));
        $this->assertEqualsCanonicalizing(['App\Models\Job', 'App\Models\JobTask'], $types);
        $keys = array_map(fn ($r) => RowKey::for('external_refs', $r), $rows);
        $this->assertContains('App\Models\Job|JB0002685018|worker_activity|assignment:1', $keys);
        $this->assertContains('App\Models\JobTask|TSK0034669922|p2|TSK0034669922', $keys);
        $this->assertSame(count($keys), count(array_unique($keys)));
        $systems = array_column(array_filter($rows, fn ($r) => $r['entity_type'] === 'App\Models\Job'), 'system');
        $this->assertEqualsCanonicalizing(['p2_runner', 'jobmaster', 'worker_activity', 'worker_activity', 'pmo', 'sphere'], $systems);
        foreach ($rows as $row) {
            $this->assertSame(['entity_type', 'entity_key', 'entity_id', 'system', 'external_id', 'url', 'status', 'status_raw', 'status_at', 'properties', 'source_updated_at', 'program_id', 'scope'], array_keys($row));
        }
    }

    public function test_rows_kind_is_accepted_only_as_rows(): void
    {
        $reader = PackageReader::open(ContractSuite::build('rows-kind'));
        $this->assertSame('rows', $reader->kind());
        $this->assertFalse($reader->manifestDto()->isSigned());
        try {
            $reader->verify(ContractSuite::keyRing());
            $this->fail('unsigned manifest verified');
        } catch (SignatureException $e) {
            $this->assertSame('signature_missing', $e->wireCode());
        }
        $this->assertSame([], $reader->validateManifest());
        $this->assertCount(2, iterator_to_array($reader->rows('packets_wanted')));
    }

    public function test_an_empty_ring_rejects_every_signed_fixture_with_unknown_key_id(): void
    {
        foreach (['nesc-to-pmo-basic', 'pmo-to-nesc-basic', 'bad-signature'] as $name) {
            try {
                PackageReader::open(ContractSuite::build($name))->verify(new KeyRing);
                $this->fail($name.' verified against an empty ring');
            } catch (SignatureException $e) {
                $this->assertSame('unknown_key_id', $e->wireCode(), $name);
            }
        }
    }

    public function test_receipts_reference_the_other_direction_and_key_rejects_by_row_key_and_seq(): void
    {
        $nesc = PackageReader::open(ContractSuite::build('nesc-to-pmo-basic'))->receipts();
        $this->assertCount(1, $nesc);
        $this->assertSame('nesc', $nesc[0]->producer->app);
        $this->assertSame('partial', $nesc[0]->status);
        $this->assertSame(2, $nesc[0]->streams['poles']->rejectedRows[0]['seq']);
        $pmo = PackageReader::open(ContractSuite::build('pmo-to-nesc-basic'))->receipts();
        $this->assertSame('pmo', $pmo[0]->producer->app);
        $this->assertSame(1, $pmo[0]->streams['review_decisions']->rejectedRows[0]['seq']);
        $this->assertStringStartsWith('nesc-match-3-', $pmo[0]->streams['review_decisions']->rejectedRows[0]['key']);
    }
}
