<?php

declare(strict_types=1);

namespace Texstar\Exchange\Package;

final class UnsignedPackageException extends PackageException
{
    public function __construct(string $kind)
    {
        parent::__construct('A kind='.$kind.' package must be signed; finalizeUnsigned() is allowed only for kind=rows', 'signature_missing');
    }
}
