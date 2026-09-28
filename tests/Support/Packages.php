<?php

declare(strict_types=1);

namespace Texstar\Exchange\Tests\Support;

use Texstar\Exchange\Dto\Manifest;
use Texstar\Exchange\Dto\Producer;
use Texstar\Exchange\Dto\Scope;

/** Shared builders for the package tests: a temp path per test, a draft manifest, a test key ring. */
trait Packages
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            @unlink($path);
        }
        $this->tempPaths = [];
        parent::tearDown();
    }

    protected function tempPath(string $suffix = '.txx.zip'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'txx-test');
        unlink($path);
        $path .= $suffix;

        return $this->tempPaths[] = $path;
    }

    protected function draft(string $kind = 'package', string $direction = 'nesc_to_pmo', ?Scope $scope = null): Manifest
    {
        return new Manifest(
            packageId: '0f3a1b2c-4d5e-4f60-8a71-8293a4b5c6d7',
            direction: $direction,
            createdAt: '2026-09-27T12:00:00Z',
            producer: new Producer('nesc', '1.0.0', 'nescdev', '1.0.0'),
            scope: $scope ?? Scope::unscoped(),
            kind: $kind,
            cursors: ['readiness' => ['from' => null, 'to' => '0f3a1b2c-4d5e-4f60-8a71-8293a4b5c6d7']],
        );
    }

    /** A minimal valid readiness row (every PAYLOAD_KEY + backlog). @return array<string, mixed> */
    protected function readinessRow(string $jb = 'JB000001'): array
    {
        return [
            'job_number' => $jb, 'p2_job_record_id' => 'a0J000000000001', 'stage' => 'estimating', 'expected_poles' => ['P1'],
            'all_poles_supervisor_approved' => false, 'approved_pole_count' => 0, 'estimating_approved_at' => null, 'completed_at' => null,
            'returned_to_field_at' => null,
            'tasks' => [['p2_task_name' => 'TSK1', 'p2_task_record_id' => 'a0p1', 'task_type' => 'Create Estimate', 'status_base' => 'open', 'raw_status' => 'Open', 'comcast_task_owner' => null, 'system' => 'p2', 'external_task_id' => 'TSK1']],
            'has_active_runner_task' => true, 'held' => false, 'hold_reason' => null, 'excluded_poles' => [], 'sphere_sow_built_at' => null,
            'permits_needed' => false, 'permits_determined_at' => null, 'permits_task_submitted_at' => null, 'permit_types' => [], 'info_requests' => [],
            'inspections' => [], 'sow_claims' => [['id' => 1, 'nesc_inspection_id' => null, 'pole' => 'P1', 'code' => 'X', 'unit' => 'EA', 'claim_type' => 'estimate', 'quantity' => 1.5, 'notes' => null]],
            'latest_event_at' => '2026-09-01 12:00:00', 'backlog' => false,
        ];
    }
}
