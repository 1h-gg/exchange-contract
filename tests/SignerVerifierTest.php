<?php

declare(strict_types=1);

namespace Texstar\Exchange\Tests;

use PHPUnit\Framework\TestCase;
use Texstar\Exchange\Canonical\CanonicalJsonException;
use Texstar\Exchange\Signing\KeyRing;
use Texstar\Exchange\Signing\SignatureException;
use Texstar\Exchange\Signing\Signer;
use Texstar\Exchange\Signing\UnknownKeyException;
use Texstar\Exchange\Signing\Verifier;

final class SignerVerifierTest extends TestCase
{
    private function manifest(): array
    {
        return ['format' => 'texstar-exchange', 'version' => 1, 'package_id' => 'p1', 'files' => [['path' => 'streams/jobs.ndjson', 'rows' => 3]], 'extras' => []];
    }

    private function ring(): KeyRing
    {
        return KeyRing::fromEnvString('texstar:comcast=secret-one,other:pair=secret-two');
    }

    public function test_round_trip(): void
    {
        $signed = (new Signer)->signManifest($this->manifest(), $this->ring(), 'texstar:comcast');

        $this->assertSame('HMAC-SHA256', $signed['signature']['alg']);
        $this->assertSame('texstar:comcast', $signed['signature']['key_id']);
        $this->assertMatchesRegularExpression('~^[a-f0-9]{64}$~', $signed['signature']['value']);
        (new Verifier)->verify($signed, $this->ring());
        $this->assertTrue((new Verifier)->isValid($signed, $this->ring()));
    }

    public function test_signature_does_not_depend_on_key_order_or_the_signature_member(): void
    {
        $a = (new Signer)->sign(['b' => 1, 'a' => ['y' => 2, 'x' => 1]], $this->ring(), 'texstar:comcast');
        $b = (new Signer)->sign(['a' => ['x' => 1, 'y' => 2], 'b' => 1, 'signature' => ['stale' => true]], $this->ring(), 'texstar:comcast');

        $this->assertSame($a, $b);
    }

    public function test_wrong_key_is_refused(): void
    {
        $signed = (new Signer)->signManifest($this->manifest(), $this->ring(), 'texstar:comcast');
        $other = KeyRing::fromArray(['texstar:comcast' => 'a-different-secret']);

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('does not match');
        try {
            (new Verifier)->verify($signed, $other);
        } catch (SignatureException $e) {
            $this->assertSame(SignatureException::INVALID, $e->wireCode());
            throw $e;
        }
    }

    public function test_re_signed_with_another_key_id_is_unknown_to_the_receiver(): void
    {
        $signed = (new Signer)->signManifest($this->manifest(), $this->ring(), 'other:pair');
        $receiver = KeyRing::fromArray(['texstar:comcast' => 'secret-one']);

        try {
            (new Verifier)->verify($signed, $receiver);
            $this->fail('expected unknown_key_id');
        } catch (SignatureException $e) {
            $this->assertSame(SignatureException::UNKNOWN_KEY, $e->wireCode());
        }
    }

    public function test_empty_ring_always_fails(): void
    {
        $signed = (new Signer)->signManifest($this->manifest(), $this->ring(), 'texstar:comcast');

        try {
            (new Verifier)->verify($signed, new KeyRing);
            $this->fail('expected an exception');
        } catch (SignatureException $e) {
            $this->assertSame(SignatureException::UNKNOWN_KEY, $e->wireCode());
        }
        $this->assertFalse((new Verifier)->isValid($signed, KeyRing::fromEnvString('')));
    }

    public function test_tampered_manifest_is_refused(): void
    {
        $signed = (new Signer)->signManifest($this->manifest(), $this->ring(), 'texstar:comcast');
        $signed['files'][0]['rows'] = 4;

        try {
            (new Verifier)->verify($signed, $this->ring());
            $this->fail('expected signature_invalid');
        } catch (SignatureException $e) {
            $this->assertSame(SignatureException::INVALID, $e->wireCode());
        }
    }

    public function test_missing_and_null_signature_are_signature_missing(): void
    {
        foreach ([$this->manifest(), $this->manifest() + ['signature' => null], $this->manifest() + ['signature' => []], $this->manifest() + ['signature' => ['alg' => 'HMAC-SHA256', 'key_id' => '', 'value' => 'x']]] as $manifest) {
            try {
                (new Verifier)->verify($manifest, $this->ring());
                $this->fail('expected signature_missing');
            } catch (SignatureException $e) {
                $this->assertSame(SignatureException::MISSING, $e->wireCode());
            }
        }
    }

    public function test_wrong_algorithm_is_signature_missing(): void
    {
        $signed = (new Signer)->signManifest($this->manifest(), $this->ring(), 'texstar:comcast');
        $signed['signature']['alg'] = 'HMAC-SHA1';

        $this->expectException(SignatureException::class);
        (new Verifier)->verify($signed, $this->ring());
    }

    public function test_non_hex_value_is_signature_invalid(): void
    {
        $signed = (new Signer)->signManifest($this->manifest(), $this->ring(), 'texstar:comcast');
        $signed['signature']['value'] = 'not-hex';

        try {
            (new Verifier)->verify($signed, $this->ring());
            $this->fail('expected signature_invalid');
        } catch (SignatureException $e) {
            $this->assertSame(SignatureException::INVALID, $e->wireCode());
        }
    }

    public function test_signer_refuses_without_the_key(): void
    {
        $this->expectException(UnknownKeyException::class);
        (new Signer)->sign($this->manifest(), KeyRing::fromEnvString(''), 'texstar:comcast');
    }

    public function test_signer_refuses_a_manifest_with_floats(): void
    {
        $this->expectException(CanonicalJsonException::class);
        (new Signer)->sign(['extras' => ['ratio' => 0.5]], $this->ring(), 'texstar:comcast');
    }
}
