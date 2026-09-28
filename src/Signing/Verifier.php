<?php

declare(strict_types=1);

namespace Texstar\Exchange\Signing;

use Texstar\Exchange\Canonical\CanonicalJson;
use Texstar\Exchange\Canonical\CanonicalJsonException;
use Texstar\Exchange\Contract;

/**
 * Fail-closed manifest verification: a missing or null signature, an unknown key id, an empty ring
 * or any mismatch throws {@see SignatureException}. Comparison uses hash_equals.
 */
final class Verifier
{
    /** @param array<string, mixed> $manifest */
    public function verify(array $manifest, KeyRing $ring): void
    {
        $signature = $manifest['signature'] ?? null;
        if (! is_array($signature) || $signature === []) {
            throw new SignatureException(SignatureException::MISSING, 'Manifest carries no signature');
        }
        $alg = $signature['alg'] ?? null;
        $keyId = $signature['key_id'] ?? null;
        $value = $signature['value'] ?? null;
        if ($alg !== Contract::SIGNATURE_ALG || ! is_string($keyId) || $keyId === '' || ! is_string($value)) {
            throw new SignatureException(SignatureException::MISSING, 'Manifest signature is incomplete');
        }
        if ($ring->isEmpty() || ! $ring->has($keyId)) {
            throw new SignatureException(SignatureException::UNKNOWN_KEY, 'Manifest signed with an unknown key id');
        }
        if (! preg_match('~^[a-f0-9]{64}$~', $value)) {
            throw new SignatureException(SignatureException::INVALID, 'Manifest signature is not a hex HMAC-SHA256');
        }
        unset($manifest['signature']);
        try {
            $expected = hash_hmac('sha256', CanonicalJson::encode($manifest, strict: true), $ring->secret($keyId));
        } catch (CanonicalJsonException $e) {
            throw new SignatureException(SignatureException::INVALID, 'Manifest cannot be canonicalised: '.$e->getMessage());
        }
        if (! hash_equals($expected, $value)) {
            throw new SignatureException(SignatureException::INVALID, 'Manifest signature does not match');
        }
    }

    /** @param array<string, mixed> $manifest */
    public function isValid(array $manifest, KeyRing $ring): bool
    {
        try {
            $this->verify($manifest, $ring);

            return true;
        } catch (SignatureException) {
            return false;
        }
    }
}
