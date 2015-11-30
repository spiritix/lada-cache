<?php

namespace Spiritix\LadaCache\Tests\Reflector;

use Spiritix\LadaCache\Database\Model as EloquentModel;
use Spiritix\LadaCache\Reflector\Model;
use Spiritix\LadaCache\Tests\TestCase;

class ModelTest extends TestCase
{
    public function testGetDatabase()
    {
        //
    }

    public function testGetTables()
    {
        $model = new EloquentModel();
        $model->setTable('table');

        $reflector = new Model($model);

        $this->assertEquals(['table'], $reflector->getTables());
    }

    public function testGetRows()
    {
        //
    }
}