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

use Illuminate\Support\Facades\Redis;

/**
 * Todo
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Cache
{
    /**
     * Reflector instance
     *
     * @var Reflector
     */
    protected $reflector;

    /**
     * Initialize cache instance
     *
     * @param Reflector $reflector
     */
    public function __construct(Reflector $reflector)
    {
        $this->reflector = $reflector;
    }

    /**
     * Check if cached version of query is available
     *
     * @return bool
     */
    public function has()
    {
        $hash = $this->reflector->getHash();

        return Redis::exists($hash);
    }

    /**
     * Set result of a query
     *
     * @param array $data Query result
     */
    public function set(array $data)
    {
        $hash = $this->reflector->getHash();
        $tags = $this->reflector->getTags();

        Redis::set($hash, $this->encodeData($data));

        foreach ($tags as $tag) {
            Redis::sadd($tag, [$hash]);
        }
    }

    /**
     * Gets result of a cached query
     *
     * @return array
     */
    public function get()
    {
        $hash = $this->reflector->getHash();
        $encoded = Redis::get($hash);

        return $this->decodeData($encoded);
    }

    /**
     * Encodes data in order to be stored as Redis string
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
     * Decodes data from Redis to array
     *
     * @param string $data Decoded data
     *
     * @return array
     */
    protected function decodeData($data)
    {
        return json_decode($data);
    }
}