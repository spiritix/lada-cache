<?php

namespace Spiritix\LadaCache\Tests;

use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\LadaCacheToggle;

class CacheToggleTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Always start with cache enabled
        LadaCacheToggle::enable();
    }

    public function tearDown(): void
    {
        // Reset after each test
        LadaCacheToggle::enable();
        parent::tearDown();
    }

    public function testDisableSetsFlag()
    {
        $this->assertFalse(LadaCacheToggle::isTemporarilyDisabled(), 'Should start enabled');
        LadaCacheToggle::disable();
        $this->assertTrue(LadaCacheToggle::isTemporarilyDisabled(), 'Should be disabled after calling disable()');
    }

    public function testEnableSetsFlagBack()
    {
        LadaCacheToggle::disable();
        $this->assertTrue(LadaCacheToggle::isTemporarilyDisabled(), 'Should be disabled');
        LadaCacheToggle::enable();
        $this->assertFalse(LadaCacheToggle::isTemporarilyDisabled(), 'Should be enabled after calling enable()');
    }

    public function testDataPulledFromDatabaseWhenTemporarilyDisabled()
    {
        // Set up an in-memory SQLite database for testing
        $capsule = new \Illuminate\Database\Capsule\Manager;
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Create a dummy table
        $capsule->getConnection()->getSchemaBuilder()->create('dummy', function ($table) {
            $table->increments('id');
            $table->string('name');
        });

        // Define a test model using LadaCacheTrait
        eval('
            namespace Spiritix\\LadaCache\\Tests;
            use Illuminate\\Database\\Eloquent\\Model;
            use Spiritix\\LadaCache\\Database\\LadaCacheTrait;
            class DummyModel extends Model {
                use LadaCacheTrait;
                protected $table = "dummy";
                public $timestamps = false;
                protected $fillable = ["name"];
            }
        ');

        // Insert a row directly into the database
        $modelClass = 'Spiritix\\LadaCache\\Tests\\DummyModel';
        $modelClass::create(['name' => 'test-value']);

        // Disable LadaCache temporarily
        \Spiritix\LadaCache\LadaCacheToggle::disable();

        // Query the model - should hit the database, not Redis
        $result = $modelClass::where('name', 'test-value')->first();

        $this->assertNotNull($result, 'Should return a result from the database');
        $this->assertEquals('test-value', $result->name, 'Should pull the correct value from the database');
    }
}
