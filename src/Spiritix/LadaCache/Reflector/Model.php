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

use Spiritix\LadaCache\Database\Model as EloquentModel;

/**
 * Model reflector provides information about an Eloquent model object.
 *
 * @package Spiritix\LadaCache\Reflector
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Model extends AbstractReflector implements ReflectorInterface
{
    /**
     * Model instance.
     *
     * @var EloquentModel
     */
    protected $model;

    /**
     * Initialize reflector.
     *
     * @param EloquentModel $model
     */
    public function __construct(EloquentModel $model)
    {
        $this->model = $model;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getDatabase()
    {
        return $this->model->getConnection()
            ->getDatabaseName();
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getTables()
    {
        return [$this->model->getTable()];
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getRows()
    {
        return [$this->model->getKey()];
    }
}