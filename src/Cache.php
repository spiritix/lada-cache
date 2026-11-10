<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Spiritix\LadaCache\Support\CascadeRead;
use Spiritix\LadaCache\Support\DependentTables;
use Spiritix\LadaCache\Support\Invalidation;
use Spiritix\LadaCache\Support\RedisCooldown;
use Spiritix\LadaCache\Support\RedisReply;
use Spiritix\LadaCache\Support\Write;
use Throwable;
use UnexpectedValueException;

/**
 * Persistence layer for Lada Cache backed by Redis.
 *
 * Architectural notes:
 * - Every operation is a single atomic Lua script, see `LuaScripts` for the key layout.
 * - `lookup()` captures the generation and the fingerprint of the tags' version tokens
 *   before the query runs; `store()` saves the result under that fingerprint, so any
 *   invalidation in between makes the stored entry unreachable instead of stale.
 * - `retireCurrentGeneration()` replaces the generation id, retiring every entry on all
 *   hosts at once; `pruneRetiredGenerations()` deletes leftovers in bounded SCAN batches.
 * - Methods throw on Redis failures; callers decide how to degrade.
 */
final class Cache
{
    private const int PRUNE_BATCH_SIZE = 1000;

    private const array SCHEMA_CHANGE_RETRY_DELAYS_MICROSECONDS = [100_000, 300_000, 900_000];

    private readonly ExpirationPolicy $expiration;

    private readonly EntrySizeLimit $entrySizeLimit;

    public function __construct(
        private readonly Redis $redis,
        private readonly Encoder $encoder,
        ?ExpirationPolicy $expiration = null,
        ?EntrySizeLimit $entrySizeLimit = null,
    ) {
        $this->expiration = $expiration ?? ExpirationPolicy::fromConfig();
        $this->entrySizeLimit = $entrySizeLimit ?? EntrySizeLimit::fromConfig();
    }

    /**
     * @param  array<string>  $tags
     * @param  list<CascadeRead>  $cascadeReads  the tables of the read as foreign key cascades into them see it
     */
    public function lookup(string $key, array $tags, array $cascadeReads): CacheHit|CacheMiss
    {
        $versionTags = self::canonicalTags($tags);
        $arguments = [
            $key,
            self::freshToken(),
            $this->expiration->nextTtl(),
            $this->redis->ladaPrefix(),
            count($versionTags),
            ...$versionTags,
            count($cascadeReads),
        ];

        foreach ($cascadeReads as $read) {
            array_push($arguments, self::dependentsName($read->database), $read->tagPrefix, Write::cascadeColumnsField($read->table), count($read->pins));

            foreach ($read->pins as $column => $keys) {
                array_push($arguments, $column, count($keys), ...$keys);
            }
        }

        $reply = $this->redis->evaluate(LuaScripts::LOOKUP, [
            ...$arguments,
            ...($this->legacyInvalidation() ? self::canonicalTags(Tagger::legacyDependencies($versionTags)) : []),
        ]);

        if (! is_array($reply) || ! isset($reply[0], $reply[1])) {
            throw new UnexpectedValueException('Malformed Lada Cache lookup reply for key '.$key);
        }

        if (isset($reply[2])) {
            $rows = $this->encoder->decode(RedisReply::string($reply[2], 'lookup payload'));

            if ($rows !== null) {
                return new CacheHit($rows);
            }
        }

        return new CacheMiss(new Generation(RedisReply::string($reply[0], 'lookup generation')), $key, RedisReply::string($reply[1], 'lookup fingerprint'));
    }

    /**
     * @param  array<mixed>  $rows
     * @param  array<string>  $tagsReadFromReplica  tags of a result read from a possibly lagging replica
     */
    public function store(CacheMiss $miss, array $rows, array $tagsReadFromReplica = []): void
    {
        $payload = $this->encoder->encode($rows);

        if (! $this->entrySizeLimit->allows($payload)) {
            return;
        }

        $this->redis->evaluate(LuaScripts::STORE, [
            $miss->generation->id,
            $miss->key,
            $miss->fingerprint,
            $this->expiration->nextTtl(),
            $payload,
            ...self::canonicalTags($tagsReadFromReplica),
        ]);
    }

    /**
     * Replaces the version tokens of the invalidation's tags and of the tables its cascade
     * sources change. Returns the databases whose dependent tables are not known in the
     * current generation yet; their cascades were skipped.
     *
     * @return array{generation: Generation|null, databasesWithUnknownDependents: list<string>}
     */
    public function invalidate(Invalidation $invalidation, RedisCooldown $cooldown = RedisCooldown::Respect): array
    {
        $arguments = [
            self::freshToken(),
            $this->expiration->nextTtl(),
            $invalidation->replicaLagSeconds,
            count($invalidation->tags()),
            ...$invalidation->tags(),
        ];

        $cascadeSources = $invalidation->cascadeSources();
        $keyedSources = $invalidation->keyedSources();
        $databases = array_values(array_unique([...array_keys($cascadeSources), ...array_keys($keyedSources)]));
        sort($databases, SORT_STRING);
        $arguments[] = count($databases);

        foreach ($databases as $database) {
            $prefixes = Tagger::tableWideTagPrefixes($database);
            $fields = $cascadeSources[$database] ?? [];
            $keyed = $keyedSources[$database] ?? [];
            array_push($arguments, self::dependentsName($database), count($prefixes), ...$prefixes);
            array_push($arguments, count($fields), ...$fields);
            array_push($arguments, Tagger::cascadeTagPrefix($database), count($keyed));

            foreach ($keyed as $source) {
                array_push($arguments, $source['field'], $source['fallback'], count($source['keys']), ...$source['keys']);
            }
        }

        $reply = $this->redis->evaluate(LuaScripts::INVALIDATE, $arguments, $cooldown);

        if ($this->legacyInvalidation()) {
            $this->redis->evaluate(LuaScripts::PURGE_LEGACY, [$this->redis->ladaPrefix(), ...$invalidation->tags()], $cooldown);
        }

        if (! is_array($reply) || ! isset($reply[0])) {
            throw new UnexpectedValueException('Malformed Lada Cache invalidation reply');
        }

        if ($reply[0] === 0 || $reply[0] === '0') {
            return ['generation' => null, 'databasesWithUnknownDependents' => []];
        }

        return [
            'generation' => new Generation(RedisReply::string($reply[0], 'invalidation generation')),
            'databasesWithUnknownDependents' => array_values(array_map(
                static fn (mixed $group): string => $databases[RedisReply::integer($group, 'invalidation dependents group') - 1] ?? throw new UnexpectedValueException('Unknown dependents group '.json_encode($group).' in Lada Cache invalidation reply'),
                array_slice($reply, 1),
            )),
        ];
    }

    /**
     * @param  array<string, list<string>>  $dependents  `Write` dependents field => tables changed along with it
     * @return bool Whether they were stored, false when the generation was retired meanwhile
     */
    public function storeDependents(Generation $generation, string $database, array $dependents, RedisCooldown $cooldown = RedisCooldown::Respect): bool
    {
        $arguments = [$generation->id, self::dependentsName($database), $this->expiration->nextTtl()];

        foreach ($dependents as $table => $tables) {
            array_push($arguments, $table, implode("\n", $tables));
        }

        return RedisReply::integer($this->redis->evaluate(LuaScripts::STORE_DEPENDENTS, $arguments, $cooldown), 'store dependents reply') === 1;
    }

    private function legacyInvalidation(): bool
    {
        return (bool) config('lada-cache.legacy_invalidation', false);
    }

    /**
     * Hosts with different `dependent_tables` or field layouts, such as old and new pods during a
     * deploy, keep separate dependency maps instead of one host's map applying to all.
     */
    private static function dependentsName(string $database): string
    {
        return $database.':'.substr(sha1((string) json_encode([DependentTables::LAYOUT, DependentTables::configured()])), 0, 12);
    }

    /**
     * Retiring makes every entry unreachable in one command; pruning only reclaims memory.
     *
     * @return int Number of retired keys deleted
     */
    public function flush(): int
    {
        $this->retireCurrentGeneration();

        return $this->pruneRetiredGenerations();
    }

    public function retireCurrentGeneration(): void
    {
        $this->redis->evaluate(LuaScripts::RETIRE, [self::freshToken()]);
    }

    /**
     * Retires the generation even while Redis is in a cooldown, retrying with backoff, because
     * a missed retire after a schema change lets hosts serve results of the previous schema.
     */
    public function retireAfterSchemaChange(): void
    {
        foreach (self::SCHEMA_CHANGE_RETRY_DELAYS_MICROSECONDS as $delay) {
            try {
                $this->redis->evaluate(LuaScripts::RETIRE, [self::freshToken()], RedisCooldown::Ignore);

                return;
            } catch (Throwable) {
                usleep($delay);
            }
        }

        $this->redis->evaluate(LuaScripts::RETIRE, [self::freshToken()], RedisCooldown::Ignore);
    }

    public function pruneRetiredGenerations(): int
    {
        $cursor = '0';
        $deleted = 0;

        do {
            $reply = $this->redis->evaluate(LuaScripts::PRUNE_BATCH, [$cursor, self::PRUNE_BATCH_SIZE]);

            if (! is_array($reply) || ! isset($reply[0], $reply[1])) {
                throw new UnexpectedValueException('Malformed Lada Cache prune reply at cursor '.$cursor);
            }

            $cursor = RedisReply::string($reply[0], 'prune cursor');
            $deleted += RedisReply::integer($reply[1], 'prune count');
        } while ($cursor !== '0');

        return $deleted;
    }

    /**
     * @param  array<string>  $tags
     * @return list<string>
     */
    private static function canonicalTags(array $tags): array
    {
        $unique = array_values(array_unique($tags));
        sort($unique, SORT_STRING);

        return $unique;
    }

    private static function freshToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
