<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Console;

use Spiritix\LadaCache\Tests\TestCase;

class EnableCommandTest extends TestCase
{
    public function testEnableCommandPrintsMessagesAndSucceeds(): void
    {
        $this->artisan('lada-cache:enable')
            ->expectsOutput('Lada Cache enabled.')
            ->assertExitCode(0);
    }
}

