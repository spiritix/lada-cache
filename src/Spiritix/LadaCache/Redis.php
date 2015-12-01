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

use Illuminate\Support\Facades\Redis as RedisFacade;

/**
 * Handles interaction with Redis store.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Redis
{
    /**
     * Prefix that will be prepended to all items in Redis store.
     *
     * @var string
     */
    public $prefix = '';

    /**
     * Initialize Redis handler.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->prefix = (string) $config['prefix'];
    }

    /**
     * Adds the cache prefix to an item key.
     *
     * @param string $key
     *
     * @return string
     */
    public function prefix($key)
    {
        return $this->prefix . $key;
    }

    /**
     * Forward call to Redis facade.
     *
     * @param string $name
     * @param array $arguments
     *
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return forward_static_call_array(array(RedisFacade::class, $name), $arguments);
    }
}