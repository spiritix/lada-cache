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

namespace Spiritix\LadaCache\Reflector;

/**
 * A "hashable" reflector must return sufficient information about a "database action" in order to hash it.
 *
 * @package Spiritix\LadaCache\Reflector
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
interface HashableReflectorInterface extends ReflectorInterface
{
    /**
     * Must return the query's SQL.
     *
     * @return string
     */
    public function getSql();

    /**
     * Must return the query's parameters if any.
     *
     * @return array
     */
    public function getParameters();
}