<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Encoder;

class EncoderTest extends TestCase
{
    private $encoder;

    public function setUp(): void
    {
        parent::setUp();

        $this->encoder = new Encoder();
    }

    public function testEncode()
    {
        $this->assertIsString($this->encoder->encode(['array']));
        $this->assertIsString($this->encoder->encode(5));
        $this->assertIsString($this->encoder->encode('string'));
    }

    public function testDecode()
    {
        $this->assertIsArray($this->encoder->decode($this->encoder->encode(['array'])));
        $this->assertIsInt($this->encoder->decode($this->encoder->encode(5)));
        $this->assertIsString($this->encoder->decode($this->encoder->encode('string')));
    }
}
