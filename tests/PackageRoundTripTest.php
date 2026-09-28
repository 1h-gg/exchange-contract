<?php

declare(strict_types=1);

namespace Texstar\Exchange\Tests;

use PHPUnit\Framework\TestCase;
use Texstar\Exchange\Contract;
use Texstar\Exchange\Dto\Producer;
use Texstar\Exchange\Dto\Scope;
use Texstar\Exchange\Package\BlobHashException;
use Texstar\Exchange\Package\HashMismatchException;
use Texstar\Exchange\Package\InflatedSizeException;
use Texstar\Exchange\Package\PackageBuilder;
use Texstar\Exchange\Package\PackageException;
use Texstar\Exchange\Package\PackageReader;
use Texstar\Exchange\Package\RowError;
use Texstar\Exchange\Package\RowTooLargeException;
use Texstar\Exchange\Package\UnsignedPackageException;
use Texstar\Exchange\Receipt\ReceiptBuilder;
use Texstar\Exchange\Signing\KeyRing;
use Texstar\Exchange\Signing\SignatureException;
use Texstar\Exchange\Signing\Signer;
use Texstar\Exchange\Testing\ZipTamper;
use Texstar\Exchange\Tests\Support\Packages;

final class PackageRoundTripTest extends TestCase
{
    use Packages;

    private const PDF = "%PDF-1.4\n%stub\n";

    /** Builds the reference package: 3 streams + 1 blob + 1 receipt, signed. @return array{0: string, 1: string} path and blob sha */
    private function buildReference(?callable $tweak = null): array
    {
        $path = $this->tempPath();
        $blob = $this->tempPath('.pdf');
        file_put_contents($blob, self::PDF);
        $sha = hash('sha256', self::PDF);

        $b = PackageBuilder::open($path, $this->draft());
        $b->addStreamRows('readiness', [$this->readinessRow('JB000001'), $this->readinessRow('JB000002')]);
        $b->addStreamRows('packets_wanted', [['job_number' => 'JB000001']]);
        $b->addStreamRows('worker_activity', [['kind' => 'job', 'job_number' => 'JB000001', 'wanted' => true], ['kind' => 'contractor', 'id' => 7, 'name' => 'Crew']]);
        $b->addBlob($sha, $blob, 'application/pdf');
        $receipt = (new ReceiptBuilder('9f3a1b2c-4d5e-4f60-8a71-8293a4b5c6d7', new Producer('nesc'), Scope::unscoped(), '8f3a1b2c-4d5e-4f60-8a71-8293a4b5c6d7', '2026-09-27T11:00:00Z'))
            ->received('jobs', 3)->reject('jobs', 'JB000009', 2, 'validation_failed', 'job_number missing')->cursorTo('jobs', ['updated_since' => '2026-09-27T00:00:00Z', 'after_id' => 9])
            ->build();
        $b->addReceipts([$receipt]);
        if ($tweak !== null) {
            $tweak($b);
        }
        $b->finalize(new Signer, $this->ring(), 'texstar:comcast', ['readiness_feed' => ['jobs_tracked' => 2]]);

        return [$path, $sha];
    }

    public function test_round_trip_streams_blob_and_receipt(): void
    {
        [$path, $sha] = $this->buildReference();

        $r = PackageReader::open($path, 10 * 1024 * 1024);
        $r->verify($this->ring());
        $this->assertSame([], array_map('strval', $r->validateManifest()));
        $this->assertTrue($r->negotiation()->accepted);
        $this->assertSame(['readiness', 'packets_wanted', 'worker_activity'], $r->negotiation()->streams);
        $this->assertSame(['streams/readiness.ndjson', 'streams/packets_wanted.ndjson', 'streams/worker_activity.ndjson', 'files/'.$sha, 'receipt.json'], $r->checkHashes());

        $m = $r->manifestDto();
        $this->assertSame('package', $m->kind);
        $this->assertSame('nesc_to_pmo', $m->direction);
        $this->assertSame('readiness@1', $m->files[0]->schema);
        $this->assertSame(2, $m->files[0]->rows);
        $this->assertSame('delta', $m->files[0]->mode);
        $this->assertSame('full', $m->files[1]->mode);
        $this->assertSame(['jobs_tracked' => 2], $m->extras['readiness_feed']);
        $this->assertSame($sha, $m->blobs[0]->sha256);
        $this->assertSame(strlen(self::PDF), $m->blobs[0]->bytes);
        $this->assertSame('texstar:comcast', $m->signature['key_id']);

        $rows = iterator_to_array($r->rows('readiness'));
        $this->assertSame([0, 1], array_keys($rows));
        $this->assertSame('JB000002', $rows[1]['job_number']);
        $this->assertSame(Scope::UNSCOPED, $rows[1]['scope'], 'scope is stamped on rows that lack it');
        $this->assertSame(1.5, $rows[0]['sow_claims'][0]['quantity'], 'floats survive');
        $this->assertSame(array_keys($this->readinessRow()) + [24 => 'scope'], array_keys($rows[0]), 'emitter key order is kept');

        $this->assertSame(self::PDF, stream_get_contents($r->blob($sha)));

        $receipts = $r->receipts();
        $this->assertCount(1, $receipts);
        $this->assertSame('9f3a1b2c-4d5e-4f60-8a71-8293a4b5c6d7', $receipts[0]->packageId);
        $this->assertSame('partial', $receipts[0]->status);
        $this->assertSame(2, $receipts[0]->streams['jobs']->accepted);
        $this->assertSame([['key' => 'JB000009', 'seq' => 2, 'code' => 'validation_failed', 'message' => 'job_number missing']], $receipts[0]->streams['jobs']->rejectedRows);
        $this->assertSame(['updated_since' => '2026-09-27T00:00:00Z', 'after_id' => 9], $receipts[0]->streams['jobs']->cursorTo);

        $this->assertSame($r->declaredSize(), $r->inflatedBytes() - $r->inflatedBytes() + $r->declaredSize());
        $this->assertGreaterThan(0, $r->declaredSize());
    }

    public function test_offsets_callback_fires_every_1000_rows(): void
    {
        $path = $this->tempPath();
        $b = PackageBuilder::open($path, $this->draft());
        $rows = (static function () {
            for ($i = 0; $i < 2500; $i++) {
                yield ['job_number' => sprintf('JB%06d', $i)];
            }
        })();
        $offsets = [];
        $file = $b->addStreamRows('packets_wanted', $rows, static function (int $seq, int $offset) use (&$offsets): void {
            $offsets[$seq] = $offset;
        });
        $b->finalize(new Signer, $this->ring(), 'texstar:comcast');

        $this->assertSame([0, 1000, 2000], array_keys($offsets));
        $this->assertSame(0, $offsets[0]);
        $this->assertSame(2500, $file->rows);
        $r = PackageReader::open($path);
        $zip = new \ZipArchive;
        $zip->open($path);
        $ndjson = (string) $zip->getFromName('streams/packets_wanted.ndjson');
        $zip->close();
        $line = json_decode(strtok(substr($ndjson, $offsets[1000]), "\n"), true);
        $this->assertSame('JB001000', $line['job_number'], 'the offset is the byte position of row 1000');
        $this->assertSame($file->bytes, $r->manifestDto()->files[0]->bytes);
    }

    public function test_tampered_stream_is_refused_by_check_hashes(): void
    {
        [$path] = $this->buildReference();
        $zip = new \ZipArchive;
        $zip->open($path);
        $content = $zip->getFromName('streams/packets_wanted.ndjson');
        $zip->deleteName('streams/packets_wanted.ndjson');
        $zip->addFromString('streams/packets_wanted.ndjson', str_replace('JB000001', 'JB000002', $content)); // same length, one byte differs
        $zip->close();

        $r = PackageReader::open($path);
        $r->verify($this->ring());
        $this->assertSame([], $r->validateManifest());
        try {
            $r->checkHashes();
            $this->fail('expected hash_mismatch');
        } catch (HashMismatchException $e) {
            $this->assertSame('streams/packets_wanted.ndjson', $e->entry);
            $this->assertSame('hash_mismatch', $e->wireCode());
        }
    }

    public function test_tampered_blob_is_refused_by_check_hashes(): void
    {
        [$path, $sha] = $this->buildReference();
        ZipTamper::flipStoredByte($path, 'files/'.$sha, 3);

        $r = PackageReader::open($path);
        $r->verify($this->ring());
        try {
            $r->checkHashes();
            $this->fail('expected hash_mismatch');
        } catch (HashMismatchException $e) {
            $this->assertSame('files/'.$sha, $e->entry);
        }
    }

    public function test_wrong_blob_entry_name_is_refused(): void
    {
        [$path, $sha] = $this->buildReference();
        $other = str_repeat('0', 64);
        $zip = new \ZipArchive;
        $zip->open($path);
        $zip->renameName('files/'.$sha, 'files/'.$other);
        $zip->close();

        $r = PackageReader::open($path);
        $keywords = array_map(static fn ($v) => $v->keyword, $r->validateManifest());
        $this->assertSame(['unexpected_entry', 'missing_entry'], $keywords);
        $this->expectException(PackageException::class);
        $this->expectExceptionMessage('no entry');
        $r->checkHashes();
    }

    public function test_blob_declared_under_another_sha_is_refused(): void
    {
        [$path, $sha] = $this->buildReference();
        $this->rewriteManifest($path, function (array $m) use ($sha): array {
            $m['blobs'][0]['sha256'] = str_repeat('1', 64); // path still files/<real sha>
            $m['blobs'][0]['path'] = 'files/'.$sha;

            return $m;
        });
        $r = PackageReader::open($path);
        $this->assertContains('bad_path', array_map(static fn ($v) => $v->keyword, $r->validateManifest()));
        $this->expectException(HashMismatchException::class);
        $r->checkHashes();
    }

    public function test_path_outside_the_closed_layout_is_refused_before_any_entry_is_read(): void
    {
        [$path] = $this->buildReference();
        $this->rewriteManifest($path, static function (array $m): array {
            $m['files'][1]['path'] = 'streams/../x.ndjson';
            $m['files'][1]['stream'] = '../x';

            return $m;
        });
        $r = PackageReader::open($path);
        $manifestBytes = $r->inflatedBytes();
        $violations = array_map('strval', $r->validateManifest());

        $this->assertContains('$.files[1].path [bad_path] must be streams/<stream>.ndjson for stream "../x"', $violations);
        $this->assertContains('$ [unexpected_entry] zip entry "streams/packets_wanted.ndjson" is not declared by the manifest', $violations);
        $this->assertSame($manifestBytes, $r->inflatedBytes(), 'only manifest.json was inflated');
        try {
            iterator_to_array($r->rows('../x'));
            $this->fail('expected refusal');
        } catch (PackageException $e) {
            $this->assertSame('unknown_stream', $e->wireCode());
        }
        $this->assertSame($manifestBytes, $r->inflatedBytes());
    }

    public function test_unexpected_zip_entry_is_reported(): void
    {
        [$path] = $this->buildReference();
        $zip = new \ZipArchive;
        $zip->open($path);
        $zip->addFromString('extra.txt', 'x');
        $zip->addFromString('files/', '');
        $zip->close();

        $violations = array_map('strval', PackageReader::open($path)->validateManifest());
        $this->assertSame(['$ [unexpected_entry] zip entry "extra.txt" is not declared by the manifest'], $violations, 'a bare files/ directory entry is tolerated');
    }

    public function test_declared_size_is_the_sum_of_central_directory_sizes(): void
    {
        [$path] = $this->buildReference();
        $zip = new \ZipArchive;
        $zip->open($path);
        $sum = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $sum += $zip->statIndex($i)['size'];
        }
        $zip->close();
        $this->assertSame($sum, PackageReader::open($path)->declaredSize());
    }

    public function test_under_declared_entry_cannot_inflate_past_the_cap(): void
    {
        $path = $this->tempPath();
        $b = PackageBuilder::open($path, $this->draft());
        $rows = (static function () {
            for ($i = 0; $i < 1024; $i++) {
                yield ['job_number' => sprintf('JB%06d', $i), 'pad' => str_repeat('x', 1000)]; // ~1 MB, compresses to a few KB
            }
        })();
        $b->addStreamRows('packets_wanted', $rows);
        $b->finalize(new Signer, $this->ring(), 'texstar:comcast');
        ZipTamper::declareSize($path, 'streams/packets_wanted.ndjson', 1024);

        $cap = 100 * 1024;
        $r = PackageReader::open($path, $cap);
        $this->assertLessThan($cap, $r->declaredSize(), 'the pre-check is fooled');
        try {
            $r->checkHashes();
            $this->fail('expected the inflate guard');
        } catch (InflatedSizeException $e) {
            $this->assertSame('unpacked_too_large', $e->wireCode());
            $this->assertSame(InflatedSizeException::ENTRY_OVERFLOW, $e->reason);
            $this->assertLessThanOrEqual($cap, $r->inflatedBytes());
            $this->assertLessThanOrEqual(1024 + 65536, $e->bytes, 'stopped within one read buffer of the declared size');
        }
        try {
            iterator_to_array($r->rows('packets_wanted'));
            $this->fail('expected the inflate guard');
        } catch (InflatedSizeException $e) {
            $this->assertSame(InflatedSizeException::ENTRY_OVERFLOW, $e->reason);
        }
        $this->assertLessThanOrEqual($cap + 65536, $r->inflatedBytes());
    }

    public function test_global_cap_fires_across_entries(): void
    {
        [$path] = $this->buildReference();
        $manifestBytes = PackageReader::open($path)->inflatedBytes();
        $cap = $manifestBytes + 1024; // the manifest fits, the first stream does not
        $r = PackageReader::open($path, $cap);
        try {
            $r->checkHashes();
            $this->fail('expected the cap');
        } catch (InflatedSizeException $e) {
            $this->assertSame(InflatedSizeException::CAP, $e->reason);
            $this->assertSame($cap, $e->limit);
            $this->assertLessThanOrEqual($cap + 65536, $e->bytes);
        }
        $this->expectException(InflatedSizeException::class);
        iterator_to_array(PackageReader::open($path, $manifestBytes - 1)->rows('readiness'));
    }

    public function test_oversize_row_is_reported_and_the_next_row_still_parses(): void
    {
        $path = $this->tempPath();
        $big = json_encode(['job_number' => 'JB000001', 'pad' => str_repeat('y', 2 * 1024 * 1024)]);
        $ok = json_encode(['job_number' => 'JB000002']);
        $ndjson = $big."\n".$ok."\n{not json}\n".$ok."\n";
        $b = PackageBuilder::open($path, $this->draft(), verbatim: true);
        $b->addVerbatimString('streams/packets_wanted.ndjson', $ndjson);
        $manifest = $this->draft()->toArray();
        $manifest['files'] = [['path' => 'streams/packets_wanted.ndjson', 'stream' => 'packets_wanted', 'schema' => 'packets_wanted@1', 'rows' => 4, 'sha256' => hash('sha256', $ndjson), 'bytes' => strlen($ndjson), 'mode' => 'full']];
        $b->finalizeVerbatim((new Signer)->signManifest(json_decode(json_encode($manifest), true), $this->ring(), 'texstar:comcast'));

        $r = PackageReader::open($path, 8 * 1024 * 1024);
        $r->verify($this->ring());
        $this->assertSame([], $r->validateManifest());
        $r->checkHashes();
        $rows = iterator_to_array($r->rows('packets_wanted'));
        $this->assertSame([0, 1, 2, 3], array_keys($rows));
        $this->assertInstanceOf(RowError::class, $rows[0]);
        $this->assertSame(RowError::TOO_LARGE, $rows[0]->code);
        $this->assertSame(strlen($big), $rows[0]->bytes);
        $this->assertSame('JB000002', $rows[1]['job_number']);
        $this->assertInstanceOf(RowError::class, $rows[2]);
        $this->assertSame(RowError::INVALID_JSON, $rows[2]->code);
        $this->assertSame('JB000002', $rows[3]['job_number']);
    }

    public function test_builder_refuses_an_oversize_row(): void
    {
        $b = PackageBuilder::open($this->tempPath(), $this->draft());
        $this->expectException(RowTooLargeException::class);
        $b->addStreamRows('packets_wanted', [['job_number' => 'JB000001', 'pad' => str_repeat('y', Contract::MAX_ROW_BYTES)]]);
    }

    public function test_builder_refuses_embedded_report_content(): void
    {
        $b = PackageBuilder::open($this->tempPath(), $this->draft());
        try {
            $b->addStreamRows('inspection_reports', [['external_report_id' => 'x', 'content_base64' => 'QUJD']]);
            $this->fail('expected refusal');
        } catch (PackageException $e) {
            $this->assertSame('row_invalid', $e->wireCode());
        }
    }

    public function test_builder_recomputes_blob_hashes(): void
    {
        $blob = $this->tempPath('.pdf');
        file_put_contents($blob, self::PDF);
        $b = PackageBuilder::open($this->tempPath(), $this->draft());
        try {
            $b->addBlob(str_repeat('a', 64), $blob, 'application/pdf');
            $this->fail('expected BlobHashException');
        } catch (BlobHashException $e) {
            $this->assertSame(hash('sha256', self::PDF), $e->actual);
            $this->assertSame('hash_mismatch', $e->wireCode());
        }
        $fromStream = $b->addBlob(hash('sha256', self::PDF), fopen($blob, 'rb'), 'application/pdf');
        $this->assertSame('files/'.hash('sha256', self::PDF), $fromStream->path);
    }

    public function test_finalize_unsigned_is_refused_for_kind_package(): void
    {
        $b = PackageBuilder::open($this->tempPath(), $this->draft('package'));
        $b->addStreamRows('packets_wanted', [['job_number' => 'JB000001']]);
        try {
            $b->finalizeUnsigned();
            $this->fail('expected UnsignedPackageException');
        } catch (UnsignedPackageException $e) {
            $this->assertSame('signature_missing', $e->wireCode());
        }
    }

    public function test_finalize_unsigned_works_for_kind_rows_and_verify_then_throws_signature_missing(): void
    {
        $path = $this->tempPath();
        $b = PackageBuilder::open($path, $this->draft('rows'));
        $b->addStreamRows('packets_wanted', [['job_number' => 'JB000001']]);
        $m = $b->finalizeUnsigned(['note' => 'api rows']);
        $this->assertNull($m->signature);
        $this->assertSame('rows', $m->kind);

        $r = PackageReader::open($path);
        $this->assertSame('rows', $r->kind());
        $this->assertSame([], $r->validateManifest(), 'schema-valid: signature null is allowed on the wire');
        $r->checkHashes();
        try {
            $r->verify($this->ring());
            $this->fail('expected signature_missing');
        } catch (SignatureException $e) {
            $this->assertSame(SignatureException::MISSING, $e->wireCode());
        }
    }

    public function test_signed_package_verified_against_an_empty_ring_or_re_signed_key_is_refused(): void
    {
        [$path] = $this->buildReference();
        $r = PackageReader::open($path);
        try {
            $r->verify(KeyRing::fromEnvString(''));
            $this->fail('expected refusal');
        } catch (SignatureException $e) {
            $this->assertSame(SignatureException::UNKNOWN_KEY, $e->wireCode());
        }
        $this->rewriteManifest($path, fn (array $m) => (new Signer)->signManifest($m, KeyRing::fromArray(['texstar:comcast' => 'another-secret']), 'texstar:comcast'));
        try {
            PackageReader::open($path)->verify($this->ring());
            $this->fail('expected refusal');
        } catch (SignatureException $e) {
            $this->assertSame(SignatureException::INVALID, $e->wireCode());
        }
    }

    public function test_reader_refuses_a_zip_without_manifest_and_bad_kinds(): void
    {
        $path = $this->tempPath();
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('streams/jobs.ndjson', "{}\n");
        $zip->close();
        try {
            PackageReader::open($path);
            $this->fail('expected manifest_missing');
        } catch (PackageException $e) {
            $this->assertSame('manifest_missing', $e->wireCode());
        }
        $this->expectException(PackageException::class);
        PackageBuilder::open($this->tempPath(), $this->draft('bundle'));
    }

    public function test_duplicate_stream_and_use_after_finalize_are_refused(): void
    {
        $b = PackageBuilder::open($this->tempPath(), $this->draft());
        $b->addStreamRows('packets_wanted', []);
        try {
            $b->addStreamRows('packets_wanted', []);
            $this->fail('expected duplicate_stream');
        } catch (PackageException $e) {
            $this->assertSame('duplicate_stream', $e->wireCode());
        }
        $b->finalize(new Signer, $this->ring(), 'texstar:comcast');
        $this->expectException(PackageException::class);
        $b->addStreamRows('jobs', []);
    }

    public function test_a_zip_naming_one_entry_twice_is_refused_at_open(): void
    {
        $path = $this->tempPath();
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', '{}');
        $zip->addFromString('streams/aaaa.ndjson', "{}\n");
        $zip->addFromString('streams/bbbb.ndjson', "{}\n");
        $zip->close();
        ZipTamper::renameEntry($path, 'streams/bbbb.ndjson', 'streams/aaaa.ndjson');

        try {
            PackageReader::open($path);
            $this->fail('expected duplicate_entry');
        } catch (PackageException $e) {
            $this->assertSame('duplicate_entry', $e->wireCode());
        }
    }

    public function test_receipt_json_is_hash_bound_and_only_declared_receipts_are_returned(): void
    {
        [$path] = $this->buildReference();
        $m = PackageReader::open($path)->manifest();
        $this->assertCount(1, $m['receipts']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $m['receipts'][0]['sha256']);
        $this->assertIsInt($m['receipts'][0]['bytes']);

        // An edited receipt.json under the untouched signed manifest: refused by checkHashes() AND receipts().
        $zip = new \ZipArchive;
        $zip->open($path);
        $doc = json_decode((string) $zip->getFromName('receipt.json'), true);
        $doc['receipts'][0]['streams']['jobs']['rejected_rows'] = [];
        $zip->addFromString('receipt.json', json_encode($doc));
        $zip->close();
        $r = PackageReader::open($path);
        $r->verify($this->ring());
        foreach (['checkHashes', 'receipts'] as $method) {
            try {
                $r->{$method}();
                $this->fail($method.' accepted a tampered receipt.json');
            } catch (HashMismatchException $e) {
                $this->assertSame('hash_mismatch', $e->wireCode());
                $this->assertSame('receipt.json', $e->entry);
            }
        }
    }

    public function test_receipts_for_undeclared_package_ids_are_ignored(): void
    {
        $path = $this->tempPath();
        $b = PackageBuilder::open($path, $this->draft());
        $receipt = fn (string $id) => (new ReceiptBuilder($id, new Producer('pmo'), Scope::unscoped()))->received('readiness', 1)->build();
        $b->addReceipts([$receipt('1f3a1b2c-4d5e-4f60-8a71-8293a4b5c6d7'), $receipt('2f3a1b2c-4d5e-4f60-8a71-8293a4b5c6d7')]);
        $b->finalize(new Signer, $this->ring(), 'texstar:comcast');
        $this->assertCount(2, PackageReader::open($path)->receipts());

        // A manifest that declares only the first: the second receipt inside receipt.json is dropped.
        $this->rewriteManifest($path, function (array $m): array {
            $m['receipts'] = [$m['receipts'][0]];

            return $m;
        });
        $ids = array_map(fn ($r) => $r->packageId, PackageReader::open($path)->receipts());
        $this->assertSame(['1f3a1b2c-4d5e-4f60-8a71-8293a4b5c6d7'], $ids);
    }

    public function test_assert_scope_binds_the_key_to_the_scope_and_the_scope_to_the_instance(): void
    {
        $ring = KeyRing::fromEnvString('texstar:comcast=golden-fixture-key,other:pair=other-secret');
        $build = function (?Scope $scope, string $keyId) use ($ring): PackageReader {
            $path = $this->tempPath();
            $b = PackageBuilder::open($path, $this->draft('package', 'pmo_to_nesc', $scope));
            $b->addStreamRows('packets_wanted', [['job_number' => 'JB000001']]);
            $b->finalize(new Signer, $ring, $keyId);
            $r = PackageReader::open($path);
            $r->verify($ring);

            return $r;
        };
        $local = Scope::unscoped();
        $build(null, 'texstar:comcast')->assertScope($local); // unscoped resolves to texstar/comcast/comcast-nesc
        $build(Scope::resolve(['program' => 'comcast-nesc']), 'texstar:comcast')->assertScope($local);

        foreach ([[null, 'other:pair'], [Scope::resolve(['program' => 'comcast-other']), 'texstar:comcast'], [Scope::resolve(['contract' => 'pair', 'tenant' => 'other']), 'other:pair']] as [$scope, $keyId]) {
            try {
                $build($scope, $keyId)->assertScope($local);
                $this->fail('expected scope_mismatch for '.$keyId.' '.json_encode($scope?->toArray()));
            } catch (PackageException $e) {
                $this->assertSame('scope_mismatch', $e->wireCode());
            }
        }
    }

    /** Replace manifest.json in place (the signature is left as the callback returns it). */
    private function rewriteManifest(string $path, callable $edit): void
    {
        $zip = new \ZipArchive;
        $zip->open($path);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->deleteName('manifest.json');
        $zip->addFromString('manifest.json', json_encode($edit($manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();
    }
}
