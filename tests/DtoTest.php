<?php

declare(strict_types=1);

namespace Texstar\Exchange\Tests;

use PHPUnit\Framework\TestCase;
use Texstar\Exchange\Contract;
use Texstar\Exchange\Dto\Manifest;
use Texstar\Exchange\Dto\Producer;
use Texstar\Exchange\Dto\Receipt;
use Texstar\Exchange\Dto\RowVerdict;
use Texstar\Exchange\Dto\Scope;
use Texstar\Exchange\Dto\StreamFile;
use Texstar\Exchange\Dto\UpdateOp;
use Texstar\Exchange\Receipt\ReceiptBuilder;

final class DtoTest extends TestCase
{
    public function test_scope_resolves_null_and_partials_to_unscoped(): void
    {
        $this->assertSame(['tenant' => 'texstar', 'contract' => 'comcast', 'program' => 'comcast-nesc', 'instance' => null], Scope::UNSCOPED);
        $this->assertSame(Scope::UNSCOPED, Scope::resolve(null)->toArray());
        $this->assertSame(Scope::UNSCOPED, Scope::resolve([])->toArray());
        $this->assertSame(Scope::UNSCOPED, Scope::resolve(['tenant' => '', 'program' => null])->toArray());
        $this->assertTrue(Scope::unscoped()->isUnscoped());
        $this->assertSame('texstar:comcast', Scope::unscoped()->keyId());

        $partial = Scope::resolve(['tenant' => 'dycom', 'instance' => 'east']);
        $this->assertSame(['tenant' => 'dycom', 'contract' => 'comcast', 'program' => 'comcast-nesc', 'instance' => 'east'], $partial->toArray());
        $this->assertSame('dycom:comcast', $partial->keyId());
        $this->assertFalse($partial->isUnscoped());
    }

    public function test_directions_and_kinds_are_ascii(): void
    {
        $this->assertSame('nesc_to_pmo', Contract::DIRECTION_NESC_TO_PMO);
        $this->assertSame('pmo_to_nesc', Contract::DIRECTION_PMO_TO_NESC);
        $this->assertSame(['nesc_to_pmo', 'pmo_to_nesc'], Contract::DIRECTIONS);
        foreach (Contract::DIRECTIONS as $d) {
            $this->assertMatchesRegularExpression('~^[a-z_]+$~', $d);
        }
        $this->assertSame(['package', 'rows'], Contract::KINDS);
        $this->assertSame('texstar-exchange', Contract::FORMAT);
        $this->assertSame('texstar-exchange-receipt', Contract::RECEIPT_FORMAT);
        $this->assertSame(1, Contract::MAJOR);
        $this->assertSame(1048576, Contract::MAX_ROW_BYTES);
        $this->assertMatchesRegularExpression('~^1\.\d+\.\d+$~', Contract::VERSION);
    }

    public function test_manifest_round_trip_keeps_unknown_keys_and_defaults_kind(): void
    {
        $in = [
            'format' => 'texstar-exchange', 'version' => 1, 'contract' => '1.0.0', 'package_id' => 'p', 'direction' => 'pmo_to_nesc', 'created_at' => '2026-09-27T00:00:00Z',
            'producer' => ['app' => 'pmo', 'version' => '2', 'instance' => 'pmo', 'contract' => '1.0.0', 'build' => 'abc'],
            'cursors' => ['jobs' => ['updated_since' => 'x', 'after_id' => 1]],
            'files' => [['path' => 'streams/jobs.ndjson', 'stream' => 'jobs', 'schema' => 'jobs@1', 'rows' => 2, 'sha256' => str_repeat('a', 64), 'mode' => 'delta', 'vendor' => 1]],
            'blobs' => [['sha256' => str_repeat('b', 64), 'bytes' => 3, 'mime' => 'application/pdf', 'path' => 'files/'.str_repeat('b', 64)]],
            'receipts' => [['package_id' => 'q', 'path' => 'receipt.json']],
            'extras' => ['p2_runner_summary' => ['x' => 1]],
            'signature' => ['alg' => 'HMAC-SHA256', 'key_id' => 'texstar:comcast', 'value' => str_repeat('c', 64)],
            'future_field' => true,
        ];
        $m = Manifest::fromArray($in);
        $this->assertSame('package', $m->kind);
        $this->assertNull($m->scope);
        $this->assertSame(Scope::UNSCOPED, $m->resolvedScope()->toArray());
        $this->assertSame(['build' => 'abc'], $m->producer->extra);
        $this->assertSame(['vendor' => 1], $m->files[0]->extra);
        $this->assertNull($m->files[0]->bytes);
        $this->assertSame(['future_field' => true], $m->extra);
        $this->assertTrue($m->isSigned());

        $out = $m->toArray();
        $this->assertSame('package', $out['kind']);
        $this->assertArrayNotHasKey('scope', $out);
        $this->assertSame($in['cursors'], $out['cursors']);
        $this->assertSame(1, $out['files'][0]['vendor']);
        $this->assertTrue($out['future_field']);
        $this->assertSame($in['signature'], $out['signature']);

        $withScope = Manifest::fromArray($in + ['scope' => ['tenant' => 'dycom'], 'kind' => 'rows']);
        $this->assertSame('rows', $withScope->kind);
        $this->assertSame('dycom', $withScope->scope->tenant);
        $this->assertSame('comcast-nesc', $withScope->toArray()['scope']['program']);
    }

    public function test_manifest_empty_objects_serialize_as_objects(): void
    {
        $m = new Manifest('p', 'nesc_to_pmo', '2026-09-27T00:00:00Z', new Producer('nesc'));
        $json = json_encode($m->toArray());
        $this->assertStringContainsString('"cursors":{}', $json);
        $this->assertStringContainsString('"extras":{}', $json);
        $this->assertStringContainsString('"files":[]', $json);
        $this->assertStringContainsString('"signature":null', $json);
    }

    public function test_stream_file_optional_set_fields(): void
    {
        $f = StreamFile::fromArray(['path' => 'streams/poles.ndjson', 'stream' => 'poles', 'schema' => 'poles@1', 'rows' => 10, 'sha256' => str_repeat('a', 64), 'mode' => 'full', 'set_checksum' => str_repeat('d', 64), 'set_total' => 10]);
        $this->assertSame(str_repeat('d', 64), $f->setChecksum);
        $this->assertSame(10, $f->toArray()['set_total']);
        $this->assertArrayNotHasKey('set_total', StreamFile::fromArray(['path' => 'x', 'stream' => 'x', 'schema' => 'x@1', 'rows' => 0, 'sha256' => ''])->toArray());
    }

    public function test_receipt_builder_and_round_trip(): void
    {
        $receipt = (new ReceiptBuilder('p', new Producer('pmo', '2'), Scope::resolve(['tenant' => 'dycom'])))
            ->received('jobs', 3)->reject('jobs', 'JB000003', 2, 'validation_failed', str_repeat('m', 600))->appliedAt('jobs', '2026-09-27T00:00:00Z')
            ->received('poles', 2)->reject('poles', 'k1', 0, 'x', 'a')->reject('poles', 'k2', 1, 'x', 'b')
            ->build();

        $this->assertSame('partial', $receipt->status);
        $this->assertMatchesRegularExpression('~^[0-9a-f-]{36}$~', $receipt->receiptId);
        $this->assertSame(2, $receipt->streams['jobs']->accepted);
        $this->assertFalse($receipt->streams['jobs']->acceptedAll);
        $this->assertSame(500, mb_strlen($receipt->streams['jobs']->rejectedRows[0]['message']));
        $this->assertSame(0, $receipt->streams['poles']->accepted);
        $this->assertSame('dycom', $receipt->scope->tenant);

        $again = Receipt::fromArray(json_decode(json_encode($receipt->toArray()), true));
        $this->assertSame($receipt->toArray(), $again->toArray());
        $this->assertSame('texstar-exchange-receipt', $again->format);

        $this->assertSame('applied', (new ReceiptBuilder('p', new Producer('pmo'), Scope::unscoped()))->received('jobs', 1)->build()->status);
        $this->assertSame('rejected', (new ReceiptBuilder('p', new Producer('pmo'), Scope::unscoped()))->received('jobs', 1)->reject('jobs', 'k', 0, 'x', 'y')->build()->status);
        $forced = (new ReceiptBuilder('p', new Producer('pmo'), Scope::unscoped()))->status('rejected', 'unpacked_too_large')->build();
        $this->assertSame('rejected', $forced->status);
        $this->assertSame('unpacked_too_large', $forced->message);
        $this->assertSame('unpacked_too_large', $forced->toArray()['message']);
    }

    public function test_receipt_builder_lists_at_most_the_cap_and_counts_every_reject(): void
    {
        $b = new ReceiptBuilder('p', new Producer('nesc'), Scope::unscoped());
        $b->received('jobs', 5000)->received('poles', 3);
        for ($i = 0; $i < 4000; $i++) {
            $b->reject('jobs', 'JB'.$i, $i, 'schema_invalid', 'drift');
        }
        $b->reject('poles', 'k', 0, 'x', 'y');
        $receipt = $b->build();

        $jobs = $receipt->streams['jobs'];
        $this->assertSame(4000, $jobs->rejected);
        $this->assertSame(1000, $jobs->accepted);
        $this->assertCount(ReceiptBuilder::MAX_LISTED_REJECTS, $jobs->rejectedRows);
        $this->assertSame(3000, $jobs->rejectedRowsOmitted);
        $this->assertFalse($jobs->listComplete());
        $this->assertSame(3000, $jobs->toArray()['rejected_rows_omitted']);
        $this->assertTrue($receipt->streams['poles']->listComplete());
        $this->assertArrayNotHasKey('rejected_rows_omitted', $receipt->streams['poles']->toArray(), 'written only when > 0, so 1.1.0 receipts hash the same');
        $this->assertSame('partial', $receipt->status);

        $again = Receipt::fromArray(json_decode(json_encode($receipt->toArray()), true));
        $this->assertSame(3000, $again->streams['jobs']->rejectedRowsOmitted);
        $this->assertSame($receipt->toArray(), $again->toArray());

        $trimmed = $receipt->withListedRejects(10);
        $this->assertCount(10, $trimmed->streams['jobs']->rejectedRows);
        $this->assertSame(3990, $trimmed->streams['jobs']->rejectedRowsOmitted);
        $this->assertSame(4000, $trimmed->streams['jobs']->rejected);
        $this->assertSame($receipt->streams['poles'], $trimmed->streams['poles']);

        $all = (new ReceiptBuilder('p', new Producer('nesc'), Scope::unscoped()))->listAtMost(0)->received('jobs', 2)->reject('jobs', 'a', 0, 'x', 'y')->reject('jobs', 'b', 1, 'x', 'y')->build();
        $this->assertSame([], $all->streams['jobs']->rejectedRows);
        $this->assertSame('rejected', $all->status, 'status follows the count, not the list');

        $validator = new \Texstar\Exchange\Schema\SchemaValidator;
        $schema = json_decode((string) file_get_contents(dirname(__DIR__).'/schemas/v1/receipt.schema.json'), true);
        $this->assertSame([], $validator->validate($jobs->toArray(), $schema['$defs']['stream_receipt']));
    }

    public function test_update_op_and_row_verdict(): void
    {
        $op = UpdateOp::fromArray(['id' => 'u1', 'op' => 'update', 'entity' => 'job', 'key' => 'JB000001', 'field' => 'folder_link', 'value' => 'https://x', 'reason' => 'r', 'program' => null, 'origin' => 'smartsheet', 'origin_ref' => ['sheet' => 1], 'extra_key' => 1]);
        $this->assertTrue($op->isBasic());
        $this->assertSame('smartsheet', $op->toArray()['origin']);
        $this->assertSame(1, $op->toArray()['extra_key']);
        $this->assertFalse(UpdateOp::fromArray(['op' => 'override'])->isBasic());
        $this->assertArrayNotHasKey('origin', UpdateOp::fromArray(['op' => 'clear'])->toArray());
        $this->assertSame(['update', 'clear'], UpdateOp::OPS_BASIC);

        $v = RowVerdict::fromArray(['seq' => 3, 'key' => 'JB000001', 'outcome' => 'unsupported_until_1d', 'message' => 'x', 'op_id' => 'u1']);
        $this->assertSame('u1', $v->toArray()['op_id']);
        $this->assertContains($v->outcome, RowVerdict::OUTCOMES);
        $this->assertArrayNotHasKey('op_id', RowVerdict::fromArray(['seq' => 0, 'key' => 'k', 'outcome' => 'applied'])->toArray());
    }
}
