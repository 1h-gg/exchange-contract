<?php

declare(strict_types=1);

namespace Texstar\Exchange\Schema;

use Texstar\Exchange\ExchangeException;

final class SchemaException extends ExchangeException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'schema');
    }
}
