<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use ReflectionClass;
use Spiritix\LadaCache\Support\ColumnKeys;
use Spiritix\LadaCache\Support\ColumnType;
use Spiritix\LadaCache\Support\DependentTables;
use Spiritix\LadaCache\Support\Metric;
use Spiritix\LadaCache\Support\RetireReason;
use Spiritix\LadaCache\Support\Write;

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
 *                                       named by database and dependency configuration; `Write::ANY_TABLE_FIELD` is always
 *                                       present and lists `DependentTables::EVERY_TABLE` when the schema could not be read
 * - `<base>stats:<bucket start>`        with metrics, a hash of counters for one time bucket (see `Metrics`)
 * - `<base>legacy:<tag>`                with legacy invalidation, a token listed in the 6.x tag set `<base><tag>`,
 *                                       so a 6.x host invalidating the tag deletes it
 *
 * An entry is valid only while the fingerprint of its tags' current tokens matches the one
 * captured before the query ran, so a result read before a concurrent write is never served.
 */
final class LuaScripts
{
    /**
     * The name of the constant holding the script, for messages about it.
     */
    public static function nameOf(string $script): string
    {
        $counting = array_search($script, self::COUNTING, true);
        $name = array_search($counting === false ? $script : $counting, (new ReflectionClass(self::class))->getConstants(), true);

        return is_string($name) ? $name : 'unknown';
    }

    /**
     * The scripts that count, by the script they are with metrics off. Both forms share their
     * body; they differ in what count() does and in the arguments they take.
     */
    public const array COUNTING = [
        self::LOOKUP => self::METRICS.self::LOOKUP_BODY,
        self::STORE => self::METRICS.self::STORE_BODY,
        self::INVALIDATE => self::METRICS.self::INVALIDATE_BODY,
        self::RETIRE => self::METRICS.self::RETIRE_BODY,
    ];

    /**
     * Defines the names of the metrics the scripts count and of the labels they count under.
     */
    private const string METRIC_NAMES = "local statsPrefix, noTable, severalTables = '".Metrics::BUCKET_PREFIX."', '".Metric::NO_TABLE."', '".Metric::SEVERAL_TABLES."'\n"
        ."local metricHit, metricMiss, metricStore = '".Metric::Hit->value."', '".Metric::Miss->value."', '".Metric::Store->value."'\n"
        ."local metricInvalidation, metricCascade, metricRetire = '".Metric::Invalidation->value."', '".Metric::Cascade->value."', '".Metric::Retire->value."'\n"
        ."local retiredByServerChange, retiredForUnknownTables = '".RetireReason::ServerChange->value."', '".RetireReason::UnknownTables->value."'\n"
        ."local everyWritePrefix = '".Write::EVERY_WRITE_PREFIX."'\n";

    /**
     * What a script starts with when metrics are off: count() does nothing, and the script
     * takes exactly the arguments it took before metrics existed. `lastArgument` is the index of
     * its last argument.
     */
    private const string NO_METRICS = self::METRIC_NAMES.<<<'LUA'
        local statsLabel = ''
        local lastArgument = #ARGV
        local function count()
        end

        LUA;

    /**
     * What a script starts with when metrics are on. Defines count(metric, label, by) and
     * countField(field, by), which add to a counter of the current metrics bucket (see
     * `Metrics`). The script's last three arguments are `Metrics::scriptArguments()`, and
     * `lastArgument` is the index of the last one before them. A bucket gets its expiration
     * when a counter is first written to it.
     */
    private const string METRICS = self::METRIC_NAMES.<<<'LUA'
        local statsBucket, statsLabel, statsRetention = ARGV[#ARGV - 2], ARGV[#ARGV - 1], ARGV[#ARGV]
        local lastArgument = #ARGV - 3
        local function countField(field, by)
            if by == 0 then
                return
            end
            local key = ARGV[1] .. statsPrefix .. statsBucket
            if redis.call('HINCRBY', key, field, by) == by then
                redis.call('EXPIRE', key, statsRetention)
            end
        end
        local function count(metric, label, by)
            countField(metric .. ':' .. (label == '' and noTable or label), by)
        end

        LUA;

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
            count(metricRetire, retiredByServerChange, 1)
            return true
        end

        LUA;

    /**
     * Defines the names `DependentTables` and `Write` give the parts of a dependency map the
     * scripts read: the table standing for every table, and the field every stored map has,
     * which lists that table when the schema could not be read. Also defines
     * readColumnKeys(index), which reads `ColumnKeys::arguments()` starting at the index and
     * returns, per `ColumnType` the values are keyed for, the first and last index of its keys,
     * and the index after them.
     */
    private const string DEPENDENTS_NAMES = "local everyTable = '".DependentTables::EVERY_TABLE."'\nlocal anyTableField = '".Write::ANY_TABLE_FIELD."'\n"
        ."local columnTypes = {'".ColumnType::Integer->value."', '".ColumnType::Decimal->value."', '".ColumnType::Text->value."', '".ColumnType::Uuid->value."'}\n"
        .'local keysAsBefore = '.ColumnKeys::AS_BEFORE."\n".<<<'LUA'
        local function readColumnKeys(index)
            local keys = {}
            local before
            for _, columnType in ipairs(columnTypes) do
                local count = tonumber(ARGV[index])
                index = index + 1
                if count == keysAsBefore then
                    keys[columnType] = before
                elseif count >= 0 then
                    keys[columnType] = {index, index + count - 1}
                    index = index + count
                end
                before = keys[columnType]
            end
            return keys, index
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
     * ARGV: base, entry hash, fresh token, ttl seconds, Lada prefix, 1 to check the server else 0, tag count, tags...,
     *       cascade read count, then per table read: dependents name, cascade tag prefix, cascade
     *       columns field, pinned column count, then per column: column, `ColumnKeys::arguments()`,
     *       then legacy tags...
     * Returns {generation, fingerprint} on a miss and {generation, fingerprint, payload} on a hit.
     *
     * Asked to check the server, it first retires the generation of another server run (see
     * `SERVER_CHECK`). Reading the run id costs more than the rest of a hit, so `Cache` asks for
     * it only every few seconds per process rather than on every lookup.
     *
     * A table read also takes the tokens of the cascade tags that row-precise foreign key cascades
     * into it replace (see `CASCADE_TAGS`): for each column the dependency map lists in the table's
     * cascade columns field (`Write::cascadeColumnsField()`),
     * the value tags of the keys the read pins it to, keyed for the column's type, or its `via` tag
     * when it pins none or values that type cannot key; without a
     * readable dependency map, the `any` tag. A table no row-precise cascade reaches takes none.
     * The tokens of all tags are read with one MGET, in the order the tags are named.
     *
     * Legacy tags are only passed with legacy invalidation on (see `Tagger::legacyDependencies()`);
     * each contributes the token of `<base>legacy:<tag>` to the fingerprint. That key is listed in the tag's 6.x tag set, which a 6.x host invalidating the
     * tag deletes along with every key it lists, so the fingerprint changes and entries this
     * version stored before the 6.x write become unreachable. A token whose key is missing or no
     * longer listed in the set, because the 6.x host deleted the set but not yet its keys, is replaced.
     */
    public const string LOOKUP = self::NO_METRICS.self::LOOKUP_BODY;

    private const string LOOKUP_BODY = self::SERVER_CHECK.self::DEPENDENTS_NAMES.self::CASCADE_TAGS.<<<'LUA'
        local base, fresh, ttl = ARGV[1], ARGV[3], tonumber(ARGV[4])
        local connectionPrefix = string.sub(base, 1, #base - #ARGV[5])
        local lastVersionTag = 7 + tonumber(ARGV[7])
        local generationKey = base .. 'generation'
        if ARGV[6] == '1' then
            retireIfServerChanged(base)
        end
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
        local versionKeys = {}
        local function takeToken(tag)
            versionKeys[#versionKeys + 1] = scope .. 'v:' .. tag
        end
        for i = 8, lastVersionTag do
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
                pins[column], index = readColumnKeys(index + 1)
            end
            local map = redis.call('HMGET', dependentsKey, anyTableField, columnsField)
            if not map[1] or map[1] == everyTable then
                takeToken(cascadeAny(prefix))
            else
                local columns = map[2]
                if columns then
                    for line in string.gmatch(columns, '[^\n]+') do
                        local column, columnType = string.match(line, '^(.-)\t(.+)$')
                        local keys = pins[column] and pins[column][columnType]
                        if keys then
                            for k = keys[1], keys[2] do
                                takeToken(cascadeValue(prefix, column, ARGV[k]))
                            end
                        else
                            takeToken(cascadeVia(prefix, column))
                        end
                    end
                end
            end
        end
        local tokens = {}
        for first = 1, #versionKeys, 1000 do
            local last = math.min(first + 999, #versionKeys)
            local found = redis.call('MGET', unpack(versionKeys, first, last))
            for i = first, last do
                local token = found[i - first + 1]
                if not token then
                    token = fresh
                    setToken(versionKeys[i], token)
                end
                tokens[i] = token
            end
        end
        for i = index, lastArgument do
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
            count(metricHit, statsLabel, 1)
            return {generation, fingerprint, string.sub(entry, 41)}
        end
        count(metricMiss, statsLabel, 1)
        return {generation, fingerprint}
        LUA;

    /**
     * ARGV: base, generation, entry hash, fingerprint, ttl seconds, payload, tags read from a replica...
     * Returns 1 when stored, 0 when the generation was retired meanwhile and -1 when one of the
     * tags was written too recently for a replica read to be trusted.
     */
    public const string STORE = self::NO_METRICS.self::STORE_BODY;

    private const string STORE_BODY = self::SERVER_CHECK.<<<'LUA'
        local base, generation, ttl = ARGV[1], ARGV[2], tonumber(ARGV[5])
        if retireIfServerChanged(base) then
            return 0
        end
        if redis.call('GET', base .. 'generation') ~= generation then
            return 0
        end
        local scope = base .. generation .. ':'
        for i = 7, lastArgument do
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
        count(metricStore, statsLabel, 1)
        return 1
        LUA;

    /**
     * ARGV: base, fresh token, ttl seconds, replica lag seconds, 1 with legacy invalidation else 0, Lada prefix, tag count, tags...,
     *       group count, then per group: dependents name, prefix count, prefixes..., field count, dependents fields (see `Write`)...,
     *       cascade tag prefix, keyed field count, then per keyed field: field, fallback field, `ColumnKeys::arguments()`
     * A keyed field lists, besides tables changed table-wide, row-precise foreign key edges as
     * `<child>\t<column>\t<ColumnType>`: for those only the cascade tags of the child naming the
     * keys are replaced (see `CASCADE_TAGS`), or the child table-wide when the values cannot be
     * keyed for the column's type. Without the keyed field, its fallback is used table-wide.
     * Replaces the tokens of the tags, and of every table-wide tag of the tables the database
     * changes along with each group's tables. A group changing `DependentTables::EVERY_TABLE`,
     * through one of its fields or because the schema could not be read, changes tables no tag
     * names, so the generation is retired instead; its dependency map, which only a schema
     * change outdates, moves to the fresh generation.
     *
     * Counts an invalidation for every table written, told by its `Write::EVERY_WRITE_PREFIX`
     * field, unless the call only follows up the cascades of one already counted (it replaces no
     * tags), and the tables the cascades reached.
     *
     * With legacy invalidation, every table-level tag replaced also has its 6.x tag set and the
     * entries it lists deleted, so hosts still running 6.x during a rolling deploy stop serving
     * what this version invalidated, cascades included; a row-precise cascade deletes the 6.x
     * entries of the whole child table, which 6.x cannot narrow. 6.x stores each entry in its tag
     * sets with the Lada prefix but without the client's connection prefix. A generation is
     * started when there is none, so the cascades of the first write are still looked up.
     * Returns {0} when
     * nothing was ever cached or is anymore, else
     * {generation, 1-based indexes of the groups whose dependents are not known yet...}.
     */
    public const string INVALIDATE = self::NO_METRICS.self::INVALIDATE_BODY;

    private const string INVALIDATE_BODY = self::SERVER_CHECK.self::DEPENDENTS_NAMES.self::CASCADE_TAGS.<<<'LUA'
        local base, fresh, ttl, lag = ARGV[1], ARGV[2], tonumber(ARGV[3]), tonumber(ARGV[4])
        local legacy = ARGV[5] == '1'
        local connectionPrefix = string.sub(base, 1, #base - #ARGV[6])
        if retireIfServerChanged(base) and not legacy then
            return {0}
        end
        local generation = redis.call('GET', base .. 'generation')
        if not generation then
            if not legacy then
                return {0}
            end
            generation = fresh
            redis.call('SET', base .. 'generation', generation)
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
        local function purgeLegacy(tag)
            local tagKey = base .. tag
            if not legacy or redis.call('TYPE', tagKey)['ok'] ~= 'set' then
                return
            end
            local entries = redis.call('SMEMBERS', tagKey)
            for first = 1, #entries, 1000 do
                local keys = {}
                for i = first, math.min(first + 999, #entries) do
                    keys[#keys + 1] = connectionPrefix .. entries[i]
                end
                redis.call('UNLINK', unpack(keys))
            end
            redis.call('UNLINK', tagKey)
        end
        local index = 7
        local tagCount = tonumber(ARGV[index])
        index = index + 1
        for _ = 1, tagCount do
            replace(ARGV[index])
            purgeLegacy(ARGV[index])
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
            local listed = redis.call('HMGET', dependentsKey, anyTableField, unpack(ARGV, index, index + fieldCount - 1))
            local known = listed[1] ~= false
            if not known then
                result[#result + 1] = group
            end
            local changed = {}
            for _, dependents in ipairs(listed) do
                if dependents then
                    for dependent in string.gmatch(dependents, '[^\n]+') do
                        changed[dependent] = true
                    end
                end
            end
            local written = {}
            for f = index, index + fieldCount - 1 do
                if string.sub(ARGV[f], 1, #everyWritePrefix) == everyWritePrefix then
                    written[#written + 1] = string.sub(ARGV[f], #everyWritePrefix + 1)
                end
            end
            local cascaded = 0
            index = index + fieldCount
            local cascadePrefix = ARGV[index]
            local keyedCount = tonumber(ARGV[index + 1])
            index = index + 2
            for _ = 1, keyedCount do
                local field, fallback = ARGV[index], ARGV[index + 1]
                local keysByType
                keysByType, index = readColumnKeys(index + 2)
                if known then
                    local dependents = redis.call('HGET', dependentsKey, field) or redis.call('HGET', dependentsKey, fallback)
                    if dependents then
                        for line in string.gmatch(dependents, '[^\n]+') do
                            local child, column, columnType = string.match(line, '^(.-)\t(.-)\t(.+)$')
                            local keys = keysByType[columnType]
                            if child and not keys then
                                changed[child] = true
                            elseif child then
                                cascaded = cascaded + 1
                                for p = 1, prefixCount do
                                    purgeLegacy(prefixes[p] .. child)
                                end
                                local prefix = cascadePrefix .. child .. ':'
                                replace(cascadeAny(prefix))
                                replace(cascadeVia(prefix, column))
                                for k = keys[1], keys[2] do
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
                count(metricRetire, retiredForUnknownTables, 1)
                return {0}
            end
            for dependent in pairs(changed) do
                cascaded = cascaded + 1
                for p = 1, prefixCount do
                    replace(prefixes[p] .. dependent)
                    purgeLegacy(prefixes[p] .. dependent)
                end
            end
            if tagCount > 0 then
                for _, tableWritten in ipairs(written) do
                    count(metricInvalidation, tableWritten, 1)
                end
            end
            count(metricCascade, #written == 1 and written[1] or severalTables, cascaded)
        end
        return result
        LUA;

    /**
     * ARGV: base, generation, dependents name, ttl seconds, then pairs of `Write` dependents field and newline separated dependents
     * Returns 1 when stored and 0 when the generation was retired meanwhile.
     */
    public const string STORE_DEPENDENTS = self::DEPENDENTS_NAMES.<<<'LUA'
        local base, generation, ttl = ARGV[1], ARGV[2], tonumber(ARGV[4])
        if redis.call('GET', base .. 'generation') ~= generation then
            return 0
        end
        local dependentsKey = base .. generation .. ':d:' .. ARGV[3]
        redis.call('DEL', dependentsKey)
        redis.call('HSET', dependentsKey, anyTableField, '')
        for i = 5, #ARGV, 2 do
            redis.call('HSET', dependentsKey, ARGV[i], ARGV[i + 1])
        end
        if ttl > 0 then
            redis.call('EXPIRE', dependentsKey, ttl)
        end
        return 1
        LUA;

    /**
     * ARGV: base, fresh generation id; counted under the `RetireReason` passed as the label
     */
    public const string RETIRE = self::NO_METRICS.self::RETIRE_BODY;

    private const string RETIRE_BODY = <<<'LUA'
        redis.call('SET', ARGV[1] .. 'generation', ARGV[2])
        count(metricRetire, statsLabel, 1)
        return 1
        LUA;

    /**
     * ARGV: base, then pairs of a counter field and what to add to it
     */
    public const string COUNT = self::METRICS.<<<'LUA'
        for i = 2, lastArgument, 2 do
            countField(ARGV[i], tonumber(ARGV[i + 1]))
        end
        return 1
        LUA;

    /**
     * ARGV: base, cursor, batch size
     * Deletes one SCAN batch of keys that belong to no current generation, keeping the server id
     * and the metrics buckets.
     * Returns {next cursor, deleted count}.
     */
    public const string PRUNE_BATCH = "local statsPrefix = '".Metrics::BUCKET_PREFIX."'\n".<<<'LUA'
        local base = ARGV[1]
        local pattern = string.gsub(base, '([%*%?%[%]\\])', '\\%1') .. '*'
        local generationKey = base .. 'generation'
        local generation = redis.call('GET', generationKey)
        local currentPrefix = generation and (base .. generation .. ':') or nil
        local scan = redis.call('SCAN', ARGV[2], 'MATCH', pattern, 'COUNT', ARGV[3])
        local stale = {}
        for _, key in ipairs(scan[2]) do
            local current = currentPrefix ~= nil and string.sub(key, 1, #currentPrefix) == currentPrefix
            local stats = string.sub(key, 1, #base + #statsPrefix) == base .. statsPrefix
            if key ~= generationKey and key ~= base .. 'server' and not current and not stats then
                stale[#stale + 1] = key
            end
        end
        if #stale > 0 then
            redis.call('UNLINK', unpack(stale))
        end
        return {scan[1], #stale}
        LUA;
}
