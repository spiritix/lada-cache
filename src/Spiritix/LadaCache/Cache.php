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

/**
 * The actual cache.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Cache
{
    /**
     * Redis instance.
     *
     * @var Redis
     */
    protected $redis;

    /**
     * Encoder instance.
     *
     * @var Encoder
     */
    protected $encoder;

    /**
     * Cache expiration time.
     *
     * @var null|int
     */
    private $expirationTime;

    /**
     * Initialize cache.
     *
     * @param Redis   $redis
     * @param Encoder $encoder
     * @param array   $config
     */
    public function __construct(Redis $redis, Encoder $encoder)
    {
        $this->redis = $redis;
        $this->encoder = $encoder;

        $this->expirationTime = config('lada-cache.expiration-time');
    }

    /**
     * Check if a key exists in the cache.
     *
     * @param string $key
     *
     * @return bool
     */
    public function has($key)
    {
        return (bool) $this->redis->exists($this->redis->prefix($key));
    }

    /**
     * Store a value for a given key in the cache.
     *
     * This method does not check if there is a value available for the given key, may cause unexpected behavior if not.
     * Use has() to prevent this issue.
     *
     * @param string $key
     * @param array  $tags
     * @param mixed  $data
     */
    public function set($key, array $tags, $data)
    {
        $key = $this->redis->prefix($key);
        $this->redis->set($key, $this->encoder->encode($data));

        if (is_int($this->expirationTime) && $this->expirationTime > 0) {
            $this->redis->expire($key, $this->expirationTime);
        }

        foreach ($tags as $tag) {
            $this->redis->sadd($this->redis->prefix($tag), $key);
        }
    }

    /**
     * Store given key in tags.
     *
     * Ensure key exists in the given tags.
     * Redis sets are unique, so the same value will not be added twice.
     *
     * @param string $key
     * @param array $tags
     */
    public function setCacheTagsForKey($key, array $tags)
    {
        $key = $this->redis->prefix($key);
        foreach ($tags as $tag) {
            $this->redis->sadd($this->redis->prefix($tag), $key);
        }
    }

    /**
     * Returns value of a cached key.
     *
     * This method does not check if there is a value available for the given key, may return unexpected values if not.
     * Use has() to prevent this issue.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function get($key)
    {
        $encoded = $this->redis->get($this->redis->prefix($key));

        // Prevent decoding if redis returns null (key does not exist).
        if ($encoded === null) {
            return null;
        }

        return $this->encoder->decode($encoded);
    }

    /**
     * Deletes all items from cache.
     *
     * This should only be used for maintenance purposes (slow performance).
     */
    public function flush()
    {
        $prefix = config('database.redis.options.prefix');
        $keys = $this->redis->keys($this->redis->prefix('*'));

        foreach ($keys as $key) {
            $this->redis->del(str_replace($prefix, '', $key));
        }
    }
}
