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

use Spiritix\LadaCache\Reflector\AbstractReflector;

/**
 * The cache manager service.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Manager
{
    /**
     * Configuration namespace.
     */
    const CONFIG_NAMESPACE = 'lada-cache';

    /**
     * Redis instance.
     *
     * @var Redis
     */
    protected $redis;

    /**
     * Package configuration.
     *
     * @var array
     */
    protected $config = [];

    /**
     * Initialize manager.
     */
    public function __construct()
    {
        $this->config = (array) config(self::CONFIG_NAMESPACE);
        $this->redis = new Redis($this->config);
    }

    /**
     * Returns Redis instance.
     *
     * @return Redis
     */
    public function getRedis()
    {
        return $this->redis;
    }

    /**
     * Returns configuration.
     *
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Resolves a cache instance.
     *
     * @param AbstractReflector $reflector
     *
     * @return Cache
     */
    public function resolve(AbstractReflector $reflector)
    {
        $reflector->setConfig($this->config);

        return new Cache($reflector, $this->redis, $this->config);
    }

    /**
     * Deletes all items from cache.
     *
     * This should only be used for maintenance purposes (slow performance).
     */
    public function flush()
    {
        $keys = $this->redis->keys('*');

        foreach ($keys as $key) {

            // Remove prefix from key
            // Will be added afterwards automatically
            $key = substr($key, strlen($this->redis->prefix));

            $this->redis->del($key);
        }
    }
}