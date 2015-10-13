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

use Spiritix\LadaCache\Database\QueryBuilder;

/**
 * Todo
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Reflector
{
    /**
     * Query builder instance.
     *
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * Initialize reflector.
     *
     * @param QueryBuilder $queryBuilder
     */
    public function __construct(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }
}