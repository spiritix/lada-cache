<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Console;

use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\TestCase;

class EnableCommandTest extends TestCase
{
    public function testEnableCommandPrintsMessagesAndSucceeds(): void
    {
        $this->artisan('lada-cache:enable')
            ->expectsOutput('Lada Cache enabled.')
            ->assertExitCode(0);
    }

    public function testEnablingRetiresResultsCachedBeforeWritesWentUntracked(): void
    {
        $redis = new Redis;
        $redis->set($redis->prefix('generation'), 'cached-before-disabling');

        $this->artisan('lada-cache:enable')->assertExitCode(0);

        $this->assertNotSame('cached-before-disabling', $redis->get($redis->prefix('generation')));
    }
}
