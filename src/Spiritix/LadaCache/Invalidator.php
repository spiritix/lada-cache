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

use Illuminate\Database\Eloquent\Model;

/**
 * Invalidator is responsible for invalidating data in the cache as soon as it changes or expires.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Invalidator
{
    protected $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function invalidate()
    {
        var_dump($this->model);die();
    }
}