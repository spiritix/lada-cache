<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Console;

use Exception;
use Illuminate\Console\Command as BaseCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Spiritix\LadaCache\LadaCacheServiceProvider;

/**
 * Base console command for the Lada Cache package.
 *
 * Provides shared helpers for concrete package commands. This class augments
 * Laravel's `Illuminate\\Console\\Command` with a safe `writeConfig()` method
 * that ensures the package config is present and updates a single key without
 * unnecessary writes.
 */
abstract class Command extends BaseCommand
{
    protected function writeConfig(string $key, string|bool|int|float|null $value): bool
    {
        $configFile = config_path(LadaCacheServiceProvider::CONFIG_FILE);

        if (! File::exists($configFile)) {
            Artisan::call('vendor:publish', [
                '--provider' => LadaCacheServiceProvider::class,
                '--tag' => 'config',
                '--force' => true,
            ]);
        }

        try {
            $contents = File::get($configFile);

            $formatted = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_numeric($value) => (string) $value,
                $value === null => 'null',
                default => "'".addslashes((string) $value)."'",
            };

            // Escape the key to avoid unintended regex behavior.
            $escapedKey = preg_quote($key, '/');
            $pattern = "/(['\"]{$escapedKey}['\"]\s*=>\s*)(.*?),(\r?\n)/s";
            $replacement = '$1'.$formatted.',$3';

            $newContents = preg_replace($pattern, $replacement, $contents, 1, $count);
            if ($newContents === null) {
                throw new Exception('Invalid PCRE pattern while updating config.');
            }

            if ($count === 0) {
                $newContents = preg_replace(
                    '/(\);[\r\n]*)$/',
                    "    '{$key}' => {$formatted},\n\n);",
                    $contents
                );
                if ($newContents === null) {
                    throw new Exception('Invalid PCRE pattern while appending config key.');
                }
            }

            // Only write when there is an actual change to persist.
            if ($newContents !== $contents) {
                File::put($configFile, $newContents);
            }
        } catch (Exception $e) {
            $this->error('Could not write lada-cache config: '.$e->getMessage());

            return false;
        }

        Artisan::call('config:clear');

        return true;
    }
}
