<?php

declare(strict_types=1);

namespace Texstar\Exchange\Package;

/** A line the reader could not turn into a row; yielded in place of the row so `seq` stays stable. */
final readonly class RowError
{
    public const TOO_LARGE = 'row_too_large';

    public const INVALID_JSON = 'invalid_json';

    public function __construct(public int $seq, public string $code, public string $message, public int $bytes = 0) {}

    /** @return array{seq: int, code: string, message: string, bytes: int} */
    public function toArray(): array
    {
        return ['seq' => $this->seq, 'code' => $this->code, 'message' => $this->message, 'bytes' => $this->bytes];
    }
}
