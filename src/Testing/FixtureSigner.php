<?php

declare(strict_types=1);

namespace Texstar\Exchange\Testing;

use Texstar\Exchange\Contract;
use Texstar\Exchange\Signing\KeyRing;
use Texstar\Exchange\Signing\Signer;

/**
 * `bin/exchange fixtures:sign`: rewrites each fixture tree's manifest.json from its expectations.json
 * `build` block — {recompute: bool, sign: "valid" | "wrong-secret" | "none"}. recompute refreshes
 * files[].rows/bytes/sha256 and blobs[].bytes from the tree (off for the tampered fixtures, whose
 * manifests must keep describing the ORIGINAL bytes); sign writes the HMAC with the golden key, a
 * wrong secret, or `signature: null`. The output is committed: readers never need this class.
 */
final class FixtureSigner
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT;

    private function __construct() {}

    /** @return array<string, string> fixture => "signed"|"unsigned"|"wrong-secret" */
    public static function signAll(?string $only = null): array
    {
        $out = [];
        foreach (ContractSuite::names() as $name) {
            if ($only !== null && $only !== $name) {
                continue;
            }
            $out[$name] = self::sign($name);
        }

        return $out;
    }

    public static function sign(string $name): string
    {
        $dir = ContractSuite::dir($name);
        $expected = ContractSuite::expected($name);
        $build = is_array($expected['build'] ?? null) ? $expected['build'] : [];
        $manifestPath = $dir.'/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new \RuntimeException('manifest.json of '.$name.' is not an object');
        }
        if ($build['recompute'] ?? true) {
            $manifest = self::recompute($manifest, $dir);
        }
        $mode = (string) ($build['sign'] ?? 'valid');
        $keyId = (string) ($manifest['signature']['key_id'] ?? $expected['key_id'] ?? 'texstar:comcast');
        unset($manifest['signature']);
        $manifest['signature'] = match ($mode) {
            'none' => null,
            'valid' => (new Signer)->signManifest($manifest, ContractSuite::keyRing(), $keyId)['signature'],
            'wrong-secret' => (new Signer)->signManifest($manifest, KeyRing::fromArray([$keyId => 'not-the-golden-key']), $keyId)['signature'],
            default => throw new \RuntimeException('Unknown build.sign mode '.$mode.' in '.$name),
        };
        file_put_contents($manifestPath, json_encode($manifest, self::FLAGS | JSON_THROW_ON_ERROR)."\n");

        return match ($mode) {
            'none' => 'unsigned',
            'valid' => 'signed',
            default => $mode,
        };
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    private static function recompute(array $manifest, string $dir): array
    {
        foreach (is_array($manifest['files'] ?? null) ? $manifest['files'] : [] as $i => $file) {
            $stream = (string) ($file['stream'] ?? '');
            $path = $dir.'/streams/'.$stream.'.ndjson';
            if (! preg_match(Contract::STREAM_NAME_PATTERN, $stream) || ! is_file($path)) {
                continue;
            }
            $bytes = (string) file_get_contents($path);
            $manifest['files'][$i]['rows'] = $bytes === '' ? 0 : substr_count(rtrim($bytes, "\n"), "\n") + 1;
            $manifest['files'][$i]['sha256'] = hash('sha256', $bytes);
            $manifest['files'][$i]['bytes'] = strlen($bytes);
        }
        foreach (is_array($manifest['blobs'] ?? null) ? $manifest['blobs'] : [] as $i => $blob) {
            $sha = (string) ($blob['sha256'] ?? '');
            $path = $dir.'/files/'.$sha;
            if (! preg_match(Contract::SHA256_PATTERN, $sha) || ! is_file($path)) {
                continue;
            }
            $manifest['blobs'][$i]['bytes'] = filesize($path);
            $manifest['blobs'][$i]['path'] = Contract::blobEntry($sha);
        }

        return $manifest;
    }
}
