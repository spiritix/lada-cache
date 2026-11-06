<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

/**
 * Atomic Redis operations backing the cache.
 *
 * Every script receives the full key base (connection prefix plus Lada prefix) as ARGV[1],
 * prepended by `Redis::evaluate()`, and builds keys itself, so all keys share one layout
 * regardless of the Redis client:
 *
 * - `<base>generation`                       current generation id
 * - `<base>schema:<database>`                 schema version of a database, read from its
 *                                              migrations table and kept for a few seconds
 * - `<base><generation>:e:<schema>:<hash>`    entry: 40 character fingerprint, then the payload
 * - `<base><generation>:v:<tag>`              current version token of a tag
 *
 * An entry is valid only while the fingerprint of its tags' current tokens matches the one
 * captured before the query ran, so a result read before a concurrent write is never served.
 * Entries live under the schema version they were read with, so once a migration is recorded
 * every host stops reading entries of the previous schema within the schema TTL, even when
 * nothing retired the generation. Version tokens are shared across schema versions, so a
 * write invalidates entries of every schema version.
 */
final class LuaScripts
{
    /**
     * ARGV: base, entry hash, fresh token, ttl seconds, database, schema candidate, schema ttl, tags...
     * Returns 0 when the schema version is unknown and the candidate is empty; the caller then
     * reads it from the database and retries with it as candidate. Otherwise returns
     * {generation, schema, fingerprint} on a miss and {generation, schema, fingerprint, payload}
     * on a hit.
     */
    public const string LOOKUP = <<<'LUA'
        local base, fresh, ttl = ARGV[1], ARGV[3], tonumber(ARGV[4])
        local schemaKey = base .. 'schema:' .. ARGV[5]
        local schema = redis.call('GET', schemaKey)
        if not schema then
            if ARGV[6] == '' then
                return 0
            end
            redis.call('SET', schemaKey, ARGV[6], 'EX', tonumber(ARGV[7]))
            schema = ARGV[6]
        end
        local generationKey = base .. 'generation'
        local generation = redis.call('GET', generationKey)
        if not generation then
            redis.call('SET', generationKey, fresh)
            generation = fresh
        end
        local scope = base .. generation .. ':'
        local tokens = {}
        for i = 8, #ARGV do
            local versionKey = scope .. 'v:' .. ARGV[i]
            local token = redis.call('GET', versionKey)
            if not token then
                token = fresh
                if ttl > 0 then
                    redis.call('SET', versionKey, token, 'EX', ttl)
                else
                    redis.call('SET', versionKey, token)
                end
            end
            tokens[#tokens + 1] = token
        end
        local fingerprint = redis.sha1hex(table.concat(tokens, ':'))
        local entry = redis.call('GET', scope .. 'e:' .. schema .. ':' .. ARGV[2])
        if entry and string.sub(entry, 1, 40) == fingerprint then
            return {generation, schema, fingerprint, string.sub(entry, 41)}
        end
        return {generation, schema, fingerprint}
        LUA;

    /**
     * ARGV: base, generation, schema, entry hash, fingerprint, ttl seconds, payload
     * Returns 1 when stored and 0 when the generation was retired meanwhile.
     */
    public const string STORE = <<<'LUA'
        local base, generation, ttl = ARGV[1], ARGV[2], tonumber(ARGV[6])
        if redis.call('GET', base .. 'generation') ~= generation then
            return 0
        end
        local entryKey = base .. generation .. ':e:' .. ARGV[3] .. ':' .. ARGV[4]
        local value = ARGV[5] .. ARGV[7]
        if ttl > 0 then
            redis.call('SET', entryKey, value, 'EX', ttl)
        else
            redis.call('SET', entryKey, value)
        end
        return 1
        LUA;

    /**
     * ARGV: base, fresh token, ttl seconds, tags...
     * Returns the number of tags whose version was replaced.
     */
    public const string INVALIDATE = <<<'LUA'
        local base, fresh, ttl = ARGV[1], ARGV[2], tonumber(ARGV[3])
        local generation = redis.call('GET', base .. 'generation')
        if not generation then
            return 0
        end
        local scope = base .. generation .. ':v:'
        for i = 4, #ARGV do
            if ttl > 0 then
                redis.call('SET', scope .. ARGV[i], fresh, 'EX', ttl)
            else
                redis.call('SET', scope .. ARGV[i], fresh)
            end
        end
        return #ARGV - 3
        LUA;

    /**
     * ARGV: base, fresh generation id
     */
    public const string RETIRE = <<<'LUA'
        redis.call('SET', ARGV[1] .. 'generation', ARGV[2])
        return 1
        LUA;

    /**
     * ARGV: base, cursor, batch size
     * Deletes one SCAN batch of keys that belong to no current entry, version or schema version.
     * Returns {next cursor, deleted count}.
     */
    public const string PRUNE_BATCH = <<<'LUA'
        local base = ARGV[1]
        local pattern = string.gsub(base, '([%*%?%[%]\\])', '\\%1') .. '*'
        local generationKey = base .. 'generation'
        local schemaPrefix = base .. 'schema:'
        local generation = redis.call('GET', generationKey)
        local entryPrefix, versionPrefix
        if generation then
            entryPrefix = base .. generation .. ':e:'
            versionPrefix = base .. generation .. ':v:'
        end
        local scan = redis.call('SCAN', ARGV[2], 'MATCH', pattern, 'COUNT', ARGV[3])
        local stale = {}
        for _, key in ipairs(scan[2]) do
            local current = generation ~= false and generation ~= nil
                and (string.sub(key, 1, #entryPrefix) == entryPrefix or string.sub(key, 1, #versionPrefix) == versionPrefix)
            if key ~= generationKey and string.sub(key, 1, #schemaPrefix) ~= schemaPrefix and not current then
                stale[#stale + 1] = key
            end
        end
        if #stale > 0 then
            redis.call('UNLINK', unpack(stale))
        end
        return {scan[1], #stale}
        LUA;
}
