<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Spiritix\LadaCache\Support\DependentTables;
use Spiritix\LadaCache\Support\Invalidation;
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
     */
    public function lookup(string $key, array $tags): CacheHit|CacheMiss
    {
        $reply = $this->redis->evaluate(LuaScripts::LOOKUP, [
            $key,
            self::freshToken(),
            $this->expiration->nextTtl(),
            ...self::canonicalTags($tags),
        ]);

        if (! is_array($reply) || ! isset($reply[0], $reply[1])) {
            throw new UnexpectedValueException('Malformed Lada Cache lookup reply for key '.$key);
        }

        if (isset($reply[2])) {
            $rows = $this->encoder->decode((string) $reply[2]);

            if ($rows !== null) {
                return new CacheHit($rows);
            }
        }

        return new CacheMiss(new Generation((string) $reply[0]), $key, (string) $reply[1]);
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
    public function invalidate(Invalidation $invalidation): array
    {
        $arguments = [
            self::freshToken(),
            $this->expiration->nextTtl(),
            $invalidation->replicaLagSeconds,
            count($invalidation->tags()),
            ...$invalidation->tags(),
            count($invalidation->cascadeSources()),
        ];

        $databases = array_keys($invalidation->cascadeSources());

        foreach ($invalidation->cascadeSources() as $database => $fields) {
            $prefixes = Tagger::tableWideTagPrefixes($database);
            array_push($arguments, self::dependentsName($database), count($prefixes), ...$prefixes);
            array_push($arguments, count($fields), ...$fields);
        }

        $reply = $this->redis->evaluate(LuaScripts::INVALIDATE, $arguments);

        if ($this->legacyInvalidation()) {
            $this->redis->evaluate(LuaScripts::PURGE_LEGACY, [$this->redis->ladaPrefix(), ...$invalidation->tags()]);
        }

        if (! is_array($reply) || ! isset($reply[0])) {
            throw new UnexpectedValueException('Malformed Lada Cache invalidation reply');
        }

        if ($reply[0] === 0 || $reply[0] === '0') {
            return ['generation' => null, 'databasesWithUnknownDependents' => []];
        }

        return [
            'generation' => new Generation((string) $reply[0]),
            'databasesWithUnknownDependents' => array_map(
                static fn (mixed $group): string => $databases[(int) $group - 1] ?? throw new UnexpectedValueException('Unknown dependents group '.json_encode($group).' in Lada Cache invalidation reply'),
                array_slice($reply, 1),
            ),
        ];
    }

    /**
     * @param  array<string, list<string>>  $dependents  `WriteKind::dependentsField()` => tables changed along with it
     * @return bool Whether they were stored, false when the generation was retired meanwhile
     */
    public function storeDependents(Generation $generation, string $database, array $dependents): bool
    {
        $arguments = [$generation->id, self::dependentsName($database), $this->expiration->nextTtl()];

        foreach ($dependents as $table => $tables) {
            array_push($arguments, $table, implode("\n", $tables));
        }

        return (int) $this->redis->evaluate(LuaScripts::STORE_DEPENDENTS, $arguments) === 1;
    }

    private function legacyInvalidation(): bool
    {
        return (bool) config('lada-cache.legacy_invalidation', false);
    }

    /**
     * Hosts with different `dependent_tables`, such as old and new pods during a deploy, keep
     * separate dependency maps instead of one host's configuration applying to all.
     */
    private static function dependentsName(string $database): string
    {
        return $database.':'.substr(sha1((string) json_encode(DependentTables::configured())), 0, 12);
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
                $this->redis->evaluateIgnoringCooldown(LuaScripts::RETIRE, [self::freshToken()]);

                return;
            } catch (Throwable) {
                usleep($delay);
            }
        }

        $this->redis->evaluateIgnoringCooldown(LuaScripts::RETIRE, [self::freshToken()]);
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

            $cursor = (string) $reply[0];
            $deleted += (int) $reply[1];
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
