<?php

declare(strict_types=1);

namespace Texstar\Exchange\Client;

use Texstar\Exchange\Uuid;

/**
 * Reference client of the /api/v1/exchange/* runner API (identical on NESC and PMO).
 *
 * - ext-curl sits behind `callable $transport` so tests (and other stacks) can swap it;
 * - every POST/PUT carries an Idempotency-Key: one uuid v4 per ATTEMPT, where an attempt is
 *   (method, path, fields, body/file sha256). Retrying the same upload replays it; a changed file
 *   is a new attempt and gets a fresh key;
 * - 429 answers are retried after Retry-After (seconds or HTTP date) with the SAME key;
 * - `uploadInbound()` reads `limits` from GET /status: a file within max_upload_mb goes single-shot,
 *   a larger one is chunked under a client-generated v4 uuid (the first chunk creates the import);
 * - `waitOutbound()` / `waitInbound()` poll until a terminal status or the deadline.
 */
final class RunnerClient
{
    public const PREFIX = '/api/v1/exchange';

    private const MB = 1024 * 1024;

    private const MAX_RETRIES = 5;

    /** @var callable(array<string, mixed>): array{status: int, headers: array<string, string>, body: string} */
    private $transport;

    /** @var callable(float): void */
    private $sleep;

    /** @var callable(): float */
    private $clock;

    /** @var callable(): string */
    private $uuid;

    /** @var array<string, string> attempt fingerprint => Idempotency-Key */
    private array $keys = [];

    /** @var array<string, int>|null */
    private ?array $limits = null;

    private readonly string $baseUrl;

    /**
     * @param  string  $baseUrl  the app root (https://nesc.example) or the full prefix (…/api/v1/exchange)
     * @param  string  $token  a field login / pairing token or an API-client token (Sanctum bearer)
     * @param  null|callable(array<string, mixed>): array{status: int, headers: array<string, string>, body: string}  $transport
     * @param  null|callable(float): void  $sleep  seconds
     * @param  null|callable(): float  $clock  unix seconds
     * @param  null|callable(): string  $uuid  v4 generator (tests pin it)
     */
    public function __construct(string $baseUrl, private readonly string $token, ?callable $transport = null, ?callable $sleep = null, ?callable $clock = null, ?callable $uuid = null)
    {
        $base = rtrim($baseUrl, '/');
        $this->baseUrl = str_ends_with($base, self::PREFIX) ? $base : $base.self::PREFIX;
        $this->transport = $transport ?? new CurlTransport;
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->uuid = $uuid ?? Uuid::v4(...);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    // ---- status ----------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function status(?string $program = null): array
    {
        $status = $this->json('GET', '/status', query: $program !== null ? ['program' => $program] : []);
        if (is_array($status['limits'] ?? null)) {
            $this->limits = array_map('intval', array_filter($status['limits'], 'is_numeric'));
        }

        return $status;
    }

    /** The server's size caps (MB): max_upload_mb, max_package_mb, chunk_mb, max_file_mb. Fetched once from GET /status. @return array<string, int> */
    public function limits(): array
    {
        if ($this->limits === null) {
            $this->status();
        }

        return $this->limits ?? [];
    }

    // ---- outbound --------------------------------------------------------------------------------

    /** @param array{include_blobs?: bool, streams?: list<string>, program?: string} $options @return array<string, mixed> */
    public function buildOutbound(array $options = []): array
    {
        return $this->json('POST', '/outbound', body: $options);
    }

    /** @return array<string, mixed> */
    public function outbound(string $packageId): array
    {
        return $this->json('GET', '/outbound/'.$this->id($packageId));
    }

    /** Poll until ready | receipted | failed. @return array<string, mixed> */
    public function waitOutbound(string $packageId, float $timeoutSeconds = 600, float $intervalSeconds = 5): array
    {
        return $this->poll(fn () => $this->outbound($packageId), ['ready', 'receipted', 'failed'], $timeoutSeconds, $intervalSeconds, 'outbound '.$packageId);
    }

    /** Download the signed zip to `$path`; returns its sha256 (checked against X-Package-Sha256 when sent). */
    public function download(string $packageId, string $path): string
    {
        $response = $this->send('GET', '/outbound/'.$this->id($packageId).'/download', sink: $path);
        $sha = hash_file('sha256', $path);
        $declared = strtolower((string) ($response['headers']['x-package-sha256'] ?? ''));
        if ($sha === false || ($declared !== '' && ! hash_equals($declared, $sha))) {
            @unlink($path);
            throw new RunnerClientException('hash_mismatch', 'Downloaded package does not match X-Package-Sha256', 200);
        }

        return $sha;
    }

    /**
     * One page of a stream as parsed rows, without the zip.
     *
     * @return array{rows: list<array<string, mixed>>, next_cursor: ?string, has_more: bool, schema: ?string}
     */
    public function streamRows(string $packageId, string $stream, ?string $cursor = null, int $limit = 1000): array
    {
        $this->stream($stream);
        $query = ['limit' => $limit] + ($cursor !== null ? ['cursor' => $cursor] : []);
        $response = $this->send('GET', '/outbound/'.$this->id($packageId).'/streams/'.$stream, query: $query);
        $rows = [];
        foreach (explode("\n", rtrim($response['body'], "\n")) as $line) {
            if ($line !== '') {
                $rows[] = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            }
        }

        return ['rows' => $rows, 'next_cursor' => $response['headers']['x-next-cursor'] ?? null, 'has_more' => filter_var($response['headers']['x-has-more'] ?? 'false', FILTER_VALIDATE_BOOLEAN), 'schema' => $response['headers']['x-stream-schema'] ?? null];
    }

    // ---- inbound ---------------------------------------------------------------------------------

    /**
     * Upload a package: single-shot when the file is within `max_upload_mb`, chunked otherwise
     * (chunk size = `chunk_mb`, overridable). Returns the 202/200 body {import_id, status}.
     *
     * @return array<string, mixed>
     */
    public function uploadInbound(string $path, bool $autoApply = true, ?int $chunkBytes = null): array
    {
        if (! is_file($path)) {
            throw new RunnerClientException('file_unreadable', 'Package file not found: '.$path, 0);
        }
        $size = (int) filesize($path);
        $limits = $this->limits();
        $maxUpload = (int) ($limits['max_upload_mb'] ?? 60) * self::MB;
        if ($size <= $maxUpload) {
            return $this->json('POST', '/inbound', multipart: ['package' => ['path' => $path, 'filename' => basename($path), 'mime' => 'application/zip'], 'auto_apply' => $autoApply ? '1' : '0'], fileSha: (string) hash_file('sha256', $path));
        }

        return $this->uploadChunked($path, $autoApply, $chunkBytes ?? (int) ($limits['chunk_mb'] ?? 8) * self::MB);
    }

    /**
     * The chunk lifecycle: the CLIENT generates the import uuid; the first POST /inbound/{uuid}/chunks
     * creates the `uploading` import owned by the caller; finalize checks the assembled sha256.
     *
     * @return array<string, mixed>
     */
    public function uploadChunked(string $path, bool $autoApply = true, int $chunkBytes = 8 * self::MB, ?string $importId = null): array
    {
        $chunkBytes = max(1, $chunkBytes);
        $size = (int) filesize($path);
        $total = max(1, (int) ceil($size / $chunkBytes));
        $limits = $this->limits();
        if (isset($limits['max_chunks']) && $total > $limits['max_chunks']) {
            throw new RunnerClientException('package_too_large', 'Package needs '.$total.' chunks; the server allows '.$limits['max_chunks'], 0);
        }
        $importId = $importId ?? ($this->uuid)();
        if (! Uuid::isV4($importId)) {
            throw new RunnerClientException('validation_failed', 'Import id must be a uuid v4', 0);
        }
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            throw new RunnerClientException('file_unreadable', 'Cannot read '.$path, 0);
        }
        $whole = hash_init('sha256');
        try {
            for ($index = 0; $index < $total; $index++) {
                $chunk = (string) fread($fp, $chunkBytes);
                hash_update($whole, $chunk);
                $checksum = hash('sha256', $chunk);
                $this->json('POST', '/inbound/'.$importId.'/chunks', multipart: [
                    'chunk' => ['contents' => $chunk, 'filename' => 'chunk-'.$index, 'mime' => 'application/octet-stream'],
                    'chunk_index' => (string) $index, 'total_chunks' => (string) $total, 'checksum' => $checksum,
                ], fileSha: $checksum);
            }
        } finally {
            fclose($fp);
        }

        return $this->json('POST', '/inbound/'.$importId.'/finalize', body: ['total_chunks' => $total, 'sha256' => hash_final($whole), 'auto_apply' => $autoApply]);
    }

    /** @return array<string, mixed> */
    public function inbound(string $importId): array
    {
        return $this->json('GET', '/inbound/'.$this->id($importId));
    }

    /** Poll until previewed | applied | failed | rejected. @return array<string, mixed> */
    public function waitInbound(string $importId, float $timeoutSeconds = 600, float $intervalSeconds = 5): array
    {
        return $this->poll(fn () => $this->inbound($importId), ['previewed', 'applied', 'failed', 'rejected'], $timeoutSeconds, $intervalSeconds, 'inbound '.$importId);
    }

    /** @return array<string, mixed> */
    public function apply(string $importId, bool $allowLargeDrop = false): array
    {
        return $this->json('POST', '/inbound/'.$this->id($importId).'/apply', body: ['allow_large_drop' => $allowLargeDrop]);
    }

    /** The receipt (verified manifests only; null while none exists). @return array<string, mixed>|null */
    public function receipt(string $importId): ?array
    {
        try {
            return $this->json('GET', '/inbound/'.$this->id($importId).'/receipt');
        } catch (RunnerClientException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
    }

    // ---- files -----------------------------------------------------------------------------------

    /** @return array{bytes: int, mime: ?string}|null null when the server does not hold the file */
    public function headFile(string $sha256): ?array
    {
        $this->sha($sha256);
        $response = $this->send('HEAD', '/files/'.$sha256, allow: [200, 404]);
        if ($response['status'] === 404) {
            return null;
        }

        return ['bytes' => (int) ($response['headers']['content-length'] ?? 0), 'mime' => $response['headers']['x-mime'] ?? null];
    }

    /** Download a file by hash to `$path` (the bytes are re-hashed). */
    public function getFile(string $sha256, string $path): void
    {
        $this->sha($sha256);
        $this->send('GET', '/files/'.$sha256, sink: $path);
        if (! hash_equals($sha256, (string) hash_file('sha256', $path))) {
            @unlink($path);
            throw new RunnerClientException('hash_mismatch', 'Downloaded file does not hash to '.$sha256, 200);
        }
    }

    /** PUT the raw bytes of `$path` under their sha256 (the server recomputes it). @return array<string, mixed> */
    public function putFile(string $sha256, string $path): array
    {
        $this->sha($sha256);
        $actual = (string) hash_file('sha256', $path);
        if (! hash_equals($sha256, $actual)) {
            throw new RunnerClientException('hash_mismatch', 'File does not hash to '.$sha256, 0);
        }

        return $this->json('PUT', '/files/'.$sha256, bodyPath: $path, fileSha: $actual, contentType: 'application/octet-stream');
    }

    // ---- rows / updates --------------------------------------------------------------------------

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    public function pushRows(string $stream, array $rows, bool $dryRun = false): array
    {
        $this->stream($stream);

        return $this->json('POST', '/rows/'.$stream, body: ['rows' => array_values($rows)], query: $dryRun ? ['dry_run' => '1'] : []);
    }

    /** @param list<array<string, mixed>> $ops @return array<string, mixed> */
    public function pushUpdates(array $ops, bool $dryRun = false, bool $create = false): array
    {
        return $this->json('POST', '/updates', body: ['ops' => array_values($ops), 'dry_run' => $dryRun, 'create' => $create]);
    }

    /** @return array<string, mixed> */
    public function pushUpdatesWorkbook(string $path, bool $dryRun = false, bool $create = false): array
    {
        if (! is_file($path)) {
            throw new RunnerClientException('file_unreadable', 'Workbook not found: '.$path, 0);
        }

        return $this->json('POST', '/updates', multipart: ['workbook' => ['path' => $path, 'filename' => basename($path), 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], 'dry_run' => $dryRun ? '1' : '0', 'create' => $create ? '1' : '0'], fileSha: (string) hash_file('sha256', $path));
    }

    /** @return array<string, mixed> */
    public function imports(?string $since = null, ?string $kind = null, int $limit = 100): array
    {
        return $this->json('GET', '/imports', query: array_filter(['since' => $since, 'kind' => $kind, 'limit' => $limit], static fn ($v) => $v !== null));
    }

    // ---- internals -------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $body  JSON body
     * @param  array<string, mixed>|null  $multipart
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, ?array $body = null, ?array $multipart = null, array $query = [], ?string $bodyPath = null, ?string $fileSha = null, ?string $contentType = null): array
    {
        $response = $this->send($method, $path, $body, $multipart, $query, $bodyPath, $fileSha, $contentType);
        $decoded = json_decode($response['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * One attempt: headers, Idempotency-Key, 429 retries, envelope errors.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>|null  $multipart
     * @param  list<int>  $allow  non-2xx statuses returned instead of thrown
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function send(string $method, string $path, ?array $body = null, ?array $multipart = null, array $query = [], ?string $bodyPath = null, ?string $fileSha = null, ?string $contentType = null, ?string $sink = null, array $allow = []): array
    {
        $url = $this->baseUrl.$path.($query !== [] ? '?'.http_build_query($query) : '');
        $headers = ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json', 'User-Agent' => 'texstar-exchange-runner/1.0'];
        $request = ['method' => $method, 'url' => $url, 'headers' => $headers];
        if ($multipart !== null) {
            $request['multipart'] = $multipart;
        } elseif ($bodyPath !== null) {
            $request['body_path'] = $bodyPath;
            $request['headers']['Content-Type'] = $contentType ?? 'application/octet-stream';
        } elseif ($body !== null) {
            $request['body'] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $request['headers']['Content-Type'] = 'application/json';
        }
        if ($sink !== null) {
            $request['sink'] = $sink;
            $request['headers']['Accept'] = '*/*';
        }
        if (in_array($method, ['POST', 'PUT'], true)) {
            $request['headers']['Idempotency-Key'] = $this->keyFor($method, $path, $query, $body, $multipart, $fileSha);
        }
        $attempt = 0;
        while (true) {
            $response = ($this->transport)($request);
            $status = (int) ($response['status'] ?? 0);
            $response['headers'] = array_change_key_case((array) ($response['headers'] ?? []), CASE_LOWER);
            $response['body'] = (string) ($response['body'] ?? '');
            if ($status === 429 && $attempt < self::MAX_RETRIES) {
                $attempt++;
                ($this->sleep)($this->retryAfter($response['headers']['retry-after'] ?? null));

                continue;
            }
            if ($status >= 200 && $status < 300 || in_array($status, $allow, true)) {
                return ['status' => $status, 'headers' => $response['headers'], 'body' => $response['body']];
            }
            throw RunnerClientException::fromResponse(['status' => $status, 'headers' => $response['headers'], 'body' => $response['body']]);
        }
    }

    /** One key per attempt: the same request (incl. the file's bytes) reuses it; anything else is a new attempt. */
    private function keyFor(string $method, string $path, array $query, ?array $body, ?array $multipart, ?string $fileSha): string
    {
        $fields = [];
        foreach ($multipart ?? [] as $field => $value) {
            $fields[$field] = is_array($value) ? '<file>' : (string) $value;
        }
        $fingerprint = hash('sha256', json_encode([$method, $path, $query, $body, $fields, $fileSha], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $this->keys[$fingerprint] ??= ($this->uuid)();
    }

    private function retryAfter(?string $header): float
    {
        if ($header === null || $header === '') {
            return 1.0;
        }
        if (is_numeric($header)) {
            return max(0.0, (float) $header);
        }
        $at = strtotime($header);

        return $at === false ? 1.0 : max(0.0, $at - ($this->clock)());
    }

    /** @param callable(): array<string, mixed> $fetch @param list<string> $terminal @return array<string, mixed> */
    private function poll(callable $fetch, array $terminal, float $timeoutSeconds, float $intervalSeconds, string $what): array
    {
        $deadline = ($this->clock)() + $timeoutSeconds;
        while (true) {
            $state = $fetch();
            if (in_array($state['status'] ?? null, $terminal, true)) {
                return $state;
            }
            if (($this->clock)() >= $deadline) {
                throw new RunnerClientException('timeout', 'Gave up waiting for '.$what.' (last status '.json_encode($state['status'] ?? null).')', 0);
            }
            ($this->sleep)($intervalSeconds);
        }
    }

    private function id(string $uuid): string
    {
        if (! preg_match('~^[0-9a-f-]{36}$~i', $uuid)) {
            throw new RunnerClientException('validation_failed', 'Not a uuid: '.$uuid, 0);
        }

        return strtolower($uuid);
    }

    private function sha(string $sha256): void
    {
        if (! preg_match('~^[a-f0-9]{64}$~', $sha256)) {
            throw new RunnerClientException('validation_failed', 'Not a sha256: '.$sha256, 0);
        }
    }

    private function stream(string $stream): void
    {
        if (! preg_match('~^[a-z0-9_]{1,40}$~', $stream)) {
            throw new RunnerClientException('validation_failed', 'Bad stream name', 0);
        }
    }
}
