<?php

declare(strict_types=1);

namespace Texstar\Exchange\Package;

use Texstar\Exchange\Contract;
use Texstar\Exchange\Stream\StreamCatalogue;

/**
 * Version rules: the manifest `version` must equal the reader's MAJOR (else unsupported_version);
 * per-stream `schema` is "<name>@<major>" — an unknown stream or a higher stream major is SKIPPED with
 * a warning, never rejected; unknown fields are ignored.
 */
final class Negotiation
{
    private function __construct() {}

    public static function accepts(int $manifestVersion, int $ownMajor = Contract::MAJOR): bool
    {
        return $manifestVersion === $ownMajor;
    }

    /** @return array{0: string, 1: int}|null [name, major] or null when malformed */
    public static function parseSchema(string $schema): ?array
    {
        if (! preg_match('~^([a-z0-9_]{1,40})@(\d{1,3})$~', $schema, $m)) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }

    /** @param array<string, mixed> $manifest */
    public static function evaluate(array $manifest, int $ownMajor = Contract::MAJOR, int $ownStreamMajor = StreamCatalogue::SCHEMA_MAJOR): NegotiationResult
    {
        $version = $manifest['version'] ?? null;
        if (! is_int($version) || ! self::accepts($version, $ownMajor)) {
            return new NegotiationResult(false, 'unsupported_version', 'Manifest version '.json_encode($version).' is not supported (reader major '.$ownMajor.')', [], []);
        }
        if (($manifest['format'] ?? null) !== Contract::FORMAT) {
            return new NegotiationResult(false, 'unsupported_version', 'Manifest format is not '.Contract::FORMAT, [], []);
        }
        $streams = [];
        $skipped = [];
        foreach (is_array($manifest['files'] ?? null) ? $manifest['files'] : [] as $file) {
            if (! is_array($file)) {
                continue;
            }
            $stream = (string) ($file['stream'] ?? '');
            $schema = (string) ($file['schema'] ?? '');
            $parsed = self::parseSchema($schema);
            if ($parsed === null) {
                $skipped[] = ['stream' => $stream, 'schema' => $schema, 'reason' => 'malformed schema id'];

                continue;
            }
            [$name, $major] = $parsed;
            if ($name !== $stream) {
                $skipped[] = ['stream' => $stream, 'schema' => $schema, 'reason' => 'schema id names another stream'];

                continue;
            }
            if (! StreamCatalogue::isPackageStream($stream)) {
                $skipped[] = ['stream' => $stream, 'schema' => $schema, 'reason' => 'unknown stream'];

                continue;
            }
            if ($major > $ownStreamMajor) {
                $skipped[] = ['stream' => $stream, 'schema' => $schema, 'reason' => 'stream major '.$major.' is newer than '.$ownStreamMajor];

                continue;
            }
            $streams[] = $stream;
        }

        return new NegotiationResult(true, null, null, $streams, $skipped);
    }
}
