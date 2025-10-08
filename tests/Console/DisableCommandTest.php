<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Console;

use Spiritix\LadaCache\Tests\TestCase;

class DisableCommandTest extends TestCase
{
    public function testDisableCommandPrintsMessageAndSucceeds(): void
    {
        $this->artisan('lada-cache:disable')
            ->expectsOutput('Lada Cache disabled.')
            ->assertExitCode(0);
    }
}

