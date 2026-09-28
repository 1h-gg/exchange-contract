<?php

declare(strict_types=1);

namespace Texstar\Exchange\Tests\Support;

/**
 * A scripted transport for the runner client tests: records every request and answers from a queue
 * keyed by "METHOD path" (query stripped), or a default 200 {} when nothing is scripted.
 */
final class FakeTransport
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @var array<string, list<array{status: int, headers?: array<string, string>, body?: string}>> */
    private array $queue = [];

    /** @param array<string, string> $headers */
    public function on(string $method, string $path, int $status, array|string $body = [], array $headers = []): self
    {
        $this->queue[strtoupper($method).' '.$path][] = ['status' => $status, 'headers' => $headers, 'body' => is_string($body) ? $body : json_encode($body)];

        return $this;
    }

    /** @param array<string, mixed> $request @return array{status: int, headers: array<string, string>, body: string} */
    public function __invoke(array $request): array
    {
        $this->requests[] = $request;
        $path = (string) parse_url((string) $request['url'], PHP_URL_PATH);
        $key = strtoupper((string) $request['method']).' '.$path;
        $answer = ['status' => 200, 'headers' => [], 'body' => '{}'];
        if (isset($this->queue[$key]) && $this->queue[$key] !== []) {
            $answer = count($this->queue[$key]) > 1 ? array_shift($this->queue[$key]) : $this->queue[$key][0];
        }
        if (isset($request['sink'])) {
            file_put_contents((string) $request['sink'], (string) ($answer['body'] ?? ''));
        }

        return ['status' => $answer['status'], 'headers' => $answer['headers'] ?? [], 'body' => (string) ($answer['body'] ?? '')];
    }

    /** @return list<string> "METHOD path" in call order */
    public function calls(): array
    {
        return array_map(fn (array $r) => strtoupper((string) $r['method']).' '.parse_url((string) $r['url'], PHP_URL_PATH), $this->requests);
    }

    /** @return list<string> */
    public function idempotencyKeys(): array
    {
        return array_values(array_filter(array_map(fn (array $r) => $r['headers']['Idempotency-Key'] ?? null, $this->requests)));
    }
}
