<?php

declare(strict_types=1);

namespace Texstar\Exchange\Tests;

use PHPUnit\Framework\TestCase;
use Texstar\Exchange\Contract;
use Texstar\Exchange\ExchangeException;
use Texstar\Exchange\Schema\SchemaRegistry;
use Texstar\Exchange\Stream\RowKey;
use Texstar\Exchange\Stream\StreamCatalogue;

final class StreamCatalogueTest extends TestCase
{
    public function test_eighteen_package_streams_plus_api_only_updates(): void
    {
        $names = StreamCatalogue::names();
        $this->assertCount(18, $names);
        $this->assertSame([
            'readiness', 'readiness_feed', 'inspection_reports', 'inspection_packets', 'review_decisions', 'observations', 'worker_activity', 'external_refs',
            'jobs', 'poles', 'p2_runner_status', 'jobmaster_status', 'worker_activity_status', 'dailies', 'packets_wanted', 'gis_sources', 'gis_features', 'pge_violations',
        ], $names);
        $this->assertNotContains('updates', $names);
        $this->assertContains('updates', StreamCatalogue::allNames());
        $this->assertTrue(StreamCatalogue::get('updates')['api_only']);
        $this->assertFalse(StreamCatalogue::isPackageStream('updates'));
        $this->assertTrue(StreamCatalogue::isPackageStream('jobs'));
        $this->assertFalse(StreamCatalogue::has('holograms'));
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression(Contract::STREAM_NAME_PATTERN, $name);
        }
    }

    public function test_directions_modes_and_schema_ids(): void
    {
        $this->assertSame(['readiness', 'readiness_feed', 'inspection_reports', 'inspection_packets', 'review_decisions', 'observations', 'worker_activity', 'external_refs'], StreamCatalogue::forDirection(Contract::DIRECTION_NESC_TO_PMO));
        $this->assertCount(10, StreamCatalogue::forDirection(Contract::DIRECTION_PMO_TO_NESC));
        $this->assertSame('full', StreamCatalogue::mode('poles'));
        $this->assertSame('full', StreamCatalogue::mode('readiness_feed'));
        $this->assertSame('delta', StreamCatalogue::mode('jobs'));
        $this->assertSame('jobs@1', StreamCatalogue::schemaId('jobs'));
        $this->assertSame('deliverables:readiness', StreamCatalogue::get('readiness')['neutral']);
        $this->assertSame('pull_jobs', StreamCatalogue::get('jobs')['action']);
        $this->assertNull(StreamCatalogue::get('external_refs')['action']);
        $this->assertStringContainsString('assignment:<id>', StreamCatalogue::get('external_refs')['notes']);
        $this->assertStringContainsString('RESERVED', StreamCatalogue::get('external_refs')['notes']);
        $registry = new SchemaRegistry;
        foreach (StreamCatalogue::allNames() as $name) {
            $this->assertTrue($registry->hasStream($name), $name);
        }
    }

    public function test_unknown_stream_throws(): void
    {
        $this->expectException(ExchangeException::class);
        StreamCatalogue::get('holograms');
    }

    public function test_row_keys(): void
    {
        $this->assertSame('JB000001', RowKey::for('readiness', ['job_number' => 'JB000001']));
        $this->assertSame('feed', RowKey::for('readiness_feed', ['jobs_tracked' => 1]));
        $this->assertSame('job|JB000001', RowKey::for('worker_activity', ['kind' => 'job', 'job_number' => 'JB000001']));
        $this->assertSame('assignment|12', RowKey::for('worker_activity', ['kind' => 'assignment', 'nesc_assignment_id' => 12]));
        $this->assertSame('contractor|7', RowKey::for('worker_activity', ['kind' => 'contractor', 'id' => 7, 'name' => 'Crew']));
        $this->assertSame('report|nesc:inspection:1:r1', RowKey::for('p2_runner_status', ['kind' => 'report', 'external_report_id' => 'nesc:inspection:1:r1']));
        $this->assertSame('note|5', RowKey::for('p2_runner_status', ['kind' => 'note', 'pmo_note_id' => 5]));
        $this->assertSame('rfa|9', RowKey::for('p2_runner_status', ['kind' => 'rfa', 'pmo_operation_id' => 9]));
        $this->assertSame('job|JB000001', RowKey::for('p2_runner_status', ['kind' => 'job', 'job_number' => 'JB000001']));
        $this->assertSame('assignment|3', RowKey::for('worker_activity_status', ['kind' => 'assignment', 'nesc_assignment_id' => 3]));
        $this->assertSame('App\Models\Job|JB000001|p2|JB000001', RowKey::for('external_refs', ['entity_type' => 'App\Models\Job', 'entity_key' => 'JB000001', 'system' => 'p2', 'external_id' => 'JB000001', 'entity_id' => 4]));
        $this->assertSame('poles|123', RowKey::for('gis_features', ['layer' => 'poles', 'feature_id' => '123']));
        $this->assertSame('T-1', RowKey::for('pge_violations', ['ticket' => 'T-1', 'payload' => []]));
        $this->assertSame('d1', RowKey::for('dailies', ['daily' => 'd1']));
        $this->assertSame('0f3a1b2c-4d5e-4f60-8a71-8293a4b5c6d7', RowKey::for('updates', ['id' => '0f3a1b2c-4d5e-4f60-8a71-8293a4b5c6d7']));
        $this->assertSame(hash('sha256', 'job|JB000001'), RowKey::hash('job|JB000001'));
    }

    public function test_row_key_refuses_unkeyed_rows(): void
    {
        $this->assertNull(RowKey::tryFor('readiness', ['stage' => 'x']));
        $this->assertNull(RowKey::tryFor('worker_activity', ['kind' => 'alien', 'id' => 1]));
        $this->assertNull(RowKey::tryFor('worker_activity', ['kind' => 'job']));
        $this->assertNull(RowKey::tryFor('gis_features', ['layer' => 'poles']));
        $this->expectException(ExchangeException::class);
        RowKey::for('readiness', []);
    }
}
