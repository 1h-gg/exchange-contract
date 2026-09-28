<?php

declare(strict_types=1);

namespace Texstar\Exchange\Tests;

use PHPUnit\Framework\TestCase;
use Texstar\Exchange\Contract;
use Texstar\Exchange\Package\Negotiation;

final class NegotiationTest extends TestCase
{
    private function manifest(array $files, int $version = 1): array
    {
        return ['format' => Contract::FORMAT, 'version' => $version, 'files' => $files];
    }

    public function test_accepts_only_its_own_major(): void
    {
        $this->assertTrue(Negotiation::accepts(1, 1));
        $this->assertFalse(Negotiation::accepts(2, 1));
        $this->assertFalse(Negotiation::accepts(0, 1));
    }

    public function test_higher_manifest_major_is_unsupported_version(): void
    {
        $result = Negotiation::evaluate($this->manifest([['stream' => 'jobs', 'schema' => 'jobs@1']], 2));
        $this->assertFalse($result->accepted);
        $this->assertSame('unsupported_version', $result->rejectCode);
        $this->assertSame([], $result->streams);

        $result = Negotiation::evaluate(['format' => 'other', 'version' => 1, 'files' => []]);
        $this->assertSame('unsupported_version', $result->rejectCode);
        $result = Negotiation::evaluate(['format' => Contract::FORMAT, 'version' => '1', 'files' => []]);
        $this->assertSame('unsupported_version', $result->rejectCode, 'version must be an integer');
    }

    public function test_unknown_or_newer_streams_are_skipped_with_a_warning_never_rejected(): void
    {
        $result = Negotiation::evaluate($this->manifest([
            ['stream' => 'jobs', 'schema' => 'jobs@1'],
            ['stream' => 'holograms', 'schema' => 'holograms@1'],
            ['stream' => 'poles', 'schema' => 'poles@2'],
            ['stream' => 'dailies', 'schema' => 'dailies'],
            ['stream' => 'readiness', 'schema' => 'jobs@1'],
            ['stream' => 'updates', 'schema' => 'updates@1'],
            ['stream' => 'p2_runner_status', 'schema' => 'p2_runner_status@1'],
        ]));

        $this->assertTrue($result->accepted);
        $this->assertSame(['jobs', 'p2_runner_status'], $result->streams);
        $this->assertSame([
            ['stream' => 'holograms', 'schema' => 'holograms@1', 'reason' => 'unknown stream'],
            ['stream' => 'poles', 'schema' => 'poles@2', 'reason' => 'stream major 2 is newer than 1'],
            ['stream' => 'dailies', 'schema' => 'dailies', 'reason' => 'malformed schema id'],
            ['stream' => 'readiness', 'schema' => 'jobs@1', 'reason' => 'schema id names another stream'],
            ['stream' => 'updates', 'schema' => 'updates@1', 'reason' => 'unknown stream'],
        ], $result->skipped);
        $this->assertCount(5, $result->warnings());
        $this->assertStringContainsString('holograms', $result->warnings()[0]);
    }

    public function test_parse_schema(): void
    {
        $this->assertSame(['jobs', 1], Negotiation::parseSchema('jobs@1'));
        $this->assertSame(['p2_runner_status', 12], Negotiation::parseSchema('p2_runner_status@12'));
        $this->assertNull(Negotiation::parseSchema('Jobs@1'));
        $this->assertNull(Negotiation::parseSchema('jobs@x'));
        $this->assertNull(Negotiation::parseSchema('jobs'));
    }
}
