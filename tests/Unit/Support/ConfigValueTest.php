<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spiritix\LadaCache\CacheScope;
use Spiritix\LadaCache\LadaCacheServiceProvider;
use Spiritix\LadaCache\Support\ConfigValue;
use Spiritix\LadaCache\Tests\TestCase;

class ConfigValueTest extends TestCase
{
    public function testReadsValidValues(): void
    {
        config([
            'lada-cache.redis_connection' => 'lada',
            'lada-cache.prefix' => 'app:',
            'lada-cache.expiration_time' => '3600',
            'lada-cache.include_tables' => ['users', 'posts'],
            'lada-cache.exclude_tables' => ['jobs' => 'jobs'],
            'lada-cache.scope' => 'models',
        ]);

        $this->assertSame('lada', ConfigValue::redisConnection());
        $this->assertSame('app:', ConfigValue::prefix());
        $this->assertSame(3600, ConfigValue::expirationTime());
        $this->assertSame(['users', 'posts'], ConfigValue::includeTables());
        $this->assertSame(['jobs'], ConfigValue::excludeTables());
        $this->assertSame(CacheScope::Models, ConfigValue::scope());
    }

    public function testEmptyOrNullValuesFallBackToDefaults(): void
    {
        config([
            'lada-cache.redis_connection' => '',
            'lada-cache.prefix' => null,
            'lada-cache.expiration_time' => null,
            'lada-cache.include_tables' => null,
            'lada-cache.scope' => '',
        ]);

        $this->assertSame('cache', ConfigValue::redisConnection());
        $this->assertSame('lada:', ConfigValue::prefix());
        $this->assertSame(0, ConfigValue::expirationTime());
        $this->assertSame([], ConfigValue::includeTables());
        $this->assertSame(CacheScope::All, ConfigValue::scope());
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function invalidValues(): array
    {
        return [
            'non-string prefix' => ['prefix', ['lada:'], 'Invalid lada-cache configuration lada-cache.prefix: expected a string, got array.'],
            'non-string redis connection' => ['redis_connection', 7, 'Invalid lada-cache configuration lada-cache.redis_connection: expected a string, got 7.'],
            'non-numeric expiration' => ['expiration_time', '1 week', "Invalid lada-cache configuration lada-cache.expiration_time: expected a non-negative integer, got '1 week'."],
            'negative expiration' => ['expiration_time', -1, 'Invalid lada-cache configuration lada-cache.expiration_time: expected a non-negative integer, got -1.'],
            'fractional expiration' => ['expiration_time', 1.5, 'Invalid lada-cache configuration lada-cache.expiration_time: expected a non-negative integer, got 1.5.'],
            'string include tables' => ['include_tables', 'users', "Invalid lada-cache configuration lada-cache.include_tables: expected a list of strings, got 'users'."],
            'unknown scope' => ['scope', 'everything', "Invalid lada-cache configuration lada-cache.scope: expected one of all, models, got 'everything'."],
            'nested exclude tables' => ['exclude_tables', ['cars', ['engines']], 'Invalid lada-cache configuration lada-cache.exclude_tables: expected a list of strings, got an entry array.'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValueFailsWithMessageNamingTheKey(string $key, mixed $value, string $message): void
    {
        config(['lada-cache.'.$key => $value]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ConfigValue::validate();
    }

    public function testInvalidValueFailsAtBoot(): void
    {
        config(['lada-cache.expiration_time' => 'forever']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lada-cache.expiration_time');

        (new LadaCacheServiceProvider($this->app))->register();
    }

    public function testInvalidValueIsNotCheckedWhileDisabled(): void
    {
        config(['lada-cache.active' => false, 'lada-cache.expiration_time' => 'forever']);

        (new LadaCacheServiceProvider($this->app))->register();

        $this->addToAssertionCount(1);
    }
}
