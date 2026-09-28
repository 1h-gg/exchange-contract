<?php

declare(strict_types=1);

namespace Texstar\Exchange\Tests;

use PHPUnit\Framework\TestCase;
use Texstar\Exchange\Client\RunnerClient;
use Texstar\Exchange\Client\RunnerClientException;
use Texstar\Exchange\Tests\Support\FakeTransport;
use Texstar\Exchange\Uuid;

final class RunnerClientTest extends TestCase
{
    private const P = RunnerClient::PREFIX;

    /** @var list<string> */
    private array $temp = [];

    /** @var list<float> */
    private array $slept = [];

    private float $now = 1_000_000.0;

    private int $uuidCounter = 0;

    protected function tearDown(): void
    {
        foreach ($this->temp as $p) {
            @unlink($p);
        }
    }

    private function client(FakeTransport $t): RunnerClient
    {
        $this->uuidCounter = 0;

        return new RunnerClient('https://nesc.test', 'tok', $t, function (float $s): void {
            $this->slept[] = $s;
            $this->now += $s;
        }, fn (): float => $this->now, fn (): string => sprintf('%08x-0000-4000-8000-%012x', ++$this->uuidCounter, $this->uuidCounter));
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'txx-client');
        file_put_contents($path, $bytes);

        return $this->temp[] = $path;
    }

    private function limits(FakeTransport $t, int $maxUploadMb = 60, int $chunkMb = 8): void
    {
        $t->on('GET', self::P.'/status', 200, ['transport' => 'exchange', 'limits' => ['max_upload_mb' => $maxUploadMb, 'max_package_mb' => 200, 'chunk_mb' => $chunkMb, 'max_file_mb' => 30, 'max_chunks' => 4000]]);
    }

    public function test_base_url_gets_the_prefix_once_and_calls_carry_the_bearer_token(): void
    {
        $t = new FakeTransport;
        $this->assertSame('https://nesc.test'.self::P, $this->client($t)->baseUrl());
        $this->assertSame('https://pmo.test'.self::P, (new RunnerClient('https://pmo.test'.self::P.'/', 'x', $t))->baseUrl());
        $this->client($t)->status('comcast-nesc');
        $this->assertSame('https://nesc.test'.self::P.'/status?program=comcast-nesc', $t->requests[0]['url']);
        $this->assertSame('Bearer tok', $t->requests[0]['headers']['Authorization']);
        $this->assertArrayNotHasKey('Idempotency-Key', $t->requests[0]['headers'], 'GET carries no Idempotency-Key');
    }

    public function test_the_sync_call_sequence_single_shot_upload(): void
    {
        $t = new FakeTransport;
        $this->limits($t);
        $zip = $this->file('PK zip bytes');
        $t->on('POST', self::P.'/outbound', 202, ['package_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'status' => 'queued']);
        $t->on('GET', self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001', 200, ['status' => 'building']);
        $t->on('GET', self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001', 200, ['status' => 'ready', 'counts' => ['readiness' => ['rows' => 3]]]);
        $t->on('GET', self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001/download', 200, 'PK zip bytes', ['X-Package-Sha256' => hash('sha256', 'PK zip bytes')]);
        $t->on('POST', self::P.'/inbound', 202, ['import_id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'status' => 'staging']);
        $t->on('GET', self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002', 200, ['status' => 'applying']);
        $t->on('GET', self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002', 200, ['status' => 'applied']);
        $t->on('GET', self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002/receipt', 200, ['status' => 'applied', 'streams' => []]);

        $c = $this->client($t);
        $build = $c->buildOutbound(['include_blobs' => false]);
        $state = $c->waitOutbound($build['package_id'], 60, 1);
        $this->assertSame('ready', $state['status']);
        $out = $this->temp[] = tempnam(sys_get_temp_dir(), 'txx-dl');
        $this->assertSame(hash('sha256', 'PK zip bytes'), $c->download($build['package_id'], $out));
        $upload = $c->uploadInbound($out, true);
        $this->assertSame('applied', $c->waitInbound($upload['import_id'], 60, 1)['status']);
        $this->assertSame('applied', $c->receipt($upload['import_id'])['status']);

        $this->assertSame([
            'POST '.self::P.'/outbound',
            'GET '.self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001',
            'GET '.self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001',
            'GET '.self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001/download',
            'GET '.self::P.'/status',
            'POST '.self::P.'/inbound',
            'GET '.self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002',
            'GET '.self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002',
            'GET '.self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002/receipt',
        ], $t->calls());
        $this->assertSame([1.0, 1.0], $this->slept, 'one poll interval per non-terminal answer');
        $inbound = $t->requests[5];
        $this->assertSame($out, $inbound['multipart']['package']['path']);
        $this->assertSame('1', $inbound['multipart']['auto_apply']);
        $this->assertSame('{"include_blobs":false}', $t->requests[0]['body']);
        $this->assertSame('application/json', $t->requests[0]['headers']['Content-Type']);
    }

    public function test_a_download_that_does_not_match_x_package_sha256_is_refused(): void
    {
        $t = new FakeTransport;
        $t->on('GET', self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001/download', 200, 'bytes', ['X-Package-Sha256' => str_repeat('0', 64)]);
        $out = $this->temp[] = tempnam(sys_get_temp_dir(), 'txx-dl');
        try {
            $this->client($t)->download('aaaaaaaa-0000-4000-8000-000000000001', $out);
            $this->fail('accepted a mismatched download');
        } catch (RunnerClientException $e) {
            $this->assertSame('hash_mismatch', $e->wireCode());
            $this->assertFileDoesNotExist($out);
        }
    }

    public function test_idempotency_key_is_a_v4_uuid_reused_for_the_same_attempt_and_fresh_when_the_file_changes(): void
    {
        $t = new FakeTransport;
        $this->limits($t);
        $t->on('POST', self::P.'/inbound', 202, ['import_id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'status' => 'staging']);
        $zip = $this->file('version one');
        $c = new RunnerClient('https://nesc.test', 'tok', $t);
        $c->uploadInbound($zip);
        $c->uploadInbound($zip);
        file_put_contents($zip, 'version two');
        $c->uploadInbound($zip);
        $keys = $t->idempotencyKeys();
        $this->assertCount(3, $keys);
        foreach ($keys as $k) {
            $this->assertTrue(Uuid::isV4($k), $k);
        }
        $this->assertSame($keys[0], $keys[1], 'the same file replays the same attempt');
        $this->assertNotSame($keys[0], $keys[2], 'a changed file is a new attempt');
        $this->assertNotSame($keys[0], (new RunnerClient('https://nesc.test', 'tok', $t))->buildOutbound() === [] ? $t->idempotencyKeys()[3] : '', 'another client never shares keys');
    }

    public function test_429_is_retried_after_retry_after_with_the_same_key(): void
    {
        $t = new FakeTransport;
        $t->on('POST', self::P.'/outbound', 429, ['error' => ['code' => 'rate_limited', 'message' => 'slow down']], ['Retry-After' => '7']);
        $t->on('POST', self::P.'/outbound', 429, ['error' => ['code' => 'rate_limited', 'message' => 'slow down']], ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', (int) $this->now + 10)]);
        $t->on('POST', self::P.'/outbound', 202, ['package_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'status' => 'queued']);
        $c = $this->client($t);
        $r = $c->buildOutbound();
        $this->assertSame('queued', $r['status']);
        $this->assertSame([7.0, 3.0], $this->slept);
        $this->assertCount(3, $t->requests);
        $this->assertCount(1, array_unique($t->idempotencyKeys()), 'a retry replays the same key');
    }

    public function test_errors_come_from_the_envelope(): void
    {
        $t = new FakeTransport;
        $t->on('POST', self::P.'/rows/readiness', 422, ['error' => ['code' => 'validation_failed', 'message' => 'job_number missing', 'row' => ['stream' => 'readiness', 'seq' => 1, 'key' => null], 'correlation_id' => 'c-1']]);
        try {
            $this->client($t)->pushRows('readiness', [['job_number' => 'JB000001'], []]);
            $this->fail('no exception');
        } catch (RunnerClientException $e) {
            $this->assertSame('validation_failed', $e->wireCode());
            $this->assertSame(422, $e->status);
            $this->assertSame(1, $e->row['seq']);
            $this->assertSame('c-1', $e->correlationId);
        }
        $t->on('GET', self::P.'/status', 503, '<html>maintenance</html>');
        try {
            $this->client($t)->status();
            $this->fail('no exception');
        } catch (RunnerClientException $e) {
            $this->assertSame('http_503', $e->wireCode());
        }
    }

    public function test_a_large_file_is_chunked_under_a_client_generated_uuid_in_order_then_finalized(): void
    {
        $t = new FakeTransport;
        $this->limits($t, maxUploadMb: 0, chunkMb: 8);
        $bytes = str_repeat('a', 10).str_repeat('b', 10).'ccc';
        $zip = $this->file($bytes);
        $t->on('POST', self::P.'/inbound/00000001-0000-4000-8000-000000000001/chunks', 200, ['received' => true]);
        $t->on('POST', self::P.'/inbound/00000001-0000-4000-8000-000000000001/finalize', 202, ['import_id' => '00000001-0000-4000-8000-000000000001', 'status' => 'staging']);

        $c = $this->client($t);
        $result = $c->uploadInbound($zip, autoApply: false, chunkBytes: 10);
        $this->assertSame('staging', $result['status']);
        $this->assertSame([
            'GET '.self::P.'/status',
            'POST '.self::P.'/inbound/00000001-0000-4000-8000-000000000001/chunks',
            'POST '.self::P.'/inbound/00000001-0000-4000-8000-000000000001/chunks',
            'POST '.self::P.'/inbound/00000001-0000-4000-8000-000000000001/chunks',
            'POST '.self::P.'/inbound/00000001-0000-4000-8000-000000000001/finalize',
        ], $t->calls(), 'the uuid is generated client-side and the first chunk creates the import');
        $chunks = array_slice($t->requests, 1, 3);
        $this->assertSame(['0', '1', '2'], array_map(fn ($r) => $r['multipart']['chunk_index'], $chunks));
        $this->assertSame(['3', '3', '3'], array_map(fn ($r) => $r['multipart']['total_chunks'], $chunks));
        $this->assertSame([str_repeat('a', 10), str_repeat('b', 10), 'ccc'], array_map(fn ($r) => $r['multipart']['chunk']['contents'], $chunks));
        $this->assertSame(hash('sha256', 'ccc'), $chunks[2]['multipart']['checksum']);
        $finalize = json_decode($t->requests[4]['body'], true);
        $this->assertSame(['total_chunks' => 3, 'sha256' => hash('sha256', $bytes), 'auto_apply' => false], $finalize);
        $keys = $t->idempotencyKeys();
        $this->assertCount(4, $keys);
        $this->assertCount(4, array_unique($keys), 'every chunk and the finalize are distinct attempts');
        $this->assertNotContains('00000001-0000-4000-8000-000000000001', $keys, 'the import uuid is not reused as an Idempotency-Key');
    }

    public function test_single_shot_when_the_file_fits_the_advertised_max_upload(): void
    {
        $t = new FakeTransport;
        $this->limits($t, maxUploadMb: 1);
        $t->on('POST', self::P.'/inbound', 202, ['import_id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'status' => 'staging']);
        $c = $this->client($t);
        $c->uploadInbound($this->file(str_repeat('x', 1024)));
        $this->assertSame(['GET '.self::P.'/status', 'POST '.self::P.'/inbound'], $t->calls());
    }

    public function test_wait_gives_up_at_the_deadline(): void
    {
        $t = new FakeTransport;
        $t->on('GET', self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002', 200, ['status' => 'staging']);
        try {
            $this->client($t)->waitInbound('bbbbbbbb-0000-4000-8000-000000000002', 10, 4);
            $this->fail('no timeout');
        } catch (RunnerClientException $e) {
            $this->assertSame('timeout', $e->wireCode());
            $this->assertSame([4.0, 4.0, 4.0], $this->slept);
        }
    }

    public function test_files_head_put_and_get(): void
    {
        $t = new FakeTransport;
        $sha = hash('sha256', 'pdf');
        $t->on('HEAD', self::P.'/files/'.$sha, 404);
        $t->on('PUT', self::P.'/files/'.$sha, 201, ['sha256' => $sha]);
        $t->on('GET', self::P.'/files/'.$sha, 200, 'pdf');
        $c = $this->client($t);
        $this->assertNull($c->headFile($sha));
        $path = $this->file('pdf');
        $c->putFile($sha, $path);
        $put = $t->requests[1];
        $this->assertSame($path, $put['body_path']);
        $this->assertSame('application/octet-stream', $put['headers']['Content-Type']);
        $this->assertArrayHasKey('Idempotency-Key', $put['headers']);
        $out = $this->temp[] = tempnam(sys_get_temp_dir(), 'txx-f');
        $c->getFile($sha, $out);
        $this->assertSame('pdf', file_get_contents($out));
        try {
            $c->putFile(str_repeat('0', 64), $path);
            $this->fail('put a file under the wrong hash');
        } catch (RunnerClientException $e) {
            $this->assertSame('hash_mismatch', $e->wireCode());
        }
    }

    public function test_receipt_is_null_until_it_exists(): void
    {
        $t = new FakeTransport;
        $t->on('GET', self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002/receipt', 404, ['error' => ['code' => 'not_found', 'message' => 'no receipt yet']]);
        $this->assertNull($this->client($t)->receipt('bbbbbbbb-0000-4000-8000-000000000002'));
    }

    public function test_stream_rows_page(): void
    {
        $t = new FakeTransport;
        $t->on('GET', self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001/streams/readiness', 200, "{\"job_number\":\"JB000001\"}\n{\"job_number\":\"JB000002\"}\n", ['X-Next-Cursor' => '2', 'X-Has-More' => 'true', 'X-Stream-Schema' => 'readiness@1']);
        $page = $this->client($t)->streamRows('aaaaaaaa-0000-4000-8000-000000000001', 'readiness', null, 2);
        $this->assertSame(['JB000001', 'JB000002'], array_column($page['rows'], 'job_number'));
        $this->assertSame('2', $page['next_cursor']);
        $this->assertTrue($page['has_more']);
        $this->assertSame('readiness@1', $page['schema']);
        $this->assertStringEndsWith('/streams/readiness?limit=2', $t->requests[0]['url']);
    }
}
