<?php

declare(strict_types=1);

namespace Texstar\Exchange\Client;

use Texstar\Exchange\ExchangeException;

/** A non-2xx answer from /api/v1/exchange/*, decoded from the one error envelope {error: {code, message, row?, correlation_id?}}. */
final class RunnerClientException extends ExchangeException
{
    /** @param array<string, mixed>|null $row */
    public function __construct(string $code, string $message, public readonly int $status, public readonly ?array $row = null, public readonly ?string $correlationId = null)
    {
        parent::__construct($message, $code);
    }

    /** @param array{status: int, headers: array<string, string>, body: string} $response */
    public static function fromResponse(array $response): self
    {
        $decoded = json_decode($response['body'], true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $code = is_string($error['code'] ?? null) && $error['code'] !== '' ? $error['code'] : 'http_'.$response['status'];
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'HTTP '.$response['status'];

        return new self($code, $message, $response['status'], is_array($error['row'] ?? null) ? $error['row'] : null, is_string($error['correlation_id'] ?? null) ? $error['correlation_id'] : null);
    }
}
