<?php

declare(strict_types=1);

namespace Texstar\Exchange;

/** UUID v4 helpers (package ids, import ids, Idempotency-Keys). */
final class Uuid
{
    private const PATTERN = '~^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$~';

    private function __construct() {}

    public static function v4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function isV4(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
