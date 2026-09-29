<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Predis\Client;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Calibration\TtlCalibrationRepository;
use Spiritix\LadaCache\Console\CalibrateCommand;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CalibrateCommandWithConnectionPrefixTest extends TestCase
{
    private string $sandboxTable = 'lada_cache_calibrations_prefix_test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['lada-cache.enable_debugbar' => false]);

        Schema::dropIfExists($this->sandboxTable);
        Schema::create($this->sandboxTable, function (Blueprint $t): void {
            $t->id();
            $t->string('model_class')->unique();
            $t->string('table_name')->index();
            $t->unsignedInteger('calibrated_ttl');
            $t->json('metrics');
            $t->timestamp('calibrated_at');
            $t->timestamps();
        });

        Config::set('lada-cache.prefix', 'lada:');
        Config::set('lada-cache.active', true);
        Config::set('lada-cache.calibration.enabled', true);
        Config::set('lada-cache.calibration.min_samples', 1);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists($this->sandboxTable);
        parent::tearDown();
    }

    /**
     * A connection-level `prefix` overrides `database.redis.options.prefix`,
     * and an empty one disables it.
     *
     * @return array<string, array{string, string|false, ?string, bool, string}>
     */
    public static function redisSetups(): array
    {
        // client, global prefix, connection prefix, SCAN_PREFIX, prefix the stored keys carry
        return [
            'predis, global prefix' => ['predis', 'app_', null, false, 'app_'],
            'predis, connection prefix' => ['predis', false, 'conn_', false, 'conn_'],
            'predis, connection clears the global prefix' => ['predis', 'app_', '', false, ''],
            'phpredis, global prefix' => ['phpredis', 'app_', null, false, 'app_'],
            'phpredis, connection prefix' => ['phpredis', false, 'conn_', false, 'conn_'],
            'phpredis, connection clears the global prefix' => ['phpredis', 'app_', '', false, ''],
            'phpredis with SCAN_PREFIX, global prefix' => ['phpredis', 'app_', null, true, 'app_'],
        ];
    }

    #[DataProvider('redisSetups')]
    public function testCalibratesWhateverPrefixTheConnectionApplies(
        string $client,
        string|false $globalPrefix,
        ?string $connectionPrefix,
        bool $scanPrefix,
        string $storedPrefix,
    ): void {
        if ($client === 'phpredis' && ! extension_loaded('redis')) {
            $this->markTestSkipped('phpredis extension not loaded.');
        }

        $connection = $this->connectCache($client, $globalPrefix, $connectionPrefix, $scanPrefix);
        $this->assertInstanceOf($client === 'phpredis' ? \Redis::class : Client::class, $connection->client());

        $redis = new Redis($connection);
        $cache = new Cache($redis, new Encoder);
        $database = (string) config('database.connections.'.config('database.default').'.database');
        $tag = sprintf('tags:database:%s:table_specific:cars', $database);

        for ($i = 0; $i < 3; $i++) {
            $cache->set(sprintf('test-calibrate-prefixed-car-%d', $i), [$tag], ['v' => $i], 3600);
        }

        // Proves the setup really produced this key layout.
        $this->assertSame([$storedPrefix.'lada:'.$tag], $connection->keys('lada:'.$tag));

        $command = new CalibrateCommand($redis, new TtlCalibrationRepository($this->sandboxTable));
        $command->setLaravel($this->app);
        $output = new BufferedOutput;

        $exit = $command->run(new ArrayInput(['--model' => Car::class, '--apply' => true]), $output);

        $this->assertSame(Command::SUCCESS, $exit);

        $row = DB::table($this->sandboxTable)->where('model_class', Car::class)->first();
        $this->assertNotNull($row, 'Prefixed tags must be found; output: '.$output->fetch());
        $this->assertSame(3, json_decode($row->metrics, true)['samples']);
    }

    private function connectCache(string $client, string|false $globalPrefix, ?string $connectionPrefix, bool $scanPrefix): Connection
    {
        $config = (array) config('database.redis');
        $config['options']['prefix'] = $globalPrefix;

        if ($scanPrefix) {
            $config['options']['scan'] = \Redis::SCAN_PREFIX;
        }

        if ($connectionPrefix !== null) {
            $config['cache']['prefix'] = $connectionPrefix;
        }

        // Keep the app config in line with the connection, as in a real app.
        config(['database.redis' => $config]);

        return (new RedisManager($this->app, $client, $config))->connection('cache');
    }
}
