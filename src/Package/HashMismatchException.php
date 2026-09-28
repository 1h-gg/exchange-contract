<?php

declare(strict_types=1);

namespace Texstar\Exchange\Package;

final class HashMismatchException extends PackageException
{
    public function __construct(public readonly string $entry, string $detail)
    {
        parent::__construct('Entry '.$entry.': '.$detail, 'hash_mismatch');
    }
}
