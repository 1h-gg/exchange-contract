<?php

declare(strict_types=1);

namespace Texstar\Exchange\Package;

/**
 * A verified package that does not belong to this instance: its signing key id is not the
 * "<tenant>:<contract>" of its own resolved scope, or that scope names another tenant, contract or
 * program than the reader serves. Wire code `scope_mismatch`.
 */
final class ScopeMismatchException extends PackageException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'scope_mismatch');
    }
}
