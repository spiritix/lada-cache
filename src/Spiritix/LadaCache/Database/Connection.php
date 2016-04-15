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

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\Connection as IlluminateConnection;

/**
 * Overrides Laravel's database connection class.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Connection extends IlluminateConnection
{
    /**
     * Get a new query builder instance.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function query()
    {
        return new QueryBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor(),
            app()->make('lada.handler')
        );
    }
}