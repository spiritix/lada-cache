<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Rollout;

use JsonException;
use RuntimeException;

/**
 * Starts hosts of a rolling deploy as separate PHP processes running pod.php, each on either
 * this version or the 5.x release in tests/Rollout/legacy, all sharing one database and Redis.
 */
final class Pods
{
    /**
     * @param  array<string, string>  $environment  overrides for every process started
     */
    public function __construct(private array $environment = []) {}

    public static function skipReason(): ?string
    {
        if (! in_array(getenv('DB_CONNECTION'), ['mysql', 'pgsql'], true)) {
            return 'The Rollout suite needs MySQL or PostgreSQL, which hosts of both versions share; 5.x never wrapped SQLite connections.';
        }

        if (! is_file(__DIR__.'/legacy/vendor/autoload.php')) {
            return 'Install the 5.x release first: composer install -d tests/Rollout/legacy';
        }

        return null;
    }

    public function with(string $name, string $value): self
    {
        return new self([...$this->environment, $name => $value]);
    }

    public function run(string $version, string $operation, string|int ...$arguments): mixed
    {
        $process = $this->start($version, $operation, ...$arguments);

        return $this->finish($process, "{$version} {$operation} ".implode(' ', $arguments));
    }

    /**
     * Runs the commands at the same time and returns their results in the same order.
     *
     * @param  list<array{string, string, list<string|int>}>  $commands  version, operation, arguments
     * @return list<mixed>
     */
    public function runConcurrently(array $commands): array
    {
        $started = array_map(fn (array $command): array => $this->start($command[0], $command[1], ...$command[2]), $commands);

        return array_map(
            fn (array $process, array $command): mixed => $this->finish($process, "{$command[0]} {$command[1]} ".implode(' ', $command[2])),
            $started,
            $commands,
        );
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function start(string $version, string $operation, string|int ...$arguments): array
    {
        $command = [PHP_BINARY, __DIR__.'/pod.php', $version, $operation, ...array_map('strval', $arguments)];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [...getenv(), ...self::databaseDefaults(), ...$this->environment]);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start pod: '.implode(' ', $command));
        }

        return [$process, $pipes];
    }

    /**
     * @param  array{0: resource, 1: array<int, resource>}  $process
     */
    private function finish(array $process, string $description): mixed
    {
        [$handle, $pipes] = $process;
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($handle);

        if ($exitCode !== 0) {
            throw new RuntimeException("Pod `{$description}` exited with {$exitCode}:\n{$errors}{$output}");
        }

        try {
            return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Pod `{$description}` printed no JSON:\n{$errors}{$output}", previous: $exception);
        }
    }

    /**
     * The same defaults TestCase uses, so the suites share the databases `just services` starts.
     *
     * @return array<string, string>
     */
    private static function databaseDefaults(): array
    {
        $postgres = getenv('DB_CONNECTION') === 'pgsql';

        return array_filter([
            'DB_DATABASE' => getenv('DB_DATABASE') ?: 'test',
            'DB_USERNAME' => getenv('DB_USERNAME') ?: ($postgres ? 'postgres' : 'root'),
            'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'secret',
            'REDIS_CLIENT' => getenv('REDIS_CLIENT') ?: 'predis',
        ]);
    }
}
