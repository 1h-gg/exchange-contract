<?php

declare(strict_types=1);

namespace Texstar\Exchange\Package;

/**
 * The inflated-bytes guard fired: the running total crossed the reader's cap (`cap`), or one entry
 * inflated past the size its central-directory record declares (`entry_overflow`). Mapped to
 * unpacked_too_large by the importer. The overshoot is at most one read buffer.
 */
final class InflatedSizeException extends PackageException
{
    public const CAP = 'cap';

    public const ENTRY_OVERFLOW = 'entry_overflow';

    public function __construct(public readonly string $reason, public readonly int $bytes, public readonly int $limit, public readonly ?string $entry = null)
    {
        parent::__construct(
            $reason === self::CAP
                ? 'Package inflated past the cap of '.$limit.' bytes'
                : 'Entry '.$entry.' inflated past its declared size of '.$limit.' bytes',
            'unpacked_too_large',
        );
    }
}
