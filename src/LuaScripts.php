<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Spiritix\LadaCache\Support\DependentTables;

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
 * - `<base><generation>:d:<name>`       hash of `Write` dependents field => newline separated tables changed along with it,
 *                                       named by database and dependency configuration
 * - `<base>legacy:<tag>`                with legacy invalidation, a token listed in the 5.x tag set `<base><tag>`,
 *                                       so a 5.x host invalidating the tag deletes it
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
     * Defines the names of the tags foreign key cascades into a table replace, given the table's
     * prefix (`Tagger::cascadeTagPrefix()` followed by the table name and a colon): `any` for
     * reads made while no dependency map was known, `via:<column>` for reads not pinned on a
     * cascade column, and `col:<column>:<key>` for reads pinned on it.
     */
    private const string CASCADE_TAGS = <<<'LUA'
        local function cascadeAny(prefix)
            return prefix .. 'any'
        end
        local function cascadeVia(prefix, column)
            return prefix .. 'via:' .. column
        end
        local function cascadeValue(prefix, column, key)
            return prefix .. 'col:' .. column .. ':' .. key
        end

        LUA;

    /**
     * ARGV: base, entry hash, fresh token, ttl seconds, Lada prefix, tag count, tags...,
     *       cascade read count, then per table read: dependents name, cascade tag prefix, cascade
     *       columns field, pinned column count, then per column: column, key count, keys...,
     *       then legacy tags...
     * Returns {generation, fingerprint} on a miss and {generation, fingerprint, payload} on a hit.
     *
     * A table read also takes the tokens of the cascade tags that row-precise foreign key cascades
     * into it replace (see `CASCADE_TAGS`): for each column the dependency map lists in the table's
     * cascade columns field (`Write::cascadeColumnsField()`),
     * the value tags of the keys the read pins it to, or its `via` tag when it pins none; without a
     * dependency map, the `any` tag. A table no row-precise cascade reaches takes none.
     *
     * Legacy tags are only passed with legacy invalidation on (see `Tagger::legacyDependencies()`);
     * each contributes the token of `<base>legacy:<tag>` to the fingerprint. That key is listed in the tag's 5.x tag set, which a 5.x host invalidating the
     * tag deletes along with every key it lists, so the fingerprint changes and entries this
     * version stored before the 5.x write become unreachable. A token whose key is missing or no
     * longer listed in the set, because the 5.x host deleted the set but not yet its keys, is replaced.
     */
    public const string LOOKUP = self::CASCADE_TAGS.<<<'LUA'
        local base, fresh, ttl = ARGV[1], ARGV[3], tonumber(ARGV[4])
        local connectionPrefix = string.sub(base, 1, #base - #ARGV[5])
        local lastVersionTag = 6 + tonumber(ARGV[6])
        local generationKey = base .. 'generation'
        local generation = redis.call('GET', generationKey)
        if not generation then
            redis.call('SET', generationKey, fresh)
            generation = fresh
        end
        local scope = base .. generation .. ':'
        local function setToken(key, token)
            if ttl > 0 then
                redis.call('SET', key, token, 'EX', ttl)
            else
                redis.call('SET', key, token)
            end
        end
        local tokens = {}
        local function takeToken(tag)
            local versionKey = scope .. 'v:' .. tag
            local token = redis.call('GET', versionKey)
            if not token then
                token = fresh
                setToken(versionKey, token)
            end
            tokens[#tokens + 1] = token
        end
        for i = 7, lastVersionTag do
            takeToken(ARGV[i])
        end
        local index = lastVersionTag + 1
        local cascadeReads = tonumber(ARGV[index])
        index = index + 1
        for _ = 1, cascadeReads do
            local dependentsKey = scope .. 'd:' .. ARGV[index]
            local prefix = ARGV[index + 1]
            local columnsField = ARGV[index + 2]
            local pinCount = tonumber(ARGV[index + 3])
            index = index + 4
            local pins = {}
            for _ = 1, pinCount do
                local column = ARGV[index]
                local keyCount = tonumber(ARGV[index + 1])
                pins[column] = {unpack(ARGV, index + 2, index + 1 + keyCount)}
                index = index + 2 + keyCount
            end
            if redis.call('EXISTS', dependentsKey) == 0 then
                takeToken(cascadeAny(prefix))
            else
                local columns = redis.call('HGET', dependentsKey, columnsField)
                if columns then
                    for column in string.gmatch(columns, '[^\n]+') do
                        local keys = pins[column]
                        if keys then
                            for _, key in ipairs(keys) do
                                takeToken(cascadeValue(prefix, column, key))
                            end
                        else
                            takeToken(cascadeVia(prefix, column))
                        end
                    end
                end
            end
        end
        for i = index, #ARGV do
            local legacyKey = base .. 'legacy:' .. ARGV[i]
            local legacySet = base .. ARGV[i]
            local member = string.sub(legacyKey, #connectionPrefix + 1)
            local legacyToken = redis.call('GET', legacyKey)
            if not legacyToken or redis.call('SISMEMBER', legacySet, member) == 0 then
                legacyToken = fresh
                setToken(legacyKey, legacyToken)
                redis.call('SADD', legacySet, member)
            end
            tokens[#tokens + 1] = 'legacy:' .. legacyToken
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
     *       group count, then per group: dependents name, prefix count, prefixes..., field count, dependents fields (see `Write`)...,
     *       cascade tag prefix, keyed field count, then per keyed field: field, fallback field, key count, keys...
     * A keyed field lists, besides tables changed table-wide, row-precise foreign key edges as
     * `<child>\t<column>`: for those only the cascade tags of the child naming the keys are
     * replaced (see `CASCADE_TAGS`). Without the keyed field, its fallback is used table-wide.
     * Replaces the tokens of the tags, and of every table-wide tag of the tables the database
     * changes along with each group's tables. A group changing `DependentTables::EVERY_TABLE`
     * changes tables no tag names, so the generation is retired instead; its dependency map,
     * which only a schema change outdates, moves to the fresh generation. Returns {0} when
     * nothing was ever cached or is anymore, else
     * {generation, 1-based indexes of the groups whose dependents are not known yet...}.
     */
    public const string INVALIDATE = self::SERVER_CHECK.self::CASCADE_TAGS."local everyTable = '".DependentTables::EVERY_TABLE."'\n".<<<'LUA'
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
            local changed = {}
            if known and fieldCount > 0 then
                for _, dependents in ipairs(redis.call('HMGET', dependentsKey, unpack(ARGV, index, index + fieldCount - 1))) do
                    if dependents then
                        for dependent in string.gmatch(dependents, '[^\n]+') do
                            changed[dependent] = true
                        end
                    end
                end
            end
            index = index + fieldCount
            local cascadePrefix = ARGV[index]
            local keyedCount = tonumber(ARGV[index + 1])
            index = index + 2
            for _ = 1, keyedCount do
                local field, fallback, keyCount = ARGV[index], ARGV[index + 1], tonumber(ARGV[index + 2])
                local keysStart = index + 3
                index = keysStart + keyCount
                if known then
                    local dependents = redis.call('HGET', dependentsKey, field) or redis.call('HGET', dependentsKey, fallback)
                    if dependents then
                        for line in string.gmatch(dependents, '[^\n]+') do
                            local child, column = string.match(line, '^(.-)\t(.+)$')
                            if child then
                                local prefix = cascadePrefix .. child .. ':'
                                replace(cascadeAny(prefix))
                                replace(cascadeVia(prefix, column))
                                for k = keysStart, keysStart + keyCount - 1 do
                                    replace(cascadeValue(prefix, column, ARGV[k]))
                                end
                            else
                                changed[line] = true
                            end
                        end
                    end
                end
            end
            if changed[everyTable] then
                redis.call('SET', base .. 'generation', fresh)
                redis.call('RENAME', dependentsKey, base .. fresh .. ':d:' .. dependentsName)
                return {0}
            end
            for dependent in pairs(changed) do
                for p = 1, prefixCount do
                    replace(prefixes[p] .. dependent)
                end
            end
        end
        return result
        LUA;

    /**
     * ARGV: base, generation, dependents name, ttl seconds, then pairs of `Write` dependents field and newline separated dependents
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
     * ARGV: base, Lada prefix, tag...
     * Deletes the 5.x tag sets of the tags and the entries they list, so hosts still running 5.x
     * during a rolling deploy stop serving results the new version invalidated. 5.x stores each
     * entry in its tag sets with the Lada prefix but without the client's connection prefix.
     */
    public const string PURGE_LEGACY = <<<'LUA'
        local base = ARGV[1]
        local connectionPrefix = string.sub(base, 1, #base - #ARGV[2])
        local deleted = 0
        for i = 3, #ARGV do
            local tagKey = base .. ARGV[i]
            if redis.call('TYPE', tagKey)['ok'] == 'set' then
                local entries = redis.call('SMEMBERS', tagKey)
                if #entries > 0 then
                    local keys = {}
                    for _, entry in ipairs(entries) do
                        keys[#keys + 1] = connectionPrefix .. entry
                    end
                    deleted = deleted + redis.call('UNLINK', unpack(keys))
                end
                deleted = deleted + redis.call('UNLINK', tagKey)
            end
        end
        return deleted
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
