<?php

declare(strict_types=1);

namespace Texstar\Exchange\Package;

use Texstar\Exchange\ExchangeException;

/** A malformed or unreadable package; `wireCode()` names the reason (manifest_invalid, unknown_stream, ...). */
class PackageException extends ExchangeException
{
    public function __construct(string $message, string $code = 'package_invalid')
    {
        parent::__construct($message, $code);
    }
}
