<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Encoder;
use stdClass;

class EncoderTest extends TestCase
{
    protected function setUp(): void
    {
        EncoderTestGadget::$woken = false;
    }

    public function testEncodeDecodeRoundTripsRows(): void
    {
        $encoder = new Encoder();

        $row = new stdClass();
        $row->id = 1;
        $row->name = 'car';
        $row->price = 12.34;
        $row->deleted_at = null;

        $cases = [
            [],
            [$row],
            [['id' => 1, 'name' => 'car'], ['id' => 2, 'name' => null]],
        ];

        foreach ($cases as $original) {
            $this->assertEquals($original, $encoder->decode($encoder->encode($original)));
        }
    }

    public function testDecodeDoesNotInstantiateClassesOtherThanStdClass(): void
    {
        $encoder = new Encoder();

        $decoded = $encoder->decode(serialize([new EncoderTestGadget()]));

        $this->assertFalse(EncoderTestGadget::$woken);
        $this->assertIsArray($decoded);
        $this->assertNotInstanceOf(EncoderTestGadget::class, $decoded[0]);
    }

    public function testDecodeReturnsNullForPayloadsThatAreNotResults(): void
    {
        $encoder = new Encoder();

        $this->assertNull($encoder->decode('this-is-not-json-and-not-serialized'));
        $this->assertNull($encoder->decode(serialize(new EncoderTestGadget())));
        $this->assertNull($encoder->decode(serialize('string')));
        $this->assertNull($encoder->decode('{"id":1}'));
        $this->assertFalse(EncoderTestGadget::$woken);
    }
}

final class EncoderTestGadget
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}
