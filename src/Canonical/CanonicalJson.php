<?php

declare(strict_types=1);

namespace Texstar\Exchange\Canonical;

/**
 * Deterministic JSON: objects with keys sorted bytewise (lists keep their order), no whitespace,
 * unescaped slashes and unicode, zero fractions preserved, and `serialize_precision = -1`
 * pinned for the call so a float round-trips to the shortest exact representation on every host.
 *
 * Floats are allowed by default (row fingerprints carry sow_claims[].quantity and lat/lng).
 * `$strict` (the manifest signing input) refuses them, because a signature must not depend on
 * float formatting at all.
 *
 * Empty containers: an empty object and an empty array both canonicalise to `[]`, because the array
 * form of json_decode() cannot tell them apart and both apps must sign/verify the same bytes from
 * either form. (The wire manifest still pretty-prints `{}` for empty objects; only the signing input
 * collapses them.)
 */
final class CanonicalJson
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private function __construct() {}

    /** @param array<mixed> $data */
    public static function encode(array $data, bool $strict = false): string
    {
        $normalized = self::normalize($data, $strict, '$');
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            return json_encode($normalized, self::FLAGS);
        } catch (\JsonException $e) {
            throw new CanonicalJsonException('Cannot encode: '.$e->getMessage());
        } finally {
            ini_set('serialize_precision', $previous === false ? '-1' : $previous);
        }
    }

    /** sha256 hex of the canonical encoding. */
    public static function hash(array $data, bool $strict = false): string
    {
        return hash('sha256', self::encode($data, $strict));
    }

    private static function normalize(mixed $value, bool $strict, string $path): mixed
    {
        if (is_float($value)) {
            if ($strict) {
                throw new CanonicalJsonException("Float at {$path} is not allowed in strict canonical JSON");
            }
            if (! is_finite($value)) {
                throw new CanonicalJsonException("Non-finite float at {$path}");
            }

            return $value;
        }
        if (is_object($value)) {
            if ($value instanceof \JsonSerializable) {
                return self::normalize($value->jsonSerialize(), $strict, $path);
            }
            if ($value instanceof \stdClass) {
                $value = (array) $value; // an empty object becomes [] on purpose (see the class docblock)
            } else {
                throw new CanonicalJsonException('Unsupported object at '.$path.': '.$value::class);
            }
        }
        if (! is_array($value)) {
            return $value;
        }
        if ($value !== [] && array_is_list($value)) {
            $out = [];
            foreach ($value as $i => $item) {
                $out[] = self::normalize($item, $strict, $path.'['.$i.']');
            }

            return $out;
        }
        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::normalize($item, $strict, $path.'.'.$key);
        }

        return $out;
    }
}
