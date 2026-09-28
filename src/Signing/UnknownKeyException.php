<?php

declare(strict_types=1);

namespace Texstar\Exchange\Signing;

use Texstar\Exchange\ExchangeException;

final class UnknownKeyException extends ExchangeException
{
    public function __construct(public readonly string $keyId)
    {
        parent::__construct('Unknown signing key id "'.$keyId.'"', 'unknown_key_id');
    }
}
