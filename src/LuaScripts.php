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
 * - `<base>generation`                  current generation id
 * - `<base>server`                      run id of the Redis server that wrote the keys
 * - `<base><generation>:e:<hash>`       entry: 40 character fingerprint followed by the payload
 * - `<base><generation>:v:<tag>`        current version token of a tag
 * - `<base><generation>:w:<tag>`        present while reads of the tag may come from a lagging replica
 * - `<base><generation>:d:<name>`       hash of WriteKind field => newline separated tables changed along with it,
 *                                       named by database and dependency configuration
 *
 * An entry is valid only while the fingerprint of its tags' current tokens matches the one
 * captured before the query ran, so a result read before a concurrent write is never served.
 */
final class LuaScripts
{
    /**
     * Defines retireIfServerChanged(base). A restarted Redis or a promoted replica may have lost
     * the newest version tokens, which would make invalidated entries reachable again; seeing a
     * new server run id retires the generation, once, and returns true.
     */
    private const string SERVER_CHECK = <<<'LUA'
        local function retireIfServerChanged(base)
            local runId = string.match(redis.call('INFO', 'server'), 'run_id:(%w+)')
            local serverKey = base .. 'server'
            local known = redis.call('GET', serverKey)
            if known == runId then
                return false
            end
            redis.call('SET', serverKey, runId)
            if not known then
                return false
            end
            redis.call('SET', base .. 'generation', string.sub(redis.sha1hex(runId .. known), 1, 32))
            return true
        end

        LUA;

    /**
     * ARGV: base, entry hash, fresh token, ttl seconds, tags...
     * Returns {generation, fingerprint} on a miss and {generation, fingerprint, payload} on a hit.
     */
    public const string LOOKUP = <<<'LUA'
        local base, fresh, ttl = ARGV[1], ARGV[3], tonumber(ARGV[4])
        local generationKey = base .. 'generation'
        local generation = redis.call('GET', generationKey)
        if not generation then
            redis.call('SET', generationKey, fresh)
            generation = fresh
        end
        local scope = base .. generation .. ':'
        local tokens = {}
        for i = 5, #ARGV do
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
        local entry = redis.call('GET', scope .. 'e:' .. ARGV[2])
        if entry and string.sub(entry, 1, 40) == fingerprint then
            return {generation, fingerprint, string.sub(entry, 41)}
        end
        return {generation, fingerprint}
        LUA;

    /**
     * ARGV: base, generation, entry hash, fingerprint, ttl seconds, payload, tags read from a replica...
     * Returns 1 when stored, 0 when the generation was retired meanwhile and -1 when one of the
     * tags was written too recently for a replica read to be trusted.
     */
    public const string STORE = self::SERVER_CHECK.<<<'LUA'
        local base, generation, ttl = ARGV[1], ARGV[2], tonumber(ARGV[5])
        if retireIfServerChanged(base) then
            return 0
        end
        if redis.call('GET', base .. 'generation') ~= generation then
            return 0
        end
        local scope = base .. generation .. ':'
        for i = 7, #ARGV do
            if redis.call('EXISTS', scope .. 'w:' .. ARGV[i]) == 1 then
                return -1
            end
        end
        local entryKey = scope .. 'e:' .. ARGV[3]
        local value = ARGV[4] .. ARGV[6]
        if ttl > 0 then
            redis.call('SET', entryKey, value, 'EX', ttl)
        else
            redis.call('SET', entryKey, value)
        end
        return 1
        LUA;

    /**
     * ARGV: base, fresh token, ttl seconds, replica lag seconds, tag count, tags...,
     *       group count, then per group: dependents name, prefix count, prefixes..., field count, dependents fields (see WriteKind)...
     * Replaces the tokens of the tags, and of every table-wide tag of the tables the database
     * changes along with each group's tables. Returns {0} when nothing was ever cached, else
     * {generation, 1-based indexes of the groups whose dependents are not known yet...}.
     */
    public const string INVALIDATE = self::SERVER_CHECK.<<<'LUA'
        local base, fresh, ttl, lag = ARGV[1], ARGV[2], tonumber(ARGV[3]), tonumber(ARGV[4])
        if retireIfServerChanged(base) then
            return {0}
        end
        local generation = redis.call('GET', base .. 'generation')
        if not generation then
            return {0}
        end
        local scope = base .. generation .. ':'
        local function replace(tag)
            if ttl > 0 then
                redis.call('SET', scope .. 'v:' .. tag, fresh, 'EX', ttl)
            else
                redis.call('SET', scope .. 'v:' .. tag, fresh)
            end
            if lag > 0 then
                redis.call('SET', scope .. 'w:' .. tag, '1', 'EX', lag)
            end
        end
        local index = 5
        local tagCount = tonumber(ARGV[index])
        index = index + 1
        for _ = 1, tagCount do
            replace(ARGV[index])
            index = index + 1
        end
        local result = {generation}
        local groupCount = tonumber(ARGV[index])
        index = index + 1
        for group = 1, groupCount do
            local dependentsName = ARGV[index]
            local prefixCount = tonumber(ARGV[index + 1])
            index = index + 2
            local prefixes = {}
            for p = 1, prefixCount do
                prefixes[p] = ARGV[index]
                index = index + 1
            end
            local fieldCount = tonumber(ARGV[index])
            index = index + 1
            local dependentsKey = scope .. 'd:' .. dependentsName
            local known = redis.call('EXISTS', dependentsKey) == 1
            if not known then
                result[#result + 1] = group
            end
            for _ = 1, fieldCount do
                if known then
                    local dependents = redis.call('HGET', dependentsKey, ARGV[index])
                    if dependents then
                        for dependent in string.gmatch(dependents, '[^\n]+') do
                            for p = 1, prefixCount do
                                replace(prefixes[p] .. dependent)
                            end
                        end
                    end
                end
                index = index + 1
            end
        end
        return result
        LUA;

    /**
     * ARGV: base, generation, dependents name, ttl seconds, then pairs of WriteKind field and newline separated dependents
     * Returns 1 when stored and 0 when the generation was retired meanwhile.
     */
    public const string STORE_DEPENDENTS = <<<'LUA'
        local base, generation, ttl = ARGV[1], ARGV[2], tonumber(ARGV[4])
        if redis.call('GET', base .. 'generation') ~= generation then
            return 0
        end
        local dependentsKey = base .. generation .. ':d:' .. ARGV[3]
        redis.call('DEL', dependentsKey)
        redis.call('HSET', dependentsKey, '', '')
        for i = 5, #ARGV, 2 do
            redis.call('HSET', dependentsKey, ARGV[i], ARGV[i + 1])
        end
        if ttl > 0 then
            redis.call('EXPIRE', dependentsKey, ttl)
        end
        return 1
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
     * Deletes one SCAN batch of keys that belong to no current generation, keeping the server id.
     * Returns {next cursor, deleted count}.
     */
    public const string PRUNE_BATCH = <<<'LUA'
        local base = ARGV[1]
        local pattern = string.gsub(base, '([%*%?%[%]\\])', '\\%1') .. '*'
        local generationKey = base .. 'generation'
        local generation = redis.call('GET', generationKey)
        local currentPrefix = generation and (base .. generation .. ':') or nil
        local scan = redis.call('SCAN', ARGV[2], 'MATCH', pattern, 'COUNT', ARGV[3])
        local stale = {}
        for _, key in ipairs(scan[2]) do
            local current = currentPrefix ~= nil and string.sub(key, 1, #currentPrefix) == currentPrefix
            if key ~= generationKey and key ~= base .. 'server' and not current then
                stale[#stale + 1] = key
            end
        end
        if #stale > 0 then
            redis.call('UNLINK', unpack(stale))
        end
        return {scan[1], #stale}
        LUA;
}
