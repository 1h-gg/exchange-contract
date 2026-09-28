<?php

declare(strict_types=1);

namespace Texstar\Exchange\Tests;

use PHPUnit\Framework\TestCase;
use Texstar\Exchange\Canonical\CanonicalJson;
use Texstar\Exchange\Canonical\CanonicalJsonException;

final class CanonicalJsonTest extends TestCase
{
    public function test_keys_sort_recursively_and_lists_keep_order(): void
    {
        $out = CanonicalJson::encode(['b' => ['z' => 1, 'a' => [3, 1, 2]], 'a' => 'x', '10' => 'n', '9' => 'm']);

        $this->assertSame('{"10":"n","9":"m","a":"x","b":{"a":[3,1,2],"z":1}}', $out);
    }

    public function test_floats_round_trip_with_shortest_representation_by_default(): void
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '5');
        try {
            $out = CanonicalJson::encode(['q' => 0.1, 'one' => 1.0, 'lat' => 45.523062, 'lng' => -122.676482]);
            $this->assertSame('{"lat":45.523062,"lng":-122.676482,"one":1.0,"q":0.1}', $out);
            $this->assertSame('5', ini_get('serialize_precision'), 'the ini pin is restored after the call');
        } finally {
            ini_set('serialize_precision', $previous === false ? '-1' : $previous);
        }
    }

    public function test_strict_mode_refuses_floats(): void
    {
        $this->expectException(CanonicalJsonException::class);
        $this->expectExceptionMessage('$.rows[0].quantity');

        CanonicalJson::encode(['rows' => [['quantity' => 1.5]]], strict: true);
    }

    public function test_strict_mode_accepts_integers_and_strings(): void
    {
        $this->assertSame('{"a":1,"b":"1.5","c":null,"d":true}', CanonicalJson::encode(['d' => true, 'c' => null, 'b' => '1.5', 'a' => 1], strict: true));
    }

    public function test_unicode_and_slashes_are_not_escaped(): void
    {
        $this->assertSame('{"u":"/a/b é"}', CanonicalJson::encode(['u' => '/a/b é']));
    }

    public function test_empty_object_and_empty_array_collapse_to_the_same_bytes(): void
    {
        $this->assertSame('{"x":[]}', CanonicalJson::encode(['x' => new \stdClass]));
        $this->assertSame('{"x":[]}', CanonicalJson::encode(['x' => []]));
        $this->assertSame(CanonicalJson::encode(json_decode('{"extras":{},"a":1}', true)), CanonicalJson::encode(['a' => 1, 'extras' => new \stdClass]));
    }

    public function test_hash_is_sha256_of_the_encoding(): void
    {
        $this->assertSame(hash('sha256', '{"a":1}'), CanonicalJson::hash(['a' => 1]));
    }

    public function test_non_finite_floats_are_refused(): void
    {
        $this->expectException(CanonicalJsonException::class);
        CanonicalJson::encode(['x' => INF]);
    }
}
