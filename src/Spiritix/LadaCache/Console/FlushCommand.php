<?php
/**
 * This file is part of the spiritix/lada-cache package.
 *
 * @copyright Copyright (c) Matthias Isler <mi@matthias-isler.ch>
 * @license   MIT
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Spiritix\LadaCache\Console;

use Illuminate\Console\Command;

/**
 * The flush command deletes all items from the cache.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class FlushCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lada-cache:flush';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flush the database cache';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $cache = app()->make('lada.cache');
        $cache->flush();

        $this->info('Cache flushed');
    }
}