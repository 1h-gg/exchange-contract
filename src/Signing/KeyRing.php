<?php

declare(strict_types=1);

namespace Texstar\Exchange\Signing;

/**
 * key_id -> shared secret. Fails closed: a blank key id or secret is refused at construction, so an
 * unset environment yields an EMPTY ring (every signature check fails, every signing attempt throws).
 *
 * Env form: `texstar:comcast=<secret>,other:pair=<secret>` — pairs split on ',' and each pair on the
 * FIRST '=' only, so base64 secrets keep their padding. A ',' cannot appear in a secret.
 */
final class KeyRing
{
    /** @var array<string, string> */
    private array $keys = [];

    /** @param array<string, string> $keys */
    public function __construct(array $keys = [])
    {
        foreach ($keys as $keyId => $secret) {
            $this->add((string) $keyId, (string) $secret);
        }
    }

    /** @param array<string, string> $keys */
    public static function fromArray(array $keys): self
    {
        return new self($keys);
    }

    public static function fromEnvString(string $env): self
    {
        return new self(self::parseEnvString($env));
    }

    /**
     * Parse the env form into a plain `[key_id => secret]` array. Blank pairs are dropped; a pair
     * with a blank key id or secret throws. Safe to call from a config file (no object, cacheable).
     *
     * @return array<string, string>
     */
    public static function parseEnvString(string $env): array
    {
        $out = [];
        foreach (explode(',', $env) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            if ($eq === false) {
                throw new KeyRingException('Key ring entry without "=": expected key_id=secret');
            }
            $keyId = trim(substr($pair, 0, $eq));
            $secret = substr($pair, $eq + 1);
            if ($keyId === '' || $secret === '') {
                throw new KeyRingException('Key ring entry with a blank key id or secret');
            }
            $out[$keyId] = $secret;
        }

        return $out;
    }

    public function add(string $keyId, string $secret): self
    {
        if (trim($keyId) === '') {
            throw new KeyRingException('Key id must not be blank');
        }
        if ($secret === '' || trim($secret) === '') {
            throw new KeyRingException('Secret for key id "'.$keyId.'" must not be blank');
        }
        $this->keys[$keyId] = $secret;

        return $this;
    }

    public function has(string $keyId): bool
    {
        return isset($this->keys[$keyId]);
    }

    public function secret(string $keyId): string
    {
        return $this->keys[$keyId] ?? throw new UnknownKeyException($keyId);
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    /** @return list<string> */
    public function keyIds(): array
    {
        return array_keys($this->keys);
    }
}
