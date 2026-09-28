<?php

declare(strict_types=1);

namespace Texstar\Exchange\Dto;

/**
 * Per-stream outcome inside a {@see Receipt}. rejected_rows are keyed by RowKey + seq, never by batch index.
 *
 * `rejected` is the authoritative count. `rejected_rows` lists at most {@see \Texstar\Exchange\Receipt\ReceiptBuilder::MAX_LISTED_REJECTS}
 * of them (fewer when receipt.json had to shrink under Contract::MAX_MANIFEST_BYTES); the rest are counted in
 * `rejected_rows_omitted` (1.2.0, written only when > 0). Only a complete list ({@see listComplete()}) lets a
 * consumer treat an unlisted row as accepted.
 */
final readonly class StreamReceipt
{
    use Extras;

    private const KNOWN = ['received', 'accepted', 'rejected', 'accepted_all', 'rejected_rows', 'rejected_rows_omitted', 'cursor_to', 'applied_at'];

    /**
     * @param  list<array{key: string, seq: int, code: string, message: string}>  $rejectedRows
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public int $received,
        public int $accepted,
        public int $rejected,
        public bool $acceptedAll,
        public array $rejectedRows = [],
        public mixed $cursorTo = null,
        public ?string $appliedAt = null,
        public array $extra = [],
        public int $rejectedRowsOmitted = 0,
    ) {}

    /** Every rejected row is listed: an unlisted row of this stream was accepted. False when the list was capped. */
    public function listComplete(): bool
    {
        return $this->rejectedRowsOmitted === 0 && count($this->rejectedRows) >= $this->rejected;
    }

    /** This stream receipt with at most `$max` rejected rows listed; the rest move to rejected_rows_omitted. */
    public function withListedRejects(int $max): self
    {
        $max = max(0, $max);
        if (count($this->rejectedRows) <= $max) {
            return $this;
        }

        return new self(
            $this->received, $this->accepted, $this->rejected, $this->acceptedAll, array_slice($this->rejectedRows, 0, $max),
            $this->cursorTo, $this->appliedAt, $this->extra, $this->rejectedRowsOmitted + count($this->rejectedRows) - $max,
        );
    }

    public static function fromArray(array $data): self
    {
        $rows = [];
        foreach (is_array($data['rejected_rows'] ?? null) ? $data['rejected_rows'] : [] as $r) {
            if (is_array($r)) {
                $rows[] = ['key' => (string) ($r['key'] ?? ''), 'seq' => (int) ($r['seq'] ?? 0), 'code' => (string) ($r['code'] ?? ''), 'message' => (string) ($r['message'] ?? '')];
            }
        }

        return new self(
            (int) ($data['received'] ?? 0), (int) ($data['accepted'] ?? 0), (int) ($data['rejected'] ?? 0), (bool) ($data['accepted_all'] ?? false),
            $rows, $data['cursor_to'] ?? null, isset($data['applied_at']) ? (string) $data['applied_at'] : null, self::unknown($data, self::KNOWN),
            max(0, (int) ($data['rejected_rows_omitted'] ?? 0)),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'received' => $this->received, 'accepted' => $this->accepted, 'rejected' => $this->rejected, 'accepted_all' => $this->acceptedAll,
            'rejected_rows' => $this->rejectedRows, 'cursor_to' => $this->cursorTo, 'applied_at' => $this->appliedAt,
        ];
        if ($this->rejectedRowsOmitted > 0) {
            $out['rejected_rows_omitted'] = $this->rejectedRowsOmitted;
        }

        return $this->merged($out);
    }
}
