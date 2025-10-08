<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Encoder;

class EncoderTest extends TestCase
{
    public function testEncodeDecodeScalarsArraysAndNull(): void
    {
        $encoder = new Encoder();

        $cases = [
            null,
            0,
            123,
            12.34,
            true,
            false,
            'string',
            ['a' => 1, 'b' => [2, 3]],
        ];

        foreach ($cases as $original) {
            $encoded = $encoder->encode($original);
            $decoded = $encoder->decode($encoded);
            $this->assertEquals($original, $decoded);
        }
    }

    public function testEncodeFallsBackToSerializeForObjectsAndRoundTrips(): void
    {
        $encoder = new Encoder();

        $obj = new class() {
            public int $x = 5;
            public string $y = 'test';
        };

        $encoded = $encoder->encode($obj);
        $this->assertIsString($encoded);

        $decoded = $encoder->decode($encoded);
        $this->assertIsObject($decoded);
        $this->assertSame(5, $decoded->x);
        $this->assertSame('test', $decoded->y);
    }

    public function testDecodeReturnsNullOnCompletelyInvalidPayload(): void
    {
        $encoder = new Encoder();

        $invalid = "this-is-not-json-and-not-serialized";
        $decoded = $encoder->decode($invalid);

        $this->assertNull($decoded);
    }
}

