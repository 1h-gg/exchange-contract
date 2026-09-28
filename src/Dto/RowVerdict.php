<?php

declare(strict_types=1);

namespace Texstar\Exchange\Dto;

/** One row's outcome in a rows/updates response: `{seq, key, outcome, message}` (plus `op_id` for update ops). */
final readonly class RowVerdict
{
    use Extras;

    public const APPLIED = 'applied';

    public const UNCHANGED = 'unchanged';

    public const REJECTED = 'rejected';

    public const FORBIDDEN = 'forbidden';

    public const UNKNOWN_KEY = 'unknown_key';

    public const UNSUPPORTED_UNTIL_1D = 'unsupported_until_1d';

    public const OUTCOMES = [self::APPLIED, self::UNCHANGED, self::REJECTED, self::FORBIDDEN, self::UNKNOWN_KEY, self::UNSUPPORTED_UNTIL_1D];

    private const KNOWN = ['seq', 'key', 'outcome', 'message', 'op_id'];

    /** @param array<string, mixed> $extra */
    public function __construct(
        public int $seq,
        public string $key,
        public string $outcome,
        public ?string $message = null,
        public ?string $opId = null,
        public array $extra = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self((int) ($data['seq'] ?? 0), (string) ($data['key'] ?? ''), (string) ($data['outcome'] ?? ''), isset($data['message']) ? (string) $data['message'] : null, isset($data['op_id']) ? (string) $data['op_id'] : null, self::unknown($data, self::KNOWN));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['seq' => $this->seq, 'key' => $this->key, 'outcome' => $this->outcome, 'message' => $this->message];
        if ($this->opId !== null) {
            $out['op_id'] = $this->opId;
        }

        return $this->merged($out);
    }
}
