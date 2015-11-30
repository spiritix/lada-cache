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

use Spiritix\LadaCache\Reflector\Model as ModelReflector;
use Illuminate\Support\Facades\Redis;

/**
 * Invalidator is responsible for invalidating data in the cache as soon as it changes.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Invalidator
{
    /**
     * Reflector instance.
     *
     * @var ModelReflector
     */
    protected $reflector;

    /**
     * Initialize invalidator instance.
     *
     * @param ModelReflector $reflector
     */
    public function __construct(ModelReflector $reflector)
    {
        $this->reflector = $reflector;
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

            // Check if a set exists
            if (!Redis::exists($tag)) {
                continue;
            }

            // Add hashes to collection
            $hashes += Redis::smembers($tag);

            // Delete tag set
            Redis::del($tag);
        }

        // Now delete items
        $hashes = array_unique($hashes);
        foreach ($hashes as $hash) {

            Redis::del($hash);
        }
    }
}