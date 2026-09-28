<?php

declare(strict_types=1);

namespace Texstar\Exchange\Receipt;

use Texstar\Exchange\Dto\Producer;
use Texstar\Exchange\Dto\Receipt;
use Texstar\Exchange\Dto\Scope;
use Texstar\Exchange\Dto\StreamReceipt;

/**
 * Accumulates per-stream outcomes for one package and builds the {@see Receipt}. "Accepted" is every row
 * of the stream not listed in rejected_rows; rejects are keyed by (RowKey, seq), never by batch index.
 */
final class ReceiptBuilder
{
    /** @var array<string, array{received: int, rejected_rows: list<array{key: string, seq: int, code: string, message: string}>, cursor_to: mixed, applied_at: ?string}> */
    private array $streams = [];

    private ?string $status = null;

    private ?string $message = null;

    public function __construct(
        private readonly string $packageId,
        private readonly Producer $producer,
        private readonly Scope $scope,
        private readonly ?string $receiptId = null,
        private readonly ?string $producedAt = null,
    ) {}

    public function received(string $stream, int $count): self
    {
        $this->entry($stream)['received'] = $count;

        return $this;
    }

    public function reject(string $stream, string $key, int $seq, string $code, string $message): self
    {
        $this->entry($stream)['rejected_rows'][] = ['key' => $key, 'seq' => $seq, 'code' => $code, 'message' => mb_substr($message, 0, 500)];

        return $this;
    }

    public function cursorTo(string $stream, mixed $cursor): self
    {
        $this->entry($stream)['cursor_to'] = $cursor;

        return $this;
    }

    public function appliedAt(string $stream, string $iso): self
    {
        $this->entry($stream)['applied_at'] = $iso;

        return $this;
    }

    /** Force the status (default: derived — applied when nothing was rejected, rejected when everything was, partial otherwise). */
    public function status(string $status, ?string $message = null): self
    {
        $this->status = $status;
        $this->message = $message;

        return $this;
    }

    public function build(): Receipt
    {
        $streams = [];
        $received = 0;
        $rejected = 0;
        foreach ($this->streams as $name => $s) {
            $n = count($s['rejected_rows']);
            $streams[$name] = new StreamReceipt($s['received'], max(0, $s['received'] - $n), $n, $n === 0, $s['rejected_rows'], $s['cursor_to'], $s['applied_at']);
            $received += $s['received'];
            $rejected += $n;
        }
        $status = $this->status ?? match (true) {
            $rejected === 0 => Receipt::STATUS_APPLIED,
            $received > 0 && $rejected >= $received => Receipt::STATUS_REJECTED,
            default => Receipt::STATUS_PARTIAL,
        };

        return new Receipt(
            $this->packageId,
            $this->receiptId ?? self::uuid4(),
            $this->producedAt ?? gmdate('Y-m-d\TH:i:s\Z'),
            $this->producer,
            $this->scope,
            $streams,
            $status,
            $this->message,
        );
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** @return array{received: int, rejected_rows: list<array{key: string, seq: int, code: string, message: string}>, cursor_to: mixed, applied_at: ?string} */
    private function &entry(string $stream): array
    {
        $this->streams[$stream] ??= ['received' => 0, 'rejected_rows' => [], 'cursor_to' => null, 'applied_at' => null];

        return $this->streams[$stream];
    }
}
