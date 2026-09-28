<?php

declare(strict_types=1);

namespace Texstar\Exchange\Canonical;

use Texstar\Exchange\ExchangeException;

final class CanonicalJsonException extends ExchangeException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'canonical_json');
    }
}
