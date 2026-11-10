<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use RuntimeException;
use Spiritix\LadaCache\Support\Sql\DeleteStatement;
use Spiritix\LadaCache\Support\Sql\Dialects;
use Spiritix\LadaCache\Support\Sql\InsertStatement;
use Spiritix\LadaCache\Support\Sql\MergeStatement;
use Spiritix\LadaCache\Support\Sql\SQLiteDialect;
use Spiritix\LadaCache\Support\Sql\Token;
use Spiritix\LadaCache\Support\Sql\Tokenizer;
use Spiritix\LadaCache\Support\Sql\TruncateStatement;
use Spiritix\LadaCache\Support\Sql\UpdateStatement;
use Spiritix\LadaCache\Support\Sql\WriteTargets;
use UnexpectedValueException;

/**
 * Tables the database changes along with a written table, so their cached reads are
 * invalidated too: views over the table, tables its triggers write and
 * `lada-cache.dependent_tables` change with every write, while foreign keys fire their delete
 * rules on deletes and their update rules on updates that assign a referenced column. Writing an
 * updatable view writes its base tables. A changed table changes its own dependents in turn:
 * a cascading delete deletes the child rows, while a cascading or nulling update, and a nulling
 * delete, only assign the child's foreign key columns. A truncate empties every referencing
 * table whatever its rules, as PostgreSQL's `TRUNCATE ... CASCADE` does. Tables reached through
 * views, triggers or configuration are written in ways not known, so the dependents any delete or
 * update of them changes change.
 *
 * @phpstan-type ForeignKey array{child: string, parentColumns: list<string>, childColumns: list<string>, onDelete: ReferentialAction, onUpdate: ReferentialAction}
 * @phpstan-type Change array{0: 'any'|'insert'|'delete'|'truncate', 1: string}|array{0: 'update', 1: string, 2: string}
 * @phpstan-type Trigger array{events: list<TriggerEvent>, columns: list<string>|null, changes: list<Change>}
 */
final class DependentTables
{
    /**
     * Part of the name of the dependency map in Redis, so hosts reading another field layout
     * keep their own map.
     */
    public const int LAYOUT = 5;

    /**
     * @return array<string, list<string>> `Write` dependents field => tables changed along with it
     */
    public static function load(Connection $connection): array
    {
        $prefix = $connection->getTablePrefix();
        $everyWrite = self::configured();
        $viewBases = [];

        foreach (self::viewDependencies($connection) as [$table, $view]) {
            $table = TableName::withoutPrefix($table, $prefix);
            $view = TableName::withoutPrefix($view, $prefix);
            $everyWrite[$table][$view] = true;
            $viewBases[$view][$table] = true;
        }

        return self::fields($everyWrite, $viewBases, self::foreignKeys($connection, $prefix), self::triggers($connection, $prefix));
    }

    /**
     * @return array<string, array<string, true>>
     */
    public static function configured(): array
    {
        $configured = config('lada-cache.dependent_tables', []);

        if (! is_array($configured)) {
            throw self::invalidConfiguration();
        }

        $direct = [];
        foreach ($configured as $table => $dependents) {
            if (! is_string($table) || ! is_array($dependents)) {
                throw self::invalidConfiguration();
            }

            foreach ($dependents as $dependent) {
                if (! is_string($dependent)) {
                    throw self::invalidConfiguration();
                }

                $direct[$table][$dependent] = true;
            }
        }

        return $direct;
    }

    /**
     * @return array<string, list<ForeignKey>> parent table => foreign keys referencing it
     */
    private static function foreignKeys(Connection $connection, string $prefix): array
    {
        $constraints = [];

        foreach ($connection->select(self::foreignKeyQuery($connection), [], false) as $row) {
            $row = (array) $row;
            $child = TableName::withoutPrefix(self::text($row, 'child_table'), $prefix);
            $parent = TableName::withoutPrefix(self::text($row, 'parent_table'), $prefix);
            $childColumn = self::text($row, 'child_column');

            if ($row['parent_column'] === null) {
                throw new RuntimeException("Lada Cache cannot tell which column of {$parent} the foreign key of {$child} on {$childColumn} references");
            }

            $id = $child."\0".self::text($row, 'constraint_id');
            $constraints[$id] ??= [
                'parent' => $parent,
                'child' => $child,
                'parentColumns' => [],
                'childColumns' => [],
                'onDelete' => ReferentialAction::parse(self::text($row, 'delete_rule')),
                'onUpdate' => ReferentialAction::parse(self::text($row, 'update_rule')),
            ];
            $constraints[$id]['parentColumns'][] = strtolower(self::text($row, 'parent_column'));
            $constraints[$id]['childColumns'][] = strtolower($childColumn);
        }

        ksort($constraints, SORT_STRING);

        $byParent = [];
        foreach ($constraints as $constraint) {
            $parent = $constraint['parent'];
            unset($constraint['parent']);
            $byParent[$parent][] = $constraint;
        }

        return $byParent;
    }

    private static function invalidConfiguration(): InvalidArgumentException
    {
        return new InvalidArgumentException('Invalid lada-cache.dependent_tables, expected an array of table => list of tables');
    }

    /**
     * @param  array<mixed>  $row
     */
    private static function text(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            throw new UnexpectedValueException("Lada Cache read {$column} of the schema as ".get_debug_type($value).', expected text');
        }

        return (string) $value;
    }

    /**
     * One row per referencing column, with an id shared by the columns of one constraint.
     */
    private static function foreignKeyQuery(Connection $connection): string
    {
        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => 'select r.constraint_name as constraint_id, k.table_name as child_table, k.referenced_table_name as parent_table,'
                .' k.column_name as child_column, k.referenced_column_name as parent_column, r.delete_rule as delete_rule, r.update_rule as update_rule'
                .' from information_schema.referential_constraints r join information_schema.key_column_usage k'
                .' on k.constraint_schema = r.constraint_schema and k.constraint_name = r.constraint_name and k.table_name = r.table_name'
                .' where r.constraint_schema = database() order by k.ordinal_position',
            'pgsql' => 'select c.oid as constraint_id, child.relname as child_table, parent.relname as parent_table,'
                .' child_column.attname as child_column, parent_column.attname as parent_column, c.confdeltype as delete_rule, c.confupdtype as update_rule'
                .' from pg_constraint c join pg_class child on child.oid = c.conrelid join pg_class parent on parent.oid = c.confrelid'
                .' join pg_namespace n on n.oid = child.relnamespace'
                .' cross join lateral unnest(c.conkey, c.confkey) with ordinality as k(child_attnum, parent_attnum, position)'
                .' join pg_attribute child_column on child_column.attrelid = c.conrelid and child_column.attnum = k.child_attnum'
                .' join pg_attribute parent_column on parent_column.attrelid = c.confrelid and parent_column.attnum = k.parent_attnum'
                ." where c.contype = 'f' and n.nspname = any(current_schemas(false)) order by k.position",
            'sqlite' => 'select p.id as constraint_id, m.name as child_table, p."table" as parent_table, p."from" as child_column,'
                .' coalesce(p."to", (select t.name from pragma_table_info(p."table") t where t.pk = p.seq + 1)) as parent_column,'
                .' p.on_delete as delete_rule, p.on_update as update_rule'
                ." from sqlite_master m join pragma_foreign_key_list(m.name) p where m.type = 'table' order by p.seq",
            'sqlsrv' => 'select fk.object_id as constraint_id, object_name(fk.parent_object_id) as child_table, object_name(fk.referenced_object_id) as parent_table,'
                .' child_column.name as child_column, parent_column.name as parent_column,'
                .' fk.delete_referential_action_desc as delete_rule, fk.update_referential_action_desc as update_rule'
                .' from sys.foreign_keys fk join sys.foreign_key_columns fkc on fkc.constraint_object_id = fk.object_id'
                .' join sys.columns child_column on child_column.object_id = fkc.parent_object_id and child_column.column_id = fkc.parent_column_id'
                .' join sys.columns parent_column on parent_column.object_id = fkc.referenced_object_id and parent_column.column_id = fkc.referenced_column_id'
                .' order by fkc.constraint_column_id',
            default => throw new RuntimeException('Lada Cache cannot read foreign keys of driver '.$connection->getDriverName()),
        };
    }

    /**
     * @return list<array{string, string}> pairs of base table and the view reading it
     */
    private static function viewDependencies(Connection $connection): array
    {
        if ($connection->getDriverName() === 'sqlite') {
            $pairs = [];
            foreach (self::rows($connection, "select name, sql from sqlite_master where type = 'view'") as $view) {
                foreach (SqlTableScanner::tables(self::text($view, 'sql'), '', Dialects::forDriver('sqlite')) as $table) {
                    $pairs[] = [TableName::unqualified($table), self::text($view, 'name')];
                }
            }

            return $pairs;
        }

        $query = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => 'select table_name as base_table, view_name as view_name from information_schema.view_table_usage where view_schema = database()',
            'pgsql' => 'select distinct t.relname as base_table, v.relname as view_name from pg_depend d'
                .' join pg_rewrite r on r.oid = d.objid join pg_class v on v.oid = r.ev_class join pg_class t on t.oid = d.refobjid'
                .' join pg_namespace n on n.oid = v.relnamespace'
                ." where d.classid = 'pg_rewrite'::regclass and d.refclassid = 'pg_class'::regclass and v.oid <> t.oid"
                ." and v.relkind = 'v' and n.nspname = any(current_schemas(false))",
            'sqlsrv' => 'select distinct d.referenced_entity_name as base_table, object_name(d.referencing_id) as view_name'
                .' from sys.sql_expression_dependencies d join sys.views v on v.object_id = d.referencing_id',
            default => throw new RuntimeException('Lada Cache cannot read view dependencies of driver '.$connection->getDriverName()),
        };

        $pairs = [];
        foreach (self::rows($connection, $query) as $row) {
            $pairs[] = [self::text($row, 'base_table'), self::text($row, 'view_name')];
        }

        return $pairs;
    }

    /**
     * The triggers of every table: the events they fire on, the columns an `UPDATE OF` trigger
     * is limited to, and how their bodies write each table, read from the parsed statements.
     *
     * @return array<string, list<Trigger>>
     */
    private static function triggers(Connection $connection, string $prefix): array
    {
        $dialect = Dialects::of($connection);
        $triggers = [];

        foreach (self::triggerDefinitions($connection) as [$table, $events, $columns, $body]) {
            $changes = [];
            foreach (SqlTableScanner::writeStatements($body, $dialect) as $statement) {
                array_push($changes, ...self::changesOfStatement($statement, $prefix));
            }

            $triggers[TableName::withoutPrefix($table, $prefix)][] = ['events' => $events, 'columns' => $columns, 'changes' => $changes];
        }

        return $triggers;
    }

    /**
     * @return list<Change>
     */
    private static function changesOfStatement(InsertStatement|UpdateStatement|DeleteStatement|TruncateStatement|MergeStatement $statement, string $prefix): array
    {
        $changes = [];

        foreach (WriteTargets::of($statement) as $name) {
            $table = TableName::withoutPrefix($name[array_key_last($name)], $prefix);
            $columns = $statement instanceof InsertStatement || $statement instanceof UpdateStatement ? WriteTargets::assignedColumns($statement) ?? [] : [];
            $updates = array_map(static fn (string $column): array => ['update', $table, Write::columnName($column)], $columns);

            array_push($changes, ...match (true) {
                $statement instanceof InsertStatement => [['insert', $table], ...($statement->replacesRows ? [['delete', $table]] : []), ...$updates],
                $statement instanceof UpdateStatement => $updates,
                $statement instanceof DeleteStatement => [['delete', $table]],
                $statement instanceof TruncateStatement => [['truncate', $table]],
                $statement instanceof MergeStatement => [['any', $table]],
            });
        }

        return $changes;
    }

    /**
     * @return list<array{string, list<TriggerEvent>, list<string>|null, string}> table, events, `UPDATE OF` columns (null for any) and body of each trigger
     */
    private static function triggerDefinitions(Connection $connection): array
    {
        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => array_map(
                static fn (array $row): array => [self::text($row, 'table_name'), [self::event(self::text($row, 'event'))], null, self::text($row, 'body')],
                self::rows($connection, 'select event_object_table as table_name, event_manipulation as event, action_statement as body from information_schema.triggers where trigger_schema = database()'),
            ),
            'pgsql' => array_map(
                static fn (array $row): array => [
                    self::text($row, 'table_name'),
                    self::postgresEvents((int) self::text($row, 'type')),
                    self::text($row, 'columns') === '' ? null : array_map(Write::columnName(...), explode(',', self::text($row, 'columns'))),
                    self::text($row, 'body'),
                ],
                self::rows(
                    $connection,
                    'select c.relname as table_name, t.tgtype as type, p.prosrc as body,'
                    ." coalesce((select string_agg(a.attname, ',') from pg_attribute a where a.attrelid = t.tgrelid and a.attnum = any(t.tgattr)), '') as columns"
                    .' from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid join pg_namespace n on n.oid = c.relnamespace'
                    .' where not t.tgisinternal and n.nspname = any(current_schemas(false))',
                ),
            ),
            'sqlite' => array_map(
                static fn (array $row): array => [self::text($row, 'table_name'), ...self::sqliteEvents(self::text($row, 'body')), self::text($row, 'body')],
                self::rows($connection, "select tbl_name as table_name, sql as body from sqlite_master where type = 'trigger'"),
            ),
            'sqlsrv' => self::sqlServerTriggers($connection),
            default => throw new RuntimeException('Lada Cache cannot read triggers of driver '.$connection->getDriverName()),
        };
    }

    /**
     * @return list<TriggerEvent>
     */
    private static function postgresEvents(int $type): array
    {
        return array_values(array_filter([
            $type & 4 ? TriggerEvent::Insert : null,
            $type & 8 ? TriggerEvent::Delete : null,
            $type & 16 ? TriggerEvent::Update : null,
            $type & 32 ? TriggerEvent::Truncate : null,
        ]));
    }

    /**
     * Reads `CREATE TRIGGER ... [BEFORE|AFTER|INSTEAD OF] DELETE|INSERT|UPDATE [OF columns] ON table`.
     *
     * @return array{list<TriggerEvent>, list<string>|null}
     */
    private static function sqliteEvents(string $sql): array
    {
        $tokens = array_values(array_filter(
            (new Tokenizer(new SQLiteDialect, $sql))->tokenize(),
            static fn (Token $token): bool => ! $token->isTrivia(),
        ));

        foreach ($tokens as $position => $token) {
            if (! $token->isKeyword('INSERT', 'UPDATE', 'DELETE')) {
                continue;
            }

            if (! $token->isKeyword('UPDATE') || ($tokens[$position + 1] ?? null)?->isKeyword('OF') !== true) {
                return [[self::event($token->value)], null];
            }

            $columns = [];
            for ($column = $position + 2; isset($tokens[$column]) && ! $tokens[$column]->isKeyword('ON'); $column++) {
                if ($tokens[$column]->isWord()) {
                    $columns[] = Write::columnName($tokens[$column]->value);
                }
            }

            return [[TriggerEvent::Update], $columns];
        }

        throw new UnexpectedValueException('Lada Cache cannot read the event of trigger '.json_encode($sql));
    }

    /**
     * @return list<array{string, list<TriggerEvent>, null, string}>
     */
    private static function sqlServerTriggers(Connection $connection): array
    {
        $triggers = [];

        foreach (self::rows(
            $connection,
            'select t.object_id as id, object_name(t.parent_id) as table_name, e.type_desc as event, m.definition as body from sys.triggers t'
            .' join sys.trigger_events e on e.object_id = t.object_id join sys.sql_modules m on m.object_id = t.object_id where t.parent_class = 1',
        ) as $row) {
            $id = self::text($row, 'id');
            $triggers[$id] ??= [self::text($row, 'table_name'), [], null, self::text($row, 'body')];
            $triggers[$id][1][] = self::event(self::text($row, 'event'));
        }

        return array_values($triggers);
    }

    private static function event(string $event): TriggerEvent
    {
        return match (strtoupper(trim($event))) {
            'INSERT' => TriggerEvent::Insert,
            'UPDATE' => TriggerEvent::Update,
            'DELETE' => TriggerEvent::Delete,
            'TRUNCATE' => TriggerEvent::Truncate,
            default => throw new UnexpectedValueException('Unknown trigger event '.json_encode($event)),
        };
    }

    /**
     * @return list<array<mixed>>
     */
    private static function rows(Connection $connection, string $query): array
    {
        return array_map(static fn (mixed $row): array => (array) $row, array_values($connection->select($query, [], false)));
    }

    /**
     * @param  array<string, array<string, true>>  $everyWrite  table => tables every write to it changes
     * @param  array<string, array<string, true>>  $viewBases  view => base tables a write through it changes
     * @param  array<string, list<ForeignKey>>  $foreignKeys  parent table => foreign keys referencing it
     * @param  array<string, list<Trigger>>  $triggers  table => its triggers
     * @return array<string, list<string>>
     */
    private static function fields(array $everyWrite, array $viewBases, array $foreignKeys, array $triggers): array
    {
        $graph = ['everyWrite' => $everyWrite, 'foreignKeys' => $foreignKeys, 'triggers' => $triggers];
        $fields = [];
        $cascadeColumns = [];

        foreach (array_keys($everyWrite + $viewBases + $foreignKeys + $triggers) as $table) {
            $table = (string) $table;
            $keys = $foreignKeys[$table] ?? [];
            $tableTriggers = $triggers[$table] ?? [];

            $starts = [
                Write::everyWriteField($table) => array_map(
                    static fn (int|string $dependent): array => ['any', (string) $dependent],
                    array_keys(($everyWrite[$table] ?? []) + ($viewBases[$table] ?? [])),
                ),
                Write::deleteField($table) => self::changesOfDelete($keys),
                Write::truncateField($table) => self::changesOfTruncate($keys),
                Write::anyUpdateField($table) => self::changesOfUpdate($keys, null),
                Write::anyColumnTriggerField($table) => self::triggerChanges($tableTriggers, static fn (array $trigger): bool => in_array(TriggerEvent::Update, $trigger['events'], true) && $trigger['columns'] !== null),
            ];
            foreach (TriggerEvent::cases() as $event) {
                $starts[Write::triggerField($event, $table)] = self::triggerChanges(
                    $tableTriggers,
                    static fn (array $trigger): bool => in_array($event, $trigger['events'], true) && ($event !== TriggerEvent::Update || $trigger['columns'] === null),
                );
            }
            foreach (self::updatedColumns($keys) as $column) {
                $starts[Write::columnUpdateField($table, $column)] = self::changesOfUpdate($keys, $column);
            }
            foreach (self::triggerColumns($tableTriggers) as $column) {
                $starts[Write::columnTriggerField($table, $column)] = self::triggerChanges(
                    $tableTriggers,
                    static fn (array $trigger): bool => in_array(TriggerEvent::Update, $trigger['events'], true) && in_array($column, $trigger['columns'] ?? [], true),
                );
            }

            foreach ($starts as $field => $changes) {
                $changed = self::reachableFrom($changes, $graph);

                if ($changed !== []) {
                    $fields[$field] = $changed;
                }
            }

            foreach (self::preciseEdgesByColumn($keys) as $column => $precise) {
                $others = array_values(array_filter($keys, static fn (array $foreignKey): bool => ! in_array($foreignKey, $precise, true)));
                $beyond = self::changesOfDelete($others);

                foreach ($precise as $foreignKey) {
                    array_push($beyond, ...self::expand(self::preciseChildChange($foreignKey), $graph));
                    $cascadeColumns[$foreignKey['child']][$foreignKey['childColumns'][0]] = true;
                }

                $lines = [
                    ...array_map(static fn (array $foreignKey): string => $foreignKey['child']."\t".$foreignKey['childColumns'][0], $precise),
                    ...self::reachableFrom($beyond, $graph),
                ];
                sort($lines, SORT_STRING);
                $fields[Write::keyedDeleteField($table, $column)] = array_values(array_unique($lines));
            }
        }

        foreach ($cascadeColumns as $child => $columns) {
            $names = array_map('strval', array_keys($columns));
            sort($names, SORT_STRING);
            $fields[Write::cascadeColumnsField((string) $child)] = $names;
        }

        ksort($fields, SORT_STRING);

        return $fields;
    }

    /**
     * The foreign keys referencing each single column of the table whose deletes change only the
     * child rows referencing the deleted values: single-column keys that cascade or set NULL. A
     * SET DEFAULT key moves its rows to the default value, which reads may pin, so it is not.
     *
     * @param  list<ForeignKey>  $foreignKeys
     * @return array<string, non-empty-list<ForeignKey>>
     */
    private static function preciseEdgesByColumn(array $foreignKeys): array
    {
        $edges = [];

        foreach ($foreignKeys as $foreignKey) {
            if (count($foreignKey['parentColumns']) === 1 && count($foreignKey['childColumns']) === 1
                && in_array($foreignKey['onDelete'], [ReferentialAction::Cascades, ReferentialAction::SetsNull], true)) {
                $edges[$foreignKey['parentColumns'][0]][] = $foreignKey;
            }
        }

        return $edges;
    }

    /**
     * @param  ForeignKey  $foreignKey
     * @return Change
     */
    private static function preciseChildChange(array $foreignKey): array
    {
        return $foreignKey['onDelete'] === ReferentialAction::Cascades
            ? ['delete', $foreignKey['child']]
            : ['update', $foreignKey['child'], $foreignKey['childColumns'][0]];
    }

    /**
     * Tables changed when the start changes are made, following each change to what it fires:
     * the views and configured dependents of every changed table, the triggers of its event and
     * the foreign key rules of its rows.
     *
     * @param  list<Change>  $start
     * @param  array{everyWrite: array<string, array<string, true>>, foreignKeys: array<string, list<ForeignKey>>, triggers: array<string, list<Trigger>>}  $graph
     * @return list<string>
     */
    private static function reachableFrom(array $start, array $graph): array
    {
        $tables = [];
        $seen = [];
        $queue = $start;

        while ($queue !== []) {
            $change = array_shift($queue);
            $id = implode("\0", $change);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $tables[$change[1]] = true;
            array_push($queue, ...self::expand($change, $graph));
        }

        $names = array_map('strval', array_keys($tables));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The changes a change to a table makes in turn: its views and configured dependents, the
     * triggers of its kind of change and the foreign key rules of its rows.
     *
     * @param  Change  $change
     * @param  array{everyWrite: array<string, array<string, true>>, foreignKeys: array<string, list<ForeignKey>>, triggers: array<string, list<Trigger>>}  $graph
     * @return list<Change>
     */
    private static function expand(array $change, array $graph): array
    {
        [$kind, $table] = $change;
        $keys = $graph['foreignKeys'][$table] ?? [];
        $triggers = $graph['triggers'][$table] ?? [];
        $column = $change[2] ?? null;
        $changes = array_map(static fn (int|string $dependent): array => ['any', (string) $dependent], array_keys($graph['everyWrite'][$table] ?? []));

        array_push($changes, ...match ($kind) {
            'any' => [...self::triggerChanges($triggers, static fn (): bool => true), ...self::changesOfDelete($keys), ...self::changesOfUpdate($keys, null)],
            'insert' => self::triggerChanges($triggers, static fn (array $trigger): bool => in_array(TriggerEvent::Insert, $trigger['events'], true)),
            'delete' => [...self::triggerChanges($triggers, static fn (array $trigger): bool => in_array(TriggerEvent::Delete, $trigger['events'], true)), ...self::changesOfDelete($keys)],
            'truncate' => [
                ...self::triggerChanges($triggers, static fn (array $trigger): bool => in_array(TriggerEvent::Truncate, $trigger['events'], true) || in_array(TriggerEvent::Delete, $trigger['events'], true)),
                ...self::changesOfTruncate($keys),
            ],
            'update' => [
                ...self::triggerChanges($triggers, static fn (array $trigger): bool => in_array(TriggerEvent::Update, $trigger['events'], true) && ($trigger['columns'] === null || in_array($column, $trigger['columns'], true))),
                ...self::changesOfUpdate($keys, $column),
            ],
        });

        return $changes;
    }

    /**
     * @param  list<Trigger>  $triggers
     * @param  callable(Trigger): bool  $fires
     * @return list<Change>
     */
    private static function triggerChanges(array $triggers, callable $fires): array
    {
        $changes = [];
        foreach ($triggers as $trigger) {
            if ($fires($trigger)) {
                array_push($changes, ...$trigger['changes']);
            }
        }

        return $changes;
    }

    /**
     * @param  list<Trigger>  $triggers
     * @return list<string>
     */
    private static function triggerColumns(array $triggers): array
    {
        $columns = [];
        foreach ($triggers as $trigger) {
            $columns += array_fill_keys($trigger['columns'] ?? [], true);
        }

        return array_map('strval', array_keys($columns));
    }

    /**
     * @param  list<ForeignKey>  $foreignKeys
     * @return list<string>
     */
    private static function updatedColumns(array $foreignKeys): array
    {
        $columns = [];
        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey['onUpdate'] !== ReferentialAction::KeepsRows) {
                $columns += array_fill_keys($foreignKey['parentColumns'], true);
            }
        }

        return array_map('strval', array_keys($columns));
    }

    /**
     * @param  list<ForeignKey>  $foreignKeys
     * @return list<Change>
     */
    private static function changesOfDelete(array $foreignKeys): array
    {
        $changes = [];
        foreach ($foreignKeys as $foreignKey) {
            array_push($changes, ...match ($foreignKey['onDelete']) {
                ReferentialAction::Cascades => [['delete', $foreignKey['child']]],
                ReferentialAction::SetsNull, ReferentialAction::SetsDefault => self::columnUpdates($foreignKey),
                ReferentialAction::KeepsRows => [],
            });
        }

        return $changes;
    }

    /**
     * @param  list<ForeignKey>  $foreignKeys
     * @return list<Change>
     */
    private static function changesOfTruncate(array $foreignKeys): array
    {
        return array_map(static fn (array $foreignKey): array => ['truncate', $foreignKey['child']], $foreignKeys);
    }

    /**
     * @param  list<ForeignKey>  $foreignKeys
     * @param  string|null  $column  the assigned column, null for any
     * @return list<Change>
     */
    private static function changesOfUpdate(array $foreignKeys, ?string $column): array
    {
        $changes = [];
        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey['onUpdate'] === ReferentialAction::KeepsRows || ($column !== null && ! in_array($column, $foreignKey['parentColumns'], true))) {
                continue;
            }

            array_push($changes, ...self::columnUpdates($foreignKey));
        }

        return $changes;
    }

    /**
     * @param  ForeignKey  $foreignKey
     * @return list<Change>
     */
    private static function columnUpdates(array $foreignKey): array
    {
        return array_map(static fn (string $column): array => ['update', $foreignKey['child'], $column], $foreignKey['childColumns']);
    }
}
