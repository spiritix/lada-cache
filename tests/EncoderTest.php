<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Encoder;

class EncoderTest extends TestCase
{
    private $encoder;

    public function setUp()
    {
        parent::setUp();

        $this->encoder = new Encoder();
    }

    public function testEncode()
    {
        $this->assertInternalType('string', $this->encoder->encode(['array']));
        $this->assertInternalType('string', $this->encoder->encode(5));
        $this->assertInternalType('string', $this->encoder->encode('string'));
    }

    public function testDecode()
    {
        $this->assertInternalType('array', $this->encoder->decode($this->encoder->encode(['array'])));
        $this->assertInternalType('int', $this->encoder->decode($this->encoder->encode(5)));
        $this->assertInternalType('string', $this->encoder->decode($this->encoder->encode('string')));
    }
}