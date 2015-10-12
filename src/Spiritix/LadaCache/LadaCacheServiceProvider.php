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

namespace Spiritix\LadaCache;

use Illuminate\Support\ServiceProvider;

/**
 * Todo
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class LadaCacheServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Bootstrap the application events.
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../../../config/lada-cache.php' => config_path('lada-cache.php'),
        ]);

        $this->mergeConfigFrom(
            __DIR__ . '/../../../config/lada-cache.php', 'lada-cache'
        );

        $this->registerModelObservers();
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        //
    }

    /**
     * Registers the model observers.
     */
    private function registerModelObservers()
    {
        Model::created(function($model) {
            return true;
        });

        Model::updated(function($model) {
            return true;
        });

        Model::deleted(function($model) {
            return true;
        });

        Model::saved(function($model) {
            return true;
        });
    }
}