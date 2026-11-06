<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use LogicException;

/**
 * Everything one or more writes require to be invalidated: their tags, the tables whose
 * dependents a database may change through foreign key actions, whether the whole cache must
 * be retired, and how long reads from lagging replicas must not be stored afterwards.
 */
final readonly class Invalidation
{
    /**
     * @param  array<string, true>  $tags
     * @param  array<string, array<string, true>>  $cascadeSources  database => tables
     * @param  array<string, string>  $connections  database => name of a connection to it
     */
    private function __construct(
        private array $tags,
        private array $cascadeSources,
        private array $connections,
        public bool $retiresEverything,
        public int $replicaLagSeconds,
    ) {}

    public static function none(): self
    {
        return new self([], [], [], false, 0);
    }

    public static function everything(): self
    {
        return new self([], [], [], true, 0);
    }

    /**
     * @param  array<string>  $tags
     * @param  array<string>  $cascadeTables
     */
    public static function of(array $tags, string $database, string $connection, array $cascadeTables, int $replicaLagSeconds): self
    {
        return new self(
            array_fill_keys($tags, true),
            $cascadeTables === [] ? [] : [$database => array_fill_keys($cascadeTables, true)],
            $cascadeTables === [] ? [] : [$database => $connection],
            false,
            $replicaLagSeconds,
        );
    }

    public function merge(self $other): self
    {
        $cascadeSources = $this->cascadeSources;
        foreach ($other->cascadeSources as $database => $tables) {
            $cascadeSources[$database] = ($cascadeSources[$database] ?? []) + $tables;
        }

        return new self(
            $this->tags + $other->tags,
            $cascadeSources,
            $this->connections + $other->connections,
            $this->retiresEverything || $other->retiresEverything,
            max($this->replicaLagSeconds, $other->replicaLagSeconds),
        );
    }

    public function isEmpty(): bool
    {
        return ! $this->retiresEverything && $this->tags === [] && $this->cascadeSources === [];
    }

    public function size(): int
    {
        return count($this->tags) + array_sum(array_map('count', $this->cascadeSources));
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        $tags = array_map('strval', array_keys($this->tags));
        sort($tags, SORT_STRING);

        return $tags;
    }

    /**
     * @return array<string, list<string>>
     */
    public function cascadeSources(): array
    {
        $sources = [];
        foreach ($this->cascadeSources as $database => $tables) {
            $names = array_map('strval', array_keys($tables));
            sort($names, SORT_STRING);
            $sources[(string) $database] = $names;
        }
        ksort($sources, SORT_STRING);

        return $sources;
    }

    /**
     * @param  list<string>  $databases
     */
    public function onlyCascadesOf(array $databases): self
    {
        $selected = array_flip($databases);

        return new self(
            [],
            array_intersect_key($this->cascadeSources, $selected),
            array_intersect_key($this->connections, $selected),
            false,
            $this->replicaLagSeconds,
        );
    }

    public function withoutRetire(): self
    {
        return new self($this->tags, $this->cascadeSources, $this->connections, false, $this->replicaLagSeconds);
    }

    public function connectionOf(string $database): string
    {
        return $this->connections[$database] ?? throw new LogicException('No connection is known for database '.$database);
    }
}
