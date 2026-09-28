<?php

declare(strict_types=1);

namespace Texstar\Exchange\Signing;

use Texstar\Exchange\ExchangeException;

/** Thrown by {@see Verifier}; `wireCode()` is signature_missing | unknown_key_id | signature_invalid. */
final class SignatureException extends ExchangeException
{
    public const MISSING = 'signature_missing';

    public const UNKNOWN_KEY = 'unknown_key_id';

    public const INVALID = 'signature_invalid';

    public function __construct(string $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
