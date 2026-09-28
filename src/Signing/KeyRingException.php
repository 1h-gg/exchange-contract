<?php

declare(strict_types=1);

namespace Texstar\Exchange\Signing;

use Texstar\Exchange\ExchangeException;

final class KeyRingException extends ExchangeException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'key_ring');
    }
}
