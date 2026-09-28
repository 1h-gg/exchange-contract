<?php

declare(strict_types=1);

namespace Texstar\Exchange\Stream;

use Texstar\Exchange\ExchangeException;

/**
 * The stable identity of a row within its stream: composite fields joined with '|', `<kind>|<id>` for
 * kind-discriminated streams, a constant for single-row streams. Receipts and verdicts address rows by
 * (key, seq), never by batch index.
 */
final class RowKey
{
    private function __construct() {}

    /** @param array<string, mixed> $row */
    public static function for(string $stream, array $row): string
    {
        $spec = StreamCatalogue::keySpec($stream);
        if (isset($spec['const'])) {
            return (string) $spec['const'];
        }
        if (isset($spec['kind'])) {
            $kind = self::scalar($row['kind'] ?? null);
            if ($kind === '' || ! isset($spec['kind'][$kind])) {
                throw new ExchangeException('Row of stream '.$stream.' has no known kind', 'row_key');
            }
            $id = self::scalar($row[$spec['kind'][$kind]] ?? null);
            if ($id === '') {
                throw new ExchangeException('Row of stream '.$stream.' kind '.$kind.' lacks '.$spec['kind'][$kind], 'row_key');
            }

            return $kind.'|'.$id;
        }
        $parts = [];
        foreach ($spec as $field) {
            $v = self::scalar($row[$field] ?? null);
            if ($v === '') {
                throw new ExchangeException('Row of stream '.$stream.' lacks key field '.$field, 'row_key');
            }
            $parts[] = $v;
        }

        return implode('|', $parts);
    }

    /** Null when the row cannot be keyed (a reject that still needs a seq). */
    public static function tryFor(string $stream, array $row): ?string
    {
        try {
            return self::for($stream, $row);
        } catch (ExchangeException) {
            return null;
        }
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    private static function scalar(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
