<?php

declare(strict_types=1);

namespace Texstar\Exchange\Signing;

use Texstar\Exchange\Canonical\CanonicalJson;
use Texstar\Exchange\Contract;

/** HMAC-SHA256 hex over the strict canonical JSON of the manifest without its `signature` member. */
final class Signer
{
    /** @param array<string, mixed> $manifest */
    public function sign(array $manifest, KeyRing $ring, string $keyId): string
    {
        $secret = $ring->secret($keyId); // throws UnknownKeyException; never signs with ''
        unset($manifest['signature']);

        return hash_hmac('sha256', CanonicalJson::encode($manifest, strict: true), $secret);
    }

    /**
     * The manifest with its `signature` block filled in.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function signManifest(array $manifest, KeyRing $ring, string $keyId): array
    {
        $value = $this->sign($manifest, $ring, $keyId);
        $manifest['signature'] = ['alg' => Contract::SIGNATURE_ALG, 'key_id' => $keyId, 'value' => $value];

        return $manifest;
    }
}
