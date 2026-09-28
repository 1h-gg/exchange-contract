<?php

declare(strict_types=1);

namespace Texstar\Exchange;

/** Base of every exception this package throws; `code()` is the snake_case wire code. */
class ExchangeException extends \RuntimeException
{
    public function __construct(string $message, private readonly string $wireCode = 'exchange_error', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function wireCode(): string
    {
        return $this->wireCode;
    }
}
