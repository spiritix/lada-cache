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

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Overrides Laravel's model class.
 *
 * If Laravel would not have hardcoded the query builder class, this file would not be required anymore.
 * It would also not be required to have all models extending this class.
 *
 * @package Spiritix\LadaCache\Database
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Model extends EloquentModel
{
    /**
     * Get a new query builder instance for the connection.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function newBaseQueryBuilder()
    {
        $conn = $this->getConnection();
        $grammar = $conn->getQueryGrammar();

        return new QueryBuilder(
            $conn,
            $grammar,
            $conn->getPostProcessor(),
            app()->make('lada.handler')
        );
    }
}