<?php

declare(strict_types=1);

namespace Texstar\Exchange\Tests;

use PHPUnit\Framework\TestCase;
use Texstar\Exchange\Signing\KeyRing;
use Texstar\Exchange\Signing\KeyRingException;
use Texstar\Exchange\Signing\UnknownKeyException;

final class KeyRingTest extends TestCase
{
    public function test_blank_secret_throws(): void
    {
        $this->expectException(KeyRingException::class);
        new KeyRing(['texstar:comcast' => '']);
    }

    public function test_whitespace_secret_throws(): void
    {
        $this->expectException(KeyRingException::class);
        (new KeyRing)->add('texstar:comcast', '   ');
    }

    public function test_blank_key_id_throws(): void
    {
        $this->expectException(KeyRingException::class);
        new KeyRing(['' => 'secret']);
    }

    public function test_empty_env_yields_an_empty_ring(): void
    {
        $ring = KeyRing::fromEnvString('');
        $this->assertTrue($ring->isEmpty());
        $this->assertSame([], $ring->keyIds());
        $this->assertSame([], KeyRing::parseEnvString('  , ,'));
    }

    public function test_env_pairs_split_on_comma_then_first_equals_only(): void
    {
        $parsed = KeyRing::parseEnvString('a:b=x==,c:d=y');
        $this->assertSame(['a:b' => 'x==', 'c:d' => 'y'], $parsed);

        $ring = KeyRing::fromEnvString('a:b=x==,c:d=y');
        $this->assertSame('x==', $ring->secret('a:b'));
        $this->assertSame('y', $ring->secret('c:d'));
        $this->assertTrue($ring->has('a:b'));
        $this->assertFalse($ring->has('a'));
    }

    public function test_secret_with_equals_keeps_everything_after_the_first(): void
    {
        $this->assertSame(['k' => 'a=b=c'], KeyRing::parseEnvString('k=a=b=c'));
    }

    public function test_pair_without_equals_throws(): void
    {
        $this->expectException(KeyRingException::class);
        KeyRing::parseEnvString('texstar:comcast');
    }

    public function test_blank_secret_in_env_throws(): void
    {
        $this->expectException(KeyRingException::class);
        KeyRing::parseEnvString('texstar:comcast=');
    }

    public function test_unknown_key_id_throws(): void
    {
        $this->expectException(UnknownKeyException::class);
        KeyRing::fromArray(['a' => 'b'])->secret('zzz');
    }
}
