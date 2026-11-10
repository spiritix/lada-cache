<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Tables the database changes along with a written table, so their cached reads are
 * invalidated too: views over the table, tables its triggers write and
 * `lada-cache.dependent_tables` change with every write, while foreign keys fire their delete
 * rules on deletes and their update rules on updates that assign a referenced column. Writing an
 * updatable view writes its base tables. A changed table changes its own dependents in turn:
 * a cascading delete deletes the child rows, while a cascading or nulling update, and a nulling
 * delete, only assign the child's foreign key columns. Tables reached through views, triggers
 * or configuration are written in ways not known, so all of their dependents change.
 *
 * @phpstan-type ForeignKey array{child: string, parentColumns: list<string>, childColumns: list<string>, onDelete: ReferentialAction, onUpdate: ReferentialAction}
 * @phpstan-type Change array{0: 'any'|'delete'|'update', 1: string, 2?: string}
 */
final class DependentTables
{
    /**
     * Part of the name of the dependency map in Redis, so hosts reading another field layout
     * keep their own map.
     */
    public const int LAYOUT = 2;

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

        foreach (self::triggerBodies($connection) as [$table, $body]) {
            foreach (SqlTableScanner::writtenTables($body, $prefix) as $target) {
                $everyWrite[TableName::withoutPrefix($table, $prefix)][TableName::unqualified($target)] = true;
            }
        }

        return self::fields($everyWrite, $viewBases, self::foreignKeys($connection, $prefix));
    }

    /**
     * @return array<string, array<string, true>>
     */
    public static function configured(): array
    {
        $configured = config('lada-cache.dependent_tables', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('Invalid lada-cache.dependent_tables, expected an array of table => list of tables');
        }

        $direct = [];
        foreach ($configured as $table => $dependents) {
            if (! is_string($table) || ! is_array($dependents)) {
                throw new InvalidArgumentException('Invalid lada-cache.dependent_tables, expected an array of table => list of tables');
            }

            foreach ($dependents as $dependent) {
                $direct[$table][(string) $dependent] = true;
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

    /**
     * @param  array<mixed>  $row
     */
    private static function text(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            throw new UnexpectedValueException("Lada Cache read {$column} of a foreign key as ".get_debug_type($value).', expected text');
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
            foreach ($connection->select("select name, sql from sqlite_master where type = 'view'", [], false) as $view) {
                $view = (array) $view;

                foreach (SqlTableScanner::tables((string) $view['sql'], '') as $table) {
                    $pairs[] = [TableName::unqualified($table), (string) $view['name']];
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
        foreach ($connection->select($query, [], false) as $row) {
            $row = (array) $row;
            $pairs[] = [(string) $row['base_table'], (string) $row['view_name']];
        }

        return $pairs;
    }

    /**
     * @return list<array{string, string}> pairs of table and the SQL its triggers run
     */
    private static function triggerBodies(Connection $connection): array
    {
        $query = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => 'select event_object_table as table_name, action_statement as body from information_schema.triggers where trigger_schema = database()',
            'pgsql' => 'select c.relname as table_name, p.prosrc as body from pg_trigger t join pg_class c on c.oid = t.tgrelid'
                .' join pg_proc p on p.oid = t.tgfoid join pg_namespace n on n.oid = c.relnamespace'
                .' where not t.tgisinternal and n.nspname = any(current_schemas(false))',
            'sqlite' => "select tbl_name as table_name, sql as body from sqlite_master where type = 'trigger'",
            'sqlsrv' => 'select object_name(t.parent_id) as table_name, m.definition as body from sys.triggers t'
                .' join sys.sql_modules m on m.object_id = t.object_id where t.parent_class = 1',
            default => throw new RuntimeException('Lada Cache cannot read triggers of driver '.$connection->getDriverName()),
        };

        $pairs = [];
        foreach ($connection->select($query, [], false) as $row) {
            $row = (array) $row;
            $pairs[] = [(string) $row['table_name'], (string) $row['body']];
        }

        return $pairs;
    }

    /**
     * @param  array<string, array<string, true>>  $everyWrite  table => tables every write to it changes
     * @param  array<string, array<string, true>>  $viewBases  view => base tables a write through it changes
     * @param  array<string, list<ForeignKey>>  $foreignKeys  parent table => foreign keys referencing it
     * @return array<string, list<string>>
     */
    private static function fields(array $everyWrite, array $viewBases, array $foreignKeys): array
    {
        $fields = [];

        foreach (array_keys($everyWrite + $viewBases + $foreignKeys) as $table) {
            $table = (string) $table;
            $everyWriteChanges = array_map(
                static fn (int|string $dependent): array => ['any', (string) $dependent],
                array_keys(($everyWrite[$table] ?? []) + ($viewBases[$table] ?? [])),
            );
            $keys = $foreignKeys[$table] ?? [];
            $updatedColumns = [];
            foreach ($keys as $foreignKey) {
                if ($foreignKey['onUpdate'] !== ReferentialAction::KeepsRows) {
                    $updatedColumns += array_fill_keys($foreignKey['parentColumns'], true);
                }
            }

            $starts = [
                Write::everyWriteField($table) => $everyWriteChanges,
                Write::deleteField($table) => [...$everyWriteChanges, ...self::changesOfDelete($keys)],
                Write::anyUpdateField($table) => [...$everyWriteChanges, ...self::changesOfUpdate($keys, null)],
            ];
            foreach (array_keys($updatedColumns) as $column) {
                $starts[Write::columnUpdateField($table, (string) $column)] = [...$everyWriteChanges, ...self::changesOfUpdate($keys, (string) $column)];
            }

            foreach ($starts as $field => $changes) {
                $changed = self::reachableFrom($changes, $everyWrite, $foreignKeys);

                if ($changed !== []) {
                    $fields[$field] = $changed;
                }
            }
        }

        ksort($fields, SORT_STRING);

        return $fields;
    }

    /**
     * Tables changed when the start changes are made, following each change to what it fires.
     *
     * @param  list<Change>  $start
     * @param  array<string, array<string, true>>  $everyWrite
     * @param  array<string, list<ForeignKey>>  $foreignKeys
     * @return list<string>
     */
    private static function reachableFrom(array $start, array $everyWrite, array $foreignKeys): array
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
            [$kind, $table] = $change;
            $tables[$table] = true;
            $keys = $foreignKeys[$table] ?? [];

            foreach (array_keys($everyWrite[$table] ?? []) as $dependent) {
                $queue[] = ['any', (string) $dependent];
            }

            array_push($queue, ...match ($kind) {
                'any' => [...self::changesOfDelete($keys), ...self::changesOfUpdate($keys, null)],
                'delete' => self::changesOfDelete($keys),
                'update' => self::changesOfUpdate($keys, $change[2] ?? null),
            });
        }

        $names = array_map('strval', array_keys($tables));
        sort($names, SORT_STRING);

        return $names;
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
                ReferentialAction::SetsColumns => self::columnUpdates($foreignKey),
                ReferentialAction::KeepsRows => [],
            });
        }

        return $changes;
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
