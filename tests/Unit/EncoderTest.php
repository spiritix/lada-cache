<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Encoder;
use stdClass;

class EncoderTest extends TestCase
{
    public function testRowsRoundTrip(): void
    {
        $encoder = new Encoder;
        $row = new stdClass;
        $row->id = 1;
        $row->name = 'car';
        $row->price = null;
        $rows = [$row, ['id' => 2, 'name' => 'array row'], []];

        $this->assertEquals($rows, $encoder->decode($encoder->encode($rows)));
        $this->assertSame([], $encoder->decode($encoder->encode([])));
    }

    public function testDecodeRejectsPayloadsThatAreNotResults(): void
    {
        $encoder = new Encoder;

        $this->assertNull($encoder->decode('not-serialized'));
        $this->assertNull($encoder->decode('"json string"'));
        $this->assertNull($encoder->decode('a:1:{i:0;'));
        $this->assertNull($encoder->decode(serialize('a:string')));
    }

    public function testDecodeNeverInstantiatesClassesOtherThanStdClass(): void
    {
        $payload = serialize([new EncoderGadget]);

        $rows = (new Encoder)->decode($payload);

        $this->assertIsArray($rows);
        $this->assertNotInstanceOf(EncoderGadget::class, $rows[0]);
        $this->assertFalse(EncoderGadget::$woken);
    }
}

final class EncoderGadget
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}
