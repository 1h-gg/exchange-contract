<?php

declare(strict_types=1);

namespace Texstar\Exchange\Dto;

/** Per-stream outcome inside a {@see Receipt}. rejected_rows are keyed by RowKey + seq, never by batch index. */
final readonly class StreamReceipt
{
    use Extras;

    private const KNOWN = ['received', 'accepted', 'rejected', 'accepted_all', 'rejected_rows', 'cursor_to', 'applied_at'];

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
    ) {}

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
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->merged([
            'received' => $this->received, 'accepted' => $this->accepted, 'rejected' => $this->rejected, 'accepted_all' => $this->acceptedAll,
            'rejected_rows' => $this->rejectedRows, 'cursor_to' => $this->cursorTo, 'applied_at' => $this->appliedAt,
        ]);
    }
}
