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

use Illuminate\Database\Eloquent\Builder;
use Spiritix\LadaCache\QueryHandler;

/**
 * Overrides Laravel's Eloquent builder class.
 *
 * @package Spiritix\LadaCache\Database
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class EloquentBuilder extends Builder
{
    /**
     * Handler instance.
     *
     * @var QueryHandler
     */
    private $handler;

    /**
     * Create a new Eloquent query builder instance.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return void
     */
    public function __construct(QueryBuilder $query, QueryHandler $handler)
    {
        parent::__construct($query);

        $this->handler = $handler;
    }
}