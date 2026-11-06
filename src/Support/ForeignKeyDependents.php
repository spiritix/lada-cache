<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Tables whose rows the database changes when rows of another table are updated or deleted:
 * foreign keys with cascading or nulling actions, plus `lada-cache.dependent_tables` for
 * changes the schema does not declare, such as triggers. Dependents are transitive.
 */
final class ForeignKeyDependents
{
    private const array CHANGING_ACTIONS = ['cascade', 'set null', 'set_null', 'set default', 'set_default', 'c', 'n', 'd'];

    /**
     * @return array<string, list<string>> table => tables changed along with it
     */
    public static function load(Connection $connection): array
    {
        $direct = self::configured();

        foreach ($connection->select(self::foreignKeyQuery($connection), [], false) as $foreignKey) {
            $foreignKey = (array) $foreignKey;

            if (! self::changesDependents((string) $foreignKey['delete_rule']) && ! self::changesDependents((string) $foreignKey['update_rule'])) {
                continue;
            }

            $parent = self::withoutPrefix((string) $foreignKey['parent_table'], $connection->getTablePrefix());
            $direct[$parent][self::withoutPrefix((string) $foreignKey['child_table'], $connection->getTablePrefix())] = true;
        }

        return self::transitiveClosure($direct);
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

    private static function changesDependents(string $action): bool
    {
        return in_array(strtolower(trim($action)), self::CHANGING_ACTIONS, true);
    }

    private static function withoutPrefix(string $table, string $prefix): string
    {
        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }

    /**
     * @param  array<string, array<string, true>>  $direct
     * @return array<string, list<string>>
     */
    private static function transitiveClosure(array $direct): array
    {
        $closure = [];

        foreach (array_keys($direct) as $table) {
            $reached = [];
            $queue = array_keys($direct[$table]);

            while ($queue !== []) {
                $dependent = (string) array_shift($queue);

                if (isset($reached[$dependent])) {
                    continue;
                }

                $reached[$dependent] = true;
                array_push($queue, ...array_keys($direct[$dependent] ?? []));
            }

            $dependents = array_map('strval', array_keys($reached));
            sort($dependents, SORT_STRING);
            $closure[(string) $table] = $dependents;
        }

        ksort($closure, SORT_STRING);

        return $closure;
    }
}
