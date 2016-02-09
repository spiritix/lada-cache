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

use Spiritix\LadaCache\Reflector\ReflectorInterface;

/**
 * The actual cache.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Cache
{
    /**
     * Reflector instance.
     *
     * @var ReflectorInterface
     */
    protected $reflector;

    /**
     * Redis instance.
     *
     * @var Redis
     */
    protected $redis;

    /**
     * Cache configuration.
     *
     * @var array
     */
    protected $config = [];

    /**
     * Initialize cache.
     *
     * @param ReflectorInterface $reflector
     * @param Redis              $redis
     * @param array              $config
     */
    public function __construct(ReflectorInterface $reflector, Redis $redis, array $config)
    {
        $this->reflector = $reflector;
        $this->redis = $redis;
        $this->config = $config;
    }

    /**
     * Check if cached version of the query result is available.
     *
     * Will always return false if cache has been disabled in config.
     *
     * @return bool
     */
    public function has()
    {
        $active = (bool) $this->config['active'];
        if ($active === false) {

            return false;
        }

        $hash = $this->reflector->getHash();

        return $this->redis->exists($this->redis->prefix($hash));
    }

    /**
     * Store result of a query in cache.
     *
     * This method does not check if the target query has already been cached.
     * Use has() to prevent this issue.
     *
     * @param array $data Query result
     */
    public function set(array $data)
    {
        $hash = $this->reflector->getHash();
        $tags = $this->reflector->getTags();

        // Store data in cache
        $hash = $this->redis->prefix($hash);
        $this->redis->set($hash, $this->encodeData($data));

        // Add cache key to all tag sets
        // Thanks to this we can easily invalidate the data by tag afterwards
        foreach ($tags as $tag) {

            $this->redis->sadd($this->redis->prefix($tag), [$hash]);
        }
    }

    /**
     * Returns result of a cached query.
     *
     * This method does not check if the query has been cached before, may return unexpected values if not.
     * Use has() to prevent this issue.
     *
     * @return array
     */
    public function get()
    {
        $hash = $this->reflector->getHash();
        $encoded = $this->redis->get($this->redis->prefix($hash));

        return $this->decodeData($encoded);
    }

    /**
     * Invalidates data in the cache.
     *
     * Will send all affected tags to the cache and ask for hashes having at least one of the provided tags.
     * Finally it's going to delete all affected items in the cache.
     */
    public function invalidate()
    {
        $hashes = [];

        // Loop trough tags
        $tags = $this->reflector->getTags(true);
        foreach ($tags as $tag) {

            $tag = $this->redis->prefix($tag);

            // Check if a set exists
            if (!$this->redis->exists($tag)) {
                continue;
            }

            // Add hashes to collection
            $hashes += $this->redis->smembers($tag);

            // Delete tag set
            $this->redis->del($tag);
        }

        // Now delete items
        $hashes = array_unique($hashes);
        foreach ($hashes as $hash) {

            $this->redis->del($hash);
        }
    }

    /**
     * Encodes data in order to be stored as Redis string.
     *
     * @param array $data Decoded data
     *
     * @return string
     */
    protected function encodeData(array $data)
    {
        return json_encode($data);
    }

    /**
     * Decodes data from Redis to array.
     *
     * @param string $data Decoded data
     *
     * @return array
     */
    protected function decodeData($data)
    {
        return json_decode($data, true);
    }
}