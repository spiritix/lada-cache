<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Tables the database changes along with a written table, so their cached reads are
 * invalidated too: views over the table and `lada-cache.dependent_tables` (such as trigger
 * targets) change with every write, while foreign keys with cascading or nulling actions
 * only fire on updates and deletes. A changed table changes its own dependents in turn.
 */
final class DependentTables
{
    private const array CHANGING_ACTIONS = ['cascade', 'set null', 'set_null', 'set default', 'set_default', 'c', 'n', 'd'];

    /**
     * @return array<string, list<string>> `WriteKind::dependentsField()` => tables changed along with it
     */
    public static function load(Connection $connection): array
    {
        $prefix = $connection->getTablePrefix();
        $everyWrite = self::configured();
        $modifications = [];

        foreach ($connection->select(self::foreignKeyQuery($connection), [], false) as $foreignKey) {
            $foreignKey = (array) $foreignKey;

            if (! self::changesDependents((string) $foreignKey['delete_rule']) && ! self::changesDependents((string) $foreignKey['update_rule'])) {
                continue;
            }

            $modifications[self::withoutPrefix((string) $foreignKey['parent_table'], $prefix)][self::withoutPrefix((string) $foreignKey['child_table'], $prefix)] = true;
        }

        foreach (self::viewDependencies($connection) as [$table, $view]) {
            $everyWrite[self::withoutPrefix($table, $prefix)][self::withoutPrefix($view, $prefix)] = true;
        }

        return self::closure($everyWrite, $modifications);
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

    private static function foreignKeyQuery(Connection $connection): string
    {
        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => 'select table_name as child_table, referenced_table_name as parent_table, delete_rule as delete_rule, update_rule as update_rule'
                .' from information_schema.referential_constraints where constraint_schema = database()',
            'pgsql' => 'select child.relname as child_table, parent.relname as parent_table, c.confdeltype as delete_rule, c.confupdtype as update_rule'
                .' from pg_constraint c join pg_class child on child.oid = c.conrelid join pg_class parent on parent.oid = c.confrelid'
                .' join pg_namespace n on n.oid = child.relnamespace'
                ." where c.contype = 'f' and n.nspname = any(current_schemas(false))",
            'sqlite' => 'select m.name as child_table, p."table" as parent_table, p.on_delete as delete_rule, p.on_update as update_rule'
                ." from sqlite_master m join pragma_foreign_key_list(m.name) p where m.type = 'table'",
            'sqlsrv' => 'select object_name(parent_object_id) as child_table, object_name(referenced_object_id) as parent_table,'
                .' delete_referential_action_desc as delete_rule, update_referential_action_desc as update_rule from sys.foreign_keys',
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
                    $pairs[] = [$table, (string) $view['name']];
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

    private static function changesDependents(string $action): bool
    {
        return in_array(strtolower(trim($action)), self::CHANGING_ACTIONS, true);
    }

    private static function withoutPrefix(string $table, string $prefix): string
    {
        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }

    /**
     * @param  array<string, array<string, true>>  $everyWrite  table => tables every write to it changes
     * @param  array<string, array<string, true>>  $modifications  table => tables its updates and deletes change
     * @return array<string, list<string>>
     */
    private static function closure(array $everyWrite, array $modifications): array
    {
        $fields = [];

        foreach (array_keys($everyWrite + $modifications) as $table) {
            $table = (string) $table;
            $direct = [
                WriteKind::Insert->value => $everyWrite[$table] ?? [],
                WriteKind::Modify->value => ($everyWrite[$table] ?? []) + ($modifications[$table] ?? []),
            ];

            foreach (WriteKind::cases() as $kind) {
                $changed = self::reachableFrom(array_keys($direct[$kind->value]), $everyWrite, $modifications);

                if ($changed !== []) {
                    $fields[$kind->dependentsField($table)] = $changed;
                }
            }
        }

        ksort($fields, SORT_STRING);

        return $fields;
    }

    /**
     * Tables changed when the start tables change: a changed table is modified, so both its
     * every-write and its modification dependents change as well.
     *
     * @param  list<int|string>  $start
     * @param  array<string, array<string, true>>  $everyWrite
     * @param  array<string, array<string, true>>  $modifications
     * @return list<string>
     */
    private static function reachableFrom(array $start, array $everyWrite, array $modifications): array
    {
        $reached = [];
        $queue = array_map('strval', $start);

        while ($queue !== []) {
            $table = array_shift($queue);

            if (isset($reached[$table])) {
                continue;
            }

            $reached[$table] = true;
            array_push($queue, ...array_map('strval', array_keys(($everyWrite[$table] ?? []) + ($modifications[$table] ?? []))));
        }

        $tables = array_map('strval', array_keys($reached));
        sort($tables, SORT_STRING);

        return $tables;
    }
}
