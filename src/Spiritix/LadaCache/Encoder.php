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
 * Encoder converts data of whatever type into a string that may be stored in cache.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Encoder
{
    /**
     * Encodes data in order to be stored as string.
     *
     * @param mixed $data Decoded data
     *
     * @return string
     */
    public function encode($data)
    {
        return json_encode($data);
    }

    /**
     * Decodes data from string to whatever it has been before.
     *
     * @param string $data Decoded data
     *
     * @return mixed
     */
    public function decode($data)
    {
        return json_decode($data);
    }
}