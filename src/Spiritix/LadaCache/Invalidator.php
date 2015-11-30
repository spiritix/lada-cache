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
 * Invalidator is responsible for invalidating data in the cache as soon as it changes or expires.
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

    public function invalidate()
    {
        $tags = $this->reflector->getTags();

        foreach ($tags as $tag) {

            if (!Redis::exists($tag)) {
                continue;
            }

            $hashes = Redis::smembers($tags);
            $command = implode(' ', $hashes);

            Redis::del($command);
        }
    }
}