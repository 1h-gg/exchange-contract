<?php

declare(strict_types=1);

namespace Texstar\Exchange\Package;

final class RowTooLargeException extends PackageException
{
    public function __construct(public readonly string $stream, public readonly int $seq, public readonly int $bytes, public readonly int $limit)
    {
        parent::__construct('Row '.$seq.' of stream '.$stream.' is '.$bytes.' bytes, over the '.$limit.'-byte limit', 'row_too_large');
    }
}
