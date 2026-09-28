<?php

declare(strict_types=1);

namespace Texstar\Exchange\Package;

final class BlobHashException extends PackageException
{
    public function __construct(public readonly string $declared, public readonly string $actual)
    {
        parent::__construct('Blob hash mismatch: declared '.$declared.', computed '.$actual, 'hash_mismatch');
    }
}
