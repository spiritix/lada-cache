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
 * Query builder reflector provides information about an Eloquent query builder object.
 *
 * @package Spiritix\LadaCache\Reflector
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Reflector
{
    /**
     * Since the query builder doesn't know about the related model, we have no way to figure out the name of the
     * primary key column. If someone is not using this value as primary key column it won't break anything, it just
     * wont consider the row ID's when creating the cache tags.
     *
     * @todo Get the primary key column from the model.
     */
    const PRIMARY_KEY_COLUMN = 'id';

    /**
     * Query builder instance.
     *
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * Values to be saved on the model.
     *
     * @var array
     */
    private $values = [];

    /**
     * The sql operation being performed.
     *
     * @var string
     */
    private $sqlOperation = 'select';

    /**
     * Initialize reflector.
     *
     * @param QueryBuilder $queryBuilder
     */
    public function __construct(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getDatabase()
    {
        return $this->queryBuilder
            ->getConnection()
            ->getDatabaseName();
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getTables()
    {
        // Get main table
        $tables = [$this->queryBuilder->from];

        // Add possible join tables
        $joins = $this->queryBuilder->joins ?: [];
        foreach ($joins as $join) {

            if (!in_array($join->table, $tables)) {
                $tables[] = $join->table;
            }
        }

        return $tables;
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getRows()
    {
        $rows = [];

        $wheres = $this->queryBuilder->wheres ?: [];
        foreach ($wheres as $where) {

            // Skip unsupported clauses
            if (!isset($where['column'])) {
                continue;
            }

            list($table, $column) = $this->splitTableAndColumn($where['column']);

            // Make sure that the where clause applies for the main table
            if ($table !== null && $table !== $this->queryBuilder->from) {
                continue;
            }

            // Make sure that the where clause applies for the primary key column
            if ($column !== self::PRIMARY_KEY_COLUMN) {
                continue;
            }

            if ($where['type'] === 'Basic') {

                if ($where['operator'] === '=' && is_numeric($where['value'])) {
                    $rows[] = $where['value'];
                }
            }
            elseif ($where['type'] === 'In') {
                $rows += $where['values'];
            }
        }

        return $rows;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getSql()
    {
        $compileFunction = $this->getCompileFunction();

        $grammar = $this->queryBuilder->getGrammar();

        return call_user_func_array([$grammar, $compileFunction], [
            'builder'  => $this->queryBuilder,
            'values'   => $this->values,
            'sequence' => '',
        ]);
    }

    /**
     * Get the mysql grammar compile function.
     *
     * @return string
     */
    private function getCompileFunction(): string
    {
        switch (strtolower($this->sqlOperation)) {
            case 'insert':
                return 'compileInsert';
            break;

            case 'insertgetid':
                return 'compileInsertGetId';
            break;

            case 'update':
                return 'compileUpdate';
            break;

            case 'delete':
                return 'compileDelete';
            break;

            case 'truncate':
                return 'compileTruncate';
            break;

            default:
                return 'compileSelect';
            break;
        }
    }

    /**
     * Set values to be modifier on the model.
     *
     * @param array $values
     *
     * @return $this
     */
    public function setValues(array $values)
    {
        $this->values = $values;

        return $this;
    }

    /**
     * Sets the sql operation.
     *
     * @param string $sqlOperation
     *
     * @return $this
     */
    public function setSqlOperation(string $sqlOperation)
    {
        $this->sqlOperation = $sqlOperation;

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getParameters()
    {
        return $this->queryBuilder->getBindings();
    }

    /**
     * Splits an SQL column identifier into table and column.
     *
     * @param string $sqlString SQL column identifier
     *
     * @return array [table|null, column]
     */
    protected function splitTableAndColumn($sqlString)
    {
        // Most column identifiers don't contain a database or table name
        // In this case just return what we've got
        if (strpos($sqlString, '.') === false) {
            return [null, $sqlString];
        }

        $parts = explode('.', $sqlString);

        // If we have three parts, the identifier also contains the database name
        if (count($parts) === 3) {
            $table = $parts[1];
        }
        // Otherwise it contains table and column
        else {
            $table = $parts[0];
        }

        // Column is always the last part
        $column = end($parts);

        return [$table, $column];
    }
}
