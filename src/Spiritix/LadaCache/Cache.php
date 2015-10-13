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
 * Adapted from Cm_Cache_Backend_Redis.
 * @see https://github.com/colinmollenhour/Cm_Cache_Backend_Redis
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
    }

    /**
     * Gets result of a cached query
     *
     * @return array
     */
    public function get()
    {
        $hash = $this->reflector->getHash();
    }
}