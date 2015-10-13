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

use Illuminate\Database\Query\Builder;
use Spiritix\LadaCache\Reflector;

/**
 * Todo
 *
 * @package Spiritix\LadaCache\Database
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class QueryBuilder extends Builder
{
    /**
     * Run the query as a "select" statement against the connection.
     *
     * @return array
     */
    protected function runSelect()
    {
        $reflector = new Reflector($this);

        return $this->connection->select($this->toSql(), $this->getBindings(), ! $this->useWritePdo);
    }
}