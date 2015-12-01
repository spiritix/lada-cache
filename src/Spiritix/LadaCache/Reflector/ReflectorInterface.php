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
 * Reflector interface.
 *
 * @package Spiritix\LadaCache\Reflector
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
interface ReflectorInterface
{
    /**
     * Returns an array of all tags for current action.
     *
     * @param bool $forceTables If set to true, the function will add the table tags to the row tags.
     *                          By default if rows are available, table tags will not be returned.
     *
     * @return array
     */
    public function getTags($forceTables = false);
}