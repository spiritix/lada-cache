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

/**
 * Enables the database cache.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class EnableCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lada-cache:enable';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enable the database cache';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->writeConfig('active', "env('LADA_CACHE_ACTIVE', true)")) {

            $this->info('Cache enabled');
            $this->warn('Please note that this has no effect if you have LADA_CACHE_ACTIVE=false in your .env file');
        }
    }
}