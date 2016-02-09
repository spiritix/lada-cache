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
 * Disables the database cache.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class DisableCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lada-cache:disable';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Disable the database cache';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->writeConfig('active', 'false')) {
            $this->info('Cache disabled');
        }
    }
}