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
 * Hasher generates a hash for a query using a reflector.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Hasher
{
    /**
     * Reflector instance.
     *
     * @var Reflector
     */
    private $reflector;

    /**
     * Initialize hasher.
     *
     * @param Reflector $reflector Reflector instance
     */
    public function __construct(Reflector $reflector)
    {
        $this->reflector = $reflector;
    }

    /**
     * Returns the hash.
     *
     * @return string
     */
    public function getHash()
    {
        // Never remove the database from the identifier
        // Most SQL queries do not include the target database
        $identifier = $this->reflector->getDatabase() .
                      $this->reflector->getSql() .
                      serialize($this->reflector->getParameters());

        return md5($identifier);
    }
}