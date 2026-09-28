<?php

declare(strict_types=1);

namespace Texstar\Exchange\Tests;

use PHPUnit\Framework\TestCase;
use Texstar\Exchange\Cli\App;
use Texstar\Exchange\Cli\Tokens;
use Texstar\Exchange\Client\RunnerClient;
use Texstar\Exchange\Dto\Manifest;
use Texstar\Exchange\Dto\Producer;
use Texstar\Exchange\Dto\Scope;
use Texstar\Exchange\Package\PackageBuilder;
use Texstar\Exchange\Signing\KeyRing;
use Texstar\Exchange\Signing\Signer;
use Texstar\Exchange\Testing\ContractSuite;
use Texstar\Exchange\Tests\Support\FakeTransport;

final class CliTest extends TestCase
{
    private const P = RunnerClient::PREFIX;

    /** @var list<string> */
    private array $out = [];

    /** @var list<string> */
    private array $err = [];

    /** @var array<string, FakeTransport> base url => transport */
    private array $transports = [];

    /** @var list<string> */
    private array $tokenFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tokenFiles as $path) {
            @unlink($path);
        }
        $this->tokenFiles = [];
        parent::tearDown();
    }

    private function app(array $env = []): App
    {
        $this->out = [];
        $this->err = [];

        return new App(
            fn (string $l) => $this->out[] = $l,
            fn (string $l) => $this->err[] = $l,
            fn (string $url, string $token): RunnerClient => new RunnerClient($url, $token, $this->transports[$url] ??= new FakeTransport, fn () => null, fn (): float => 0.0),
            $env,
        );
    }

    public function test_parse_splits_options_and_positionals(): void
    {
        [$options, $positional] = App::parse(['--from=nesc', 'file.xlsx', '--both', '--to-url=https://x/y=z']);
        $this->assertSame(['from' => 'nesc', 'both' => true, 'to-url' => 'https://x/y=z'], $options);
        $this->assertSame(['file.xlsx'], $positional);
    }

    public function test_help_and_unknown_command(): void
    {
        $this->assertSame(0, $this->app()->run(['help']));
        $this->assertStringContainsString('usage: exchange', $this->out[0]);
        $this->assertSame(2, $this->app()->run(['nope']));
        $this->assertSame(['unknown command nope'], $this->err);
    }

    public function test_sync_drives_both_apps_and_prints_the_receipt(): void
    {
        $nesc = $this->transports['https://nesc.test'] = new FakeTransport;
        $pmo = $this->transports['https://pmo.test'] = new FakeTransport;
        $nesc->on('POST', self::P.'/outbound', 202, ['package_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'status' => 'queued']);
        $nesc->on('GET', self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001', 200, ['status' => 'ready', 'counts' => ['readiness' => ['rows' => 2], 'external_refs' => 7]]);
        $nesc->on('GET', self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001/download', 200, 'PK', ['X-Package-Sha256' => hash('sha256', 'PK')]);
        $pmo->on('GET', self::P.'/status', 200, ['limits' => ['max_upload_mb' => 60, 'chunk_mb' => 8]]);
        $pmo->on('POST', self::P.'/inbound', 202, ['import_id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'status' => 'staging']);
        $pmo->on('GET', self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002', 200, ['status' => 'applied']);
        $pmo->on('GET', self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002/receipt', 200, ['receipt_id' => 'cccccccc-0000-4000-8000-000000000003', 'status' => 'partial', 'streams' => ['readiness' => ['received' => 2, 'accepted' => 1, 'rejected' => 1, 'rejected_rows' => [['key' => 'JB000002', 'seq' => 1, 'code' => 'unknown_job', 'message' => 'not ours']]]]]);

        $code = $this->app(['EXCHANGE_FROM_TOKEN' => 'nesc-token', 'EXCHANGE_TO_TOKEN' => 'pmo-token'])->run(['sync', '--from=nesc', '--to=pmo', '--from-url=https://nesc.test', '--to-url=https://pmo.test', '--include-blobs', '--program=comcast-nesc']);

        $this->assertSame(0, $code, implode("\n", $this->err));
        $this->assertSame('{"include_blobs":true,"program":"comcast-nesc"}', $nesc->requests[0]['body']);
        $this->assertSame('Bearer nesc-token', $nesc->requests[0]['headers']['Authorization']);
        $this->assertSame('Bearer pmo-token', $pmo->requests[0]['headers']['Authorization']);
        $this->assertSame(['GET '.self::P.'/status', 'POST '.self::P.'/inbound', 'GET '.self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002', 'GET '.self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002/receipt'], $pmo->calls());
        $this->assertSame('1', $pmo->requests[1]['multipart']['auto_apply']);
        $this->assertFileDoesNotExist($pmo->requests[1]['multipart']['package']['path'], 'the temp zip is removed after the upload');
        $this->assertStringContainsString('package aaaaaaaa-0000-4000-8000-000000000001 ready: readiness=2 external_refs=7', implode("\n", $this->out));
        $this->assertStringContainsString('readiness                received=2 accepted=1 rejected=1', implode("\n", $this->out));
        $this->assertStringContainsString('seq 1 JB000002: unknown_job not ours', implode("\n", $this->err));
        $this->assertStringContainsString('receipt cccccccc-0000-4000-8000-000000000003 (partial)', implode("\n", $this->out));
    }

    public function test_sync_both_runs_the_reverse_direction_and_a_rejected_import_fails(): void
    {
        $nesc = $this->transports['https://nesc.test'] = new FakeTransport;
        $pmo = $this->transports['https://pmo.test'] = new FakeTransport;
        foreach ([$nesc, $pmo] as $t) {
            $t->on('GET', self::P.'/status', 200, ['limits' => ['max_upload_mb' => 60, 'chunk_mb' => 8]]);
            $t->on('POST', self::P.'/outbound', 202, ['package_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'status' => 'queued']);
            $t->on('GET', self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001', 200, ['status' => 'ready', 'counts' => []]);
            $t->on('GET', self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001/download', 200, 'PK', ['X-Package-Sha256' => hash('sha256', 'PK')]);
            $t->on('POST', self::P.'/inbound', 202, ['import_id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'status' => 'staging']);
        }
        $pmo->on('GET', self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002', 200, ['status' => 'applied']);
        $pmo->on('GET', self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002/receipt', 200, ['receipt_id' => 'r', 'status' => 'applied', 'streams' => []]);
        $nesc->on('GET', self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002', 200, ['status' => 'rejected', 'rejected_code' => 'signature_invalid', 'rejects' => []]);

        $code = $this->app()->run(['sync', '--from=nesc', '--to=pmo', '--from-url=https://nesc.test', '--to-url=https://pmo.test', '--from-token=a', '--to-token=b', '--both']);
        $this->assertSame(1, $code);
        $this->assertContains('POST '.self::P.'/outbound', $pmo->calls(), 'the reverse direction built a PMO package');
        $this->assertContains('POST '.self::P.'/inbound', $nesc->calls(), 'and uploaded it to NESC');
        $this->assertStringContainsString('[pmo -> nesc] import rejected: signature_invalid', implode("\n", $this->err));
    }

    public function test_sync_copies_the_referenced_files_the_target_lacks_before_the_upload(): void
    {
        $carried = '%PDF-1.4 carried';
        $present = '%PDF-1.4 present';
        $missing = '%PDF-1.4 missing';
        [$shaCarried, $shaPresent, $shaMissing] = [hash('sha256', $carried), hash('sha256', $present), hash('sha256', $missing)];
        $zip = (string) tempnam(sys_get_temp_dir(), 'txx-cli');
        unlink($zip);
        $zip .= '.txx.zip';
        $blob = tempnam(sys_get_temp_dir(), 'txx-cli');
        $this->tokenFiles[] = $zip;
        $this->tokenFiles[] = $blob;
        file_put_contents($blob, $carried);
        $b = PackageBuilder::open($zip, new Manifest(packageId: 'aaaaaaaa-0000-4000-8000-000000000001', direction: 'nesc_to_pmo', createdAt: '2026-09-28T12:00:00Z', producer: new Producer('nesc', '1.0.0', 'nescdev', '1.1.0'), scope: Scope::unscoped()));
        $report = static fn (string $id, ?string $sha): array => ['external_report_id' => $id, 'job_number' => 'JB000001', 'sha256' => $sha, 'content_base64' => null];
        $b->addStreamRows('inspection_reports', [$report('r1', $shaCarried), $report('r2', $shaPresent), $report('r3', $shaMissing), $report('r4', strtoupper($shaMissing)), $report('r5', null)]);
        $b->addBlob($shaCarried, $blob, 'application/pdf');
        $b->finalize(new Signer, KeyRing::fromEnvString('texstar:comcast=cli-test-key'), 'texstar:comcast');
        $bytes = (string) file_get_contents($zip);

        $nesc = $this->transports['https://nesc.test'] = new FakeTransport;
        $pmo = $this->transports['https://pmo.test'] = new FakeTransport;
        $nesc->on('POST', self::P.'/outbound', 202, ['package_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'status' => 'queued']);
        $nesc->on('GET', self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001', 200, ['status' => 'ready', 'counts' => ['inspection_reports' => ['rows' => 5]]]);
        $nesc->on('GET', self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001/download', 200, $bytes, ['X-Package-Sha256' => hash('sha256', $bytes)]);
        $nesc->on('GET', self::P.'/files/'.$shaMissing, 200, $missing);
        $pmo->on('GET', self::P.'/status', 200, ['limits' => ['max_upload_mb' => 60, 'chunk_mb' => 8]]);
        $pmo->on('HEAD', self::P.'/files/'.$shaPresent, 200, '', ['Content-Length' => '16']);
        $pmo->on('HEAD', self::P.'/files/'.$shaMissing, 404, ['error' => ['code' => 'not_found', 'message' => 'no']]);
        $pmo->on('PUT', self::P.'/files/'.$shaMissing, 201, ['sha256' => $shaMissing]);
        $pmo->on('POST', self::P.'/inbound', 202, ['import_id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'status' => 'staging']);
        $pmo->on('GET', self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002', 200, ['status' => 'applied']);
        $pmo->on('GET', self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002/receipt', 200, ['receipt_id' => 'r', 'status' => 'applied', 'streams' => []]);

        $code = $this->app()->run(['sync', '--from=nesc', '--to=pmo', '--from-url=https://nesc.test', '--to-url=https://pmo.test', '--from-token=a', '--to-token=b']);

        $this->assertSame(0, $code, implode("\n", $this->err));
        $this->assertSame([
            'HEAD '.self::P.'/files/'.$shaPresent,
            'HEAD '.self::P.'/files/'.$shaMissing,
            'PUT '.self::P.'/files/'.$shaMissing,
            'GET '.self::P.'/status',
            'POST '.self::P.'/inbound',
            'GET '.self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002',
            'GET '.self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002/receipt',
        ], $pmo->calls(), 'the carried blob is not probed, the present one is not copied, the missing one is PUT once, all before the upload');
        $this->assertContains('GET '.self::P.'/files/'.$shaMissing, $nesc->calls());
        $this->assertStringContainsString('[nesc -> pmo] files: 3 referenced, 1 in package, 1 already on pmo, 1 copied, 0 failed', implode("\n", $this->out));

        // --no-files skips the step; a failed copy is reported and the upload still goes ahead.
        foreach ([['--no-files'], []] as $i => $extra) {
            $this->transports = [];
            $nesc = $this->transports['https://nesc.test'] = new FakeTransport;
            $pmo = $this->transports['https://pmo.test'] = new FakeTransport;
            $nesc->on('POST', self::P.'/outbound', 202, ['package_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'status' => 'queued']);
            $nesc->on('GET', self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001', 200, ['status' => 'ready', 'counts' => []]);
            $nesc->on('GET', self::P.'/outbound/aaaaaaaa-0000-4000-8000-000000000001/download', 200, $bytes, ['X-Package-Sha256' => hash('sha256', $bytes)]);
            $nesc->on('GET', self::P.'/files/'.$shaMissing, 404, ['error' => ['code' => 'not_found', 'message' => 'gone']]);
            $pmo->on('HEAD', self::P.'/files/'.$shaPresent, 200, '');
            $pmo->on('HEAD', self::P.'/files/'.$shaMissing, 404, '');
            $pmo->on('POST', self::P.'/inbound', 202, ['import_id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'status' => 'staging']);
            $pmo->on('GET', self::P.'/inbound/bbbbbbbb-0000-4000-8000-000000000002', 200, ['status' => 'applied']);
            $code = $this->app()->run(array_merge(['sync', '--from=nesc', '--to=pmo', '--from-url=https://nesc.test', '--to-url=https://pmo.test', '--from-token=a', '--to-token=b'], $extra));
            $this->assertSame(0, $code, implode("\n", $this->err));
            $this->assertContains('POST '.self::P.'/inbound', $pmo->calls());
            $this->assertNotContains('PUT '.self::P.'/files/'.$shaMissing, $pmo->calls());
            if ($i === 0) {
                $this->assertSame([], array_values(array_filter($pmo->calls(), static fn (string $c): bool => str_starts_with($c, 'HEAD '))), '--no-files makes no file call');
            } else {
                $this->assertStringContainsString('file '.$shaMissing.' not copied: not_found gone', implode("\n", $this->err));
                $this->assertStringContainsString('[nesc -> pmo] files: 3 referenced, 1 in package, 1 already on pmo, 0 copied, 1 failed', implode("\n", $this->out));
            }
        }
    }

    public function test_sync_validates_its_options(): void
    {
        $this->assertSame(1, $this->app()->run(['sync', '--from=nesc', '--to=nesc', '--from-url=x', '--to-url=y', '--from-token=a', '--to-token=b']));
        $this->assertStringContainsString('--from and --to must differ', $this->err[0]);
        $this->assertSame(1, $this->app()->run(['sync', '--from=nesc', '--to=pmo', '--from-url=x', '--to-url=y']));
        $this->assertStringContainsString('EXCHANGE_FROM_TOKEN', $this->err[0]);
        $this->assertSame(1, $this->app()->run(['sync', '--from=erp', '--to=pmo']));
        $this->assertStringContainsString('nesc or pmo', $this->err[0]);
    }

    public function test_push_updates_sends_the_workbook_and_reports_verdicts(): void
    {
        $nesc = $this->transports['https://nesc.test'] = new FakeTransport;
        $nesc->on('POST', self::P.'/updates', 200, ['batch_id' => 'dddddddd-0000-4000-8000-000000000004', 'dry_run' => true, 'summary' => ['applied' => 1, 'rejected' => 1], 'verdicts' => [
            ['op_id' => 'op-1', 'key' => 'JB000001', 'outcome' => 'applied', 'message' => null],
            ['op_id' => 'op-2', 'key' => 'JB000009', 'outcome' => 'unknown_key', 'message' => 'no such job'],
        ]]);
        $xlsx = tempnam(sys_get_temp_dir(), 'txx-wb');
        file_put_contents($xlsx, 'PK');
        try {
            $code = $this->app(['EXCHANGE_TOKEN' => 't'])->run(['push-updates', $xlsx, '--url=https://nesc.test', '--dry-run']);
        } finally {
            unlink($xlsx);
        }
        $this->assertSame(1, $code, 'a rejected op fails the command');
        $this->assertSame($xlsx, $nesc->requests[0]['multipart']['workbook']['path']);
        $this->assertSame('1', $nesc->requests[0]['multipart']['dry_run']);
        $this->assertSame('0', $nesc->requests[0]['multipart']['create']);
        $this->assertSame('Bearer t', $nesc->requests[0]['headers']['Authorization']);
        $this->assertStringContainsString('batch dddddddd-0000-4000-8000-000000000004 (dry run): applied=1 rejected=1', $this->out[0]);
        $this->assertSame(['  op-2 JB000009: unknown_key no such job'], $this->err);
        $this->assertSame(1, $this->app()->run(['push-updates', '/nonexistent.xlsx', '--url=https://nesc.test', '--token=t']));
        $this->assertStringContainsString('Workbook not found', $this->err[0]);
    }

    public function test_fixture_commands_and_the_suite(): void
    {
        $this->assertSame(0, $this->app()->run(['suite:run']));
        $this->assertCount(16, $this->out);
        foreach ($this->out as $line) {
            $this->assertStringEndsWith(' ok', $line);
        }
        $base = tempnam(sys_get_temp_dir(), 'txx-cli');
        $out = $base.'.txx.zip';
        try {
            $this->assertSame(0, $this->app()->run(['fixtures:build', 'unscoped', '--out='.$out]));
            $this->assertSame([$out], $this->out);
            $this->assertSame(0, $this->app()->run(['package:inspect', $out, '--keys=texstar:comcast=golden-fixture-key']));
            $this->assertStringContainsString('signature verified (texstar:comcast)', implode("\n", $this->out));
            $this->assertStringContainsString('"program":"comcast-nesc"', $this->out[1]);
        } finally {
            @unlink($out);
            @unlink($base);
            ContractSuite::cleanup();
        }
        $this->assertSame(0, $this->app()->run(['schemas:audit']));
        $this->assertSame(['schemas/v1: clean'], $this->out);
        $this->assertSame(0, $this->app()->run(['fixtures:sign', '--fixture=unscoped']));
        $this->assertSame(['unscoped             signed'], $this->out);
    }

    public function test_a_token_flag_is_warned_about_and_a_token_file_is_read(): void
    {
        $this->assertSame('from-file', Tokens::resolve(['token-file' => $this->tokenFile('from-file')], 'token', ['EXCHANGE_TOKEN'], ['EXCHANGE_TOKEN' => 'env'], function (string $l): void {
            $this->fail('a token file is not warned about');
        }));
        $warnings = [];
        $warn = function (string $l) use (&$warnings): void {
            $warnings[] = $l;
        };
        $this->assertSame('env', Tokens::resolve(['token' => 'flag'], 'token', ['EXCHANGE_TOKEN'], ['EXCHANGE_TOKEN' => 'env'], $warn));
        $this->assertSame([], $warnings, 'the environment wins over the flag, silently');
        $this->assertSame('flag', Tokens::resolve(['token' => 'flag'], 'token', ['EXCHANGE_TOKEN'], [], $warn));
        $this->assertStringContainsString('ps output and shell history', $warnings[0]);
    }

    private function tokenFile(string $token): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'txx-token');
        file_put_contents($path, $token."\n");
        $this->tokenFiles[] = $path;

        return $path;
    }
}
