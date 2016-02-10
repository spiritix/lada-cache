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
 * A reflector parses a "database action" (not necessarily SQL) and provides information about it.
 *
 * @package Spiritix\LadaCache\Reflector
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
interface ReflectorInterface
{
    /**
     * Must return the affected database.
     *
     * @return string
     */
    public function getDatabase();

    /**
     * Must return the affected table(s).
     *
     * @return array
     */
    public function getTables();

    /**
     * Must return the ID's (primary keys) of the affected row(s).
     *
     * Must return an empty array if it could not determine affected rows.
     *
     * @return array
     */
    public function getRows();
}