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

use Exception;
use Illuminate\Console\Command as IlluminateCommand;
use Illuminate\Support\Facades\File;
use Spiritix\LadaCache\LadaCacheServiceProvider;

/**
 * Console command.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Command extends IlluminateCommand
{
    /**
     * Writes a value to package configuration file.
     */
    protected function writeConfig($key, $value)
    {
        $file = config_path(LadaCacheServiceProvider::CONFIG_FILE);

        if (!File::exists($file)) {
            $this->call('vendor:publish');
        }

        try {
            $contents = File::get($file);

            $contents = preg_replace(
                "/'" . $key . "'(.*?),\s?\n/s",
                "'" . $key . "' => " . $value . ",\n\n",
                $contents
            );

            File::put($file, $contents);
        }
        catch (Exception $e) {
            $this->error('Could not write config file');

            return false;
        }

        $this->call('config:clear');

        return true;
    }
}