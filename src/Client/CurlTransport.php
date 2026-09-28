<?php

declare(strict_types=1);

namespace Texstar\Exchange\Client;

/**
 * The default RunnerClient transport (ext-curl). A request is
 * {method, url, headers: {name: value}, body?: string, body_path?: file streamed as the raw body,
 *  multipart?: {field: string | {path, filename?} | {contents, filename}}, sink?: path the response body is written to}
 * and the answer is {status, headers: {lower-case name: value}, body}.
 */
final class CurlTransport
{
    public function __construct(private readonly int $timeoutSeconds = 600, private readonly int $connectTimeoutSeconds = 30) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function __invoke(array $request): array
    {
        if (! function_exists('curl_init')) {
            throw new RunnerClientException('curl_missing', 'ext-curl is required by the runner client', 0);
        }
        $ch = curl_init((string) $request['url']);
        $headers = [];
        foreach ((array) ($request['headers'] ?? []) as $name => $value) {
            $headers[] = $name.': '.$value;
        }
        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }

                return strlen($line);
            },
        ];
        $responseHeaders = [];
        $bodyFile = null;
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        if (isset($request['multipart'])) {
            $fields = [];
            foreach ((array) $request['multipart'] as $field => $value) {
                if (is_array($value) && isset($value['path'])) {
                    $fields[$field] = new \CURLFile((string) $value['path'], (string) ($value['mime'] ?? 'application/octet-stream'), (string) ($value['filename'] ?? basename((string) $value['path'])));
                } elseif (is_array($value) && array_key_exists('contents', $value)) {
                    $fields[$field] = new \CURLStringFile((string) $value['contents'], (string) ($value['filename'] ?? $field), (string) ($value['mime'] ?? 'application/octet-stream'));
                } else {
                    $fields[$field] = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
                }
            }
            $options[CURLOPT_POSTFIELDS] = $fields;
        } elseif (isset($request['body_path'])) {
            $bodyFile = fopen((string) $request['body_path'], 'rb');
            if ($bodyFile === false) {
                throw new RunnerClientException('file_unreadable', 'Cannot read '.$request['body_path'], 0);
            }
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $bodyFile;
            $options[CURLOPT_INFILESIZE] = filesize((string) $request['body_path']);
        } elseif (isset($request['body'])) {
            $options[CURLOPT_POSTFIELDS] = (string) $request['body'];
        }
        $sink = null;
        if (isset($request['sink'])) {
            $sink = fopen((string) $request['sink'], 'wb');
            if ($sink === false) {
                throw new RunnerClientException('file_unwritable', 'Cannot write '.$request['sink'], 0);
            }
            $options[CURLOPT_FILE] = $sink;
            unset($options[CURLOPT_RETURNTRANSFER]);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($bodyFile !== null) {
            fclose($bodyFile);
        }
        if ($sink !== null) {
            fclose($sink);
        }
        if ($errno !== 0) {
            throw new RunnerClientException('transport_error', 'curl: '.$error, 0);
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => is_string($body) ? $body : ''];
    }
}
