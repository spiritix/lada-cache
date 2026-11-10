<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support\Sql;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\Sql\DeleteStatement;
use Spiritix\LadaCache\Support\Sql\Dialects;
use Spiritix\LadaCache\Support\Sql\InsertStatement;
use Spiritix\LadaCache\Support\Sql\MergeStatement;
use Spiritix\LadaCache\Support\Sql\Parser;
use Spiritix\LadaCache\Support\Sql\ParserException;
use Spiritix\LadaCache\Support\Sql\TruncateStatement;
use Spiritix\LadaCache\Support\Sql\UpdateStatement;
use Spiritix\LadaCache\Support\Sql\WriteTargets;

/**
 * Ported from sqlparser-rs tests/sqlparser_{common,mysql,postgres,sqlite,mssql}.rs (Apache-2.0), commit 8a176d9: the
 * cases that parse a write or a FROM clause, asserting the tables and columns lada extracts on each dialect that runs
 * them, and that syntax lada cannot map to tables throws. The `lada*` providers cover what the port adds beyond them.
 */
final class ParserTest extends TestCase
{
    private const array ALL = ['mysql', 'pgsql', 'sqlite', 'sqlsrv'];

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function commonWrites(): iterable
    {
        yield 'parse_insert_values #1' => ['INSERT customer VALUES (1, 2, 3)', self::ALL, 'INSERT customer'];
        yield 'parse_insert_values #2' => ['INSERT INTO customer VALUES (1, 2, 3)', self::ALL, 'INSERT customer'];
        yield 'parse_insert_values #3' => ['INSERT INTO customer VALUES (1, 2, 3), (1, 2, 3)', self::ALL, 'INSERT customer'];
        yield 'parse_insert_values #4' => ['INSERT INTO public.customer VALUES (1, 2, 3)', self::ALL, 'INSERT public.customer'];
        yield 'parse_insert_values #5' => ['INSERT INTO db.public.customer VALUES (1, 2, 3)', self::ALL, 'INSERT db.public.customer'];
        yield 'parse_insert_values #6' => ['INSERT INTO public.customer (id, name, active) VALUES (1, 2, 3)', self::ALL, 'INSERT public.customer'];
        yield 'parse_insert_values #7' => ['INSERT INTO t (id, name, active) VALUE (1, 2, 3)', ['mysql'], 'INSERT t'];
        yield 'parse_insert_values #8' => ['INSERT INTO customer WITH foo AS (SELECT 1) SELECT * FROM foo UNION VALUES (1)', self::ALL, 'INSERT customer'];
        yield 'parse_insert_set #1' => ["INSERT INTO tbl1 SET col1 = 1, col2 = 'abc', col3 = current_date()", ['mysql'], 'INSERT tbl1'];
        yield 'parse_insert_default_values #1' => ['INSERT INTO test_table DEFAULT VALUES', self::ALL, 'INSERT test_table'];
        yield 'parse_insert_default_values #2' => ['INSERT INTO test_table DEFAULT VALUES RETURNING test_column', self::ALL, 'INSERT test_table'];
        yield 'parse_insert_default_values #3' => ['INSERT INTO test_table DEFAULT VALUES ON CONFLICT DO NOTHING', self::ALL, 'INSERT test_table'];
        yield 'parse_insert_select_returning #1' => ['INSERT INTO t SELECT 1 RETURNING 2', self::ALL, 'INSERT t'];
        yield 'parse_insert_select_returning #2' => ['INSERT INTO t SELECT x RETURNING x AS y', self::ALL, 'INSERT t'];
        yield 'parse_insert_select_from_returning #1' => ['INSERT INTO table1 SELECT * FROM table2 RETURNING id', self::ALL, 'INSERT table1'];
        yield 'parse_insert_nested_parenthesized_subquery #1' => ['INSERT INTO t ((SELECT 1))', self::ALL, 'INSERT t'];
        yield 'parse_insert_nested_parenthesized_subquery #2' => ['INSERT INTO t (((SELECT 1)))', self::ALL, 'INSERT t'];
        yield 'parse_insert_sqlite #1' => ['INSERT INTO test_table(id) VALUES(1)', ['sqlite'], 'INSERT test_table'];
        yield 'parse_insert_sqlite #2' => ['REPLACE INTO test_table(id) VALUES(1)', ['sqlite'], 'INSERT test_table REPLACING ROWS'];
        yield 'parse_insert_sqlite #3' => ['INSERT OR REPLACE INTO test_table(id) VALUES(1)', ['sqlite'], 'INSERT test_table REPLACING ROWS'];
        yield 'parse_insert_sqlite #4' => ['INSERT OR ROLLBACK INTO test_table(id) VALUES(1)', ['sqlite'], 'INSERT test_table'];
        yield 'parse_insert_sqlite #5' => ['INSERT OR ABORT INTO test_table(id) VALUES(1)', ['sqlite'], 'INSERT test_table'];
        yield 'parse_insert_sqlite #6' => ['INSERT OR FAIL INTO test_table(id) VALUES(1)', ['sqlite'], 'INSERT test_table'];
        yield 'parse_insert_sqlite #7' => ['INSERT OR IGNORE INTO test_table(id) VALUES(1)', ['sqlite'], 'INSERT test_table'];
        yield 'parse_update #1' => ['UPDATE t SET a = 1, b = 2, c = 3 WHERE d', self::ALL, 'UPDATE t SET a, b, c'];
        yield 'parse_update #2' => ['UPDATE t SET a = 1, a = 2, a = 3', self::ALL, 'UPDATE t SET a, a, a'];
        yield 'parse_update_set_from #1' => ['UPDATE t1 SET name = t2.name FROM (SELECT name, id FROM t1 GROUP BY id) AS t2 WHERE t1.id = t2.id', ['pgsql', 'sqlite', 'sqlsrv'], 'UPDATE t1 SET name'];
        yield 'parse_update_set_from #2' => ['UPDATE T SET a = b FROM U, (SELECT foo FROM V) AS W WHERE 1 = 1', ['pgsql', 'sqlite', 'sqlsrv'], 'UPDATE T SET a'];
        yield 'parse_update_with_table_alias #1' => ["UPDATE users AS u SET u.username = 'new_user' WHERE u.username = 'old_user'", self::ALL, 'UPDATE users SET username'];
        yield 'parse_update_or #2' => ['UPDATE OR ROLLBACK t SET n = n + 1', self::ALL, 'UPDATE t SET n'];
        yield 'parse_update_or #3' => ['UPDATE OR ABORT t SET n = n + 1', self::ALL, 'UPDATE t SET n'];
        yield 'parse_update_or #4' => ['UPDATE OR FAIL t SET n = n + 1', self::ALL, 'UPDATE t SET n'];
        yield 'parse_update_or #5' => ['UPDATE OR IGNORE t SET n = n + 1', self::ALL, 'UPDATE t SET n'];
        yield 'parse_delete_statement #1' => ['DELETE FROM "table"', self::ALL, 'DELETE table'];
        yield 'parse_delete_statement_for_multi_tables #1' => ['DELETE schema1.table1, schema2.table2 FROM schema1.table1 JOIN schema2.table2 ON schema2.table2.col1 = schema1.table1.col1 WHERE schema2.table2.col2 = 1', self::ALL, 'DELETE schema1.table1, schema2.table2'];
        yield 'parse_delete_statement_for_multi_tables_with_using #1' => ['DELETE FROM schema1.table1, schema2.table2 USING schema1.table1 JOIN schema2.table2 ON schema2.table2.pk = schema1.table1.col1 WHERE schema2.table2.col2 = 1', self::ALL, 'DELETE schema1.table1, schema2.table2'];
        yield 'parse_where_delete_statement #1' => ['DELETE FROM foo WHERE name = 5', self::ALL, 'DELETE foo'];
        yield 'parse_where_delete_with_alias_statement #1' => ['DELETE FROM basket AS a USING basket AS b WHERE a.id < b.id', self::ALL, 'DELETE basket'];
        yield 'parse_merge #1' => ["MERGE INTO s.bar AS dest USING (SELECT * FROM s.foo) AS stg ON dest.D = stg.D AND dest.E = stg.E WHEN NOT MATCHED THEN INSERT (A, B, C) VALUES (stg.A, stg.B, stg.C) WHEN MATCHED AND dest.A = 'a' THEN UPDATE SET dest.F = stg.F, dest.G = stg.G WHEN MATCHED THEN DELETE", self::ALL, 'MERGE s.bar'];
        yield 'parse_merge #2' => ["MERGE s.bar AS dest USING (SELECT * FROM s.foo) AS stg ON dest.D = stg.D AND dest.E = stg.E WHEN NOT MATCHED THEN INSERT (A, B, C) VALUES (stg.A, stg.B, stg.C) WHEN MATCHED AND dest.A = 'a' THEN UPDATE SET dest.F = stg.F, dest.G = stg.G WHEN MATCHED THEN DELETE", self::ALL, 'MERGE s.bar'];
        yield 'parse_merge #3' => ['MERGE INTO s.bar AS dest USING newArrivals AS S ON (1 > 1) WHEN NOT MATCHED THEN INSERT VALUES (stg.A, stg.B, stg.C)', self::ALL, 'MERGE s.bar'];
        yield 'parse_merge #4' => ["MERGE INTO FOO USING FOO_IMPORT ON (FOO.ID = FOO_IMPORT.ID) WHEN MATCHED THEN UPDATE SET FOO.NAME = FOO_IMPORT.NAME WHERE 1 = 1 DELETE WHERE FOO.NAME LIKE '%.DELETE' WHEN NOT MATCHED THEN INSERT (ID, NAME) VALUES (FOO_IMPORT.ID, UPPER(FOO_IMPORT.NAME)) WHERE NOT FOO_IMPORT.NAME LIKE '%.DO_NOT_INSERT'", self::ALL, 'MERGE FOO'];
        yield 'parse_merge #5' => ["MERGE INTO FOO USING FOO_IMPORT ON (FOO.ID = FOO_IMPORT.ID) WHEN NOT MATCHED THEN INSERT (ID, NAME) VALUES (1, 'abc')", self::ALL, 'MERGE FOO'];
        yield 'parse_merge #6' => ["MERGE INTO FOO USING FOO_IMPORT ON (FOO.ID = FOO_IMPORT.ID) WHEN NOT MATCHED THEN INSERT (FOO.ID, FOO.NAME) VALUES (1, 'abc')", self::ALL, 'MERGE FOO'];
        yield 'parse_merge #7' => ["MERGE INTO PLAYGROUND.FOO USING FOO_IMPORT ON (PLAYGROUND.FOO.ID = FOO_IMPORT.ID) WHEN NOT MATCHED THEN INSERT (PLAYGROUND.FOO.ID, PLAYGROUND.FOO.NAME) VALUES (1, 'abc')", self::ALL, 'MERGE PLAYGROUND.FOO'];
        yield 'parse_merge #8' => ['MERGE INTO target USING source ON target.id = source.id WHEN MATCHED THEN UPDATE SET * WHEN NOT MATCHED THEN INSERT *', self::ALL, 'MERGE target'];
        yield 'parse_merge #9' => ['MERGE INTO target USING source ON target.id = source.id WHEN MATCHED AND source.active = 1 THEN UPDATE SET *', self::ALL, 'MERGE target'];
        yield 'parse_merge #10' => ['MERGE INTO target USING source ON target.id = source.id WHEN NOT MATCHED BY TARGET THEN INSERT *', self::ALL, 'MERGE target'];
        yield 'parse_merge #11' => ['MERGE INTO target USING source ON target.id = source.id WHEN MATCHED THEN UPDATE SET * WHEN NOT MATCHED THEN INSERT (a, b) VALUES (source.a, source.b)', self::ALL, 'MERGE target'];
        yield 'parse_merge #12' => ['MERGE INTO t1 AS target USING (SELECT * FROM t2) AS source ON target.id = source.id WHEN MATCHED THEN UPDATE SET * WHEN NOT MATCHED THEN INSERT *', self::ALL, 'MERGE t1'];
        yield 'test_merge_with_returning #1' => ['MERGE INTO wines AS w USING wine_stock_changes AS s ON s.winename = w.winename WHEN NOT MATCHED AND s.stock_delta > 0 THEN INSERT VALUES (s.winename, s.stock_delta) WHEN MATCHED AND w.stock + s.stock_delta > 0 THEN UPDATE SET stock = w.stock + s.stock_delta WHEN MATCHED THEN DELETE RETURNING merge_action(), w.*', self::ALL, 'MERGE wines'];
        yield 'test_merge_with_output #1' => ['MERGE INTO target_table USING source_table ON target_table.id = source_table.oooid WHEN MATCHED THEN UPDATE SET target_table.description = source_table.description WHEN NOT MATCHED THEN INSERT (ID, description) VALUES (source_table.id, source_table.description) OUTPUT inserted.* INTO log_target', ['mysql', 'pgsql', 'sqlite'], 'MERGE target_table'];
        yield 'test_merge_with_output_without_into #1' => ['MERGE INTO a USING b ON a.id = b.id WHEN MATCHED THEN DELETE OUTPUT inserted.*', self::ALL, 'MERGE a'];
        yield 'test_merge_into_using_table #1' => ['MERGE INTO target_table USING source_table ON target_table.id = source_table.oooid WHEN MATCHED THEN UPDATE SET target_table.description = source_table.description WHEN NOT MATCHED THEN INSERT (ID, description) VALUES (source_table.id, source_table.description)', self::ALL, 'MERGE target_table'];
        yield 'test_merge_with_delimiter #1' => ['MERGE INTO target_table USING source_table ON target_table.id = source_table.oooid WHEN MATCHED THEN UPDATE SET target_table.description = source_table.description WHEN NOT MATCHED THEN INSERT (ID, description) VALUES (source_table.id, source_table.description);', self::ALL, 'MERGE target_table'];
        yield 'test_truncate_table_with_on_cluster #1' => ['TRUNCATE TABLE t', self::ALL, 'TRUNCATE t'];
        yield 'parse_update_from_before_select #1' => ['UPDATE t1 FROM (SELECT name, id FROM t1 GROUP BY id) AS t2 SET name = t2.name WHERE t1.id = t2.id', self::ALL, 'UPDATE t1 SET name'];
        yield 'parse_update_from_before_select #2' => ['UPDATE t1 FROM U, (SELECT id FROM V) AS W SET a = b WHERE 1 = 1', self::ALL, 'UPDATE t1 SET a'];
        yield 'parse_update_from_before_select #3' => ['UPDATE t1 FROM (SELECT name, id FROM t1 GROUP BY id) AS t2 SET name = t2.name FROM (SELECT name from t2) AS t2', self::ALL, 'UPDATE t1 SET name'];
        yield 'parse_truncate_only #1' => ['TRUNCATE TABLE employee, ONLY dept', self::ALL, 'TRUNCATE employee, dept'];
        yield 'parse_insert_by_name #1' => ['INSERT INTO target BY NAME SELECT 1 AS a', self::ALL, 'INSERT target'];
        yield 'parse_insert_by_name #2' => ['INSERT INTO target (a) BY NAME SELECT 1 AS a', self::ALL, 'INSERT target'];
        yield 'parse_insert_by_name #3' => ['INSERT INTO TABLE target PARTITION (p = 1) BY NAME SELECT 1 AS a', ['mysql', 'sqlite', 'sqlsrv'], 'INSERT target'];
        yield 'parse_insert_by_name #4' => ['INSERT INTO target AS t BY NAME SELECT 1 AS a', ['pgsql'], 'INSERT target'];
        yield 'parse_table_preserves_quotes_and_trailing_tokens #1' => ['INSERT INTO t TABLE `%mpty`', ['mysql'], 'INSERT t'];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function commonUnsupportedWrites(): iterable
    {
        yield 'parse_replace_into #1' => ['REPLACE INTO public.customer (id, name, active) VALUES (1, 2, 3)', ['pgsql']];
        yield 'parse_update #3' => ['UPDATE t WHERE 1', self::ALL];
        yield 'parse_update_or #1' => ['UPDATE OR REPLACE t SET n = n + 1', self::ALL];
        yield 'parse_delete_without_from_error #1' => ['DELETE "table" WHERE 1', self::ALL];
        yield 'parse_cte_in_data_modification_statements #1' => ['WITH x AS (SELECT 1) UPDATE t SET bar = (SELECT * FROM x)', self::ALL];
        yield 'parse_cte_in_data_modification_statements #2' => ['WITH t (x) AS (SELECT 9) DELETE FROM q WHERE id IN (SELECT x FROM t)', self::ALL];
        yield 'parse_cte_in_data_modification_statements #3' => ['WITH x AS (SELECT 42) INSERT INTO t SELECT foo FROM x', self::ALL];
        yield 'test_merge_with_output #2' => ['MERGE INTO target_table USING source_table ON target_table.id = source_table.oooid WHEN MATCHED THEN UPDATE SET target_table.description = source_table.description WHEN NOT MATCHED THEN INSERT (ID, description) VALUES (source_table.id, source_table.description) OUTPUT inserted.* INTO log_target', ['sqlsrv']];
        yield 'parse_update_deeply_nested_parens_hits_recursion_limits #1' => ["\nUPDATE\n\n\n\n\n\n\n\n\n\n".str_repeat('(', 1000), self::ALL];
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function commonReads(): iterable
    {
        yield 'parse_select_with_table_alias_as #1' => ['SELECT a, b, c FROM lineitem AS l (A, B, C)', self::ALL, ['lineitem']];
        yield 'parse_select_with_table_alias_as #2' => ['SELECT a, b, c FROM lineitem l (A, B, C)', self::ALL, ['lineitem']];
        yield 'parse_select_with_table_alias #1' => ['SELECT a, b, c FROM lineitem AS l (A, B, C)', self::ALL, ['lineitem']];
        yield 'parse_limit_is_not_an_alias #1' => ['SELECT id FROM customer LIMIT 1', self::ALL, ['customer']];
        yield 'parse_in_subquery #1' => ['SELECT * FROM customers WHERE segment IN (SELECT segm FROM bar)', self::ALL, ['customers', 'bar']];
        yield 'parse_in_subquery #2' => ['SELECT segm FROM bar', self::ALL, ['bar']];
        yield 'parse_in_table_expression #1' => ['SELECT a IN t FROM t', ['sqlite'], ['t', 't']];
        yield 'parse_in_table_expression #2' => ['SELECT a NOT IN t FROM t', ['sqlite'], ['t', 't']];
        yield 'parse_in_table_expression #3' => ['SELECT a IN main.t FROM t', ['sqlite'], ['main.t', 't']];
        yield 'parse_in_table_expression #4' => ['SELECT a IN f() FROM t', ['sqlite'], ['t']];
        yield 'parse_in_table_expression #5' => ['SELECT a NOT IN f(1, 2) FROM t', ['sqlite'], ['t']];
        yield 'parse_in_table_expression #6' => ['SELECT a IN (1, 2) FROM t', ['sqlite'], ['t']];
        yield 'parse_in_table_expression #7' => ['SELECT a IN (SELECT b FROM u) FROM t', ['sqlite'], ['u', 't']];
        yield 'test_visit_relations_in_table_target #1' => ['SELECT a IN lookup FROM source', ['sqlite'], ['lookup', 'source']];
        yield 'parse_table_function #1' => ["SELECT * FROM TABLE(FUN('1')) AS a", self::ALL, []];
        yield 'parse_select_with_alias_and_column_defs #1' => ['SELECT * FROM jsonb_to_record(\'{"a": "x", "b": 2}\'::JSONB) AS x (a TEXT, b INT)', self::ALL, []];
        yield 'parse_from_advanced #1' => ['SELECT * FROM fn(1, 2) AS foo, schema.bar AS bar WITH (NOLOCK)', self::ALL, ['schema.bar']];
        yield 'parse_nullary_table_valued_function #1' => ['SELECT * FROM fn()', self::ALL, []];
        yield 'parse_implicit_join #1' => ['SELECT * FROM t1, t2', self::ALL, ['t1', 't2']];
        yield 'parse_implicit_join #2' => ['SELECT * FROM t1a NATURAL JOIN t1b, t2a NATURAL JOIN t2b', self::ALL, ['t1a', 't1b', 't2a', 't2b']];
        yield 'parse_cross_join #1' => ['SELECT * FROM t1 CROSS JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_cross_join_constraint #1' => ['SELECT * FROM t1 CROSS JOIN t2 ON a = b', ['mysql'], ['t1', 't2']];
        yield 'parse_cross_join_constraint #2' => ['SELECT * FROM t1 CROSS JOIN t2 USING(a)', ['mysql'], ['t1', 't2']];
        yield 'parse_joins_on #1' => ['SELECT * FROM t1 JOIN t2 AS foo ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #2' => ['SELECT * FROM t1 JOIN t2 foo ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #3' => ['SELECT * FROM t1 JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #4' => ['SELECT * FROM t1 INNER JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #5' => ['SELECT * FROM t1 LEFT JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #6' => ['SELECT * FROM t1 LEFT OUTER JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #7' => ['SELECT * FROM t1 RIGHT JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #8' => ['SELECT * FROM t1 RIGHT OUTER JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #9' => ['SELECT * FROM t1 SEMI JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #10' => ['SELECT * FROM t1 LEFT SEMI JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #11' => ['SELECT * FROM t1 RIGHT SEMI JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #12' => ['SELECT * FROM t1 ANTI JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #13' => ['SELECT * FROM t1 LEFT ANTI JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #14' => ['SELECT * FROM t1 RIGHT ANTI JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #15' => ['SELECT * FROM t1 FULL JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #16' => ['SELECT * FROM t1 GLOBAL FULL JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #1' => ['SELECT * FROM t1 JOIN t2 AS foo USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #2' => ['SELECT * FROM t1 JOIN t2 foo USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #3' => ['SELECT * FROM t1 JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #4' => ['SELECT * FROM t1 INNER JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #5' => ['SELECT * FROM t1 LEFT JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #6' => ['SELECT * FROM t1 LEFT OUTER JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #7' => ['SELECT * FROM t1 RIGHT JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #8' => ['SELECT * FROM t1 RIGHT OUTER JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #9' => ['SELECT * FROM t1 SEMI JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #10' => ['SELECT * FROM t1 LEFT SEMI JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #11' => ['SELECT * FROM t1 RIGHT SEMI JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #12' => ['SELECT * FROM t1 ANTI JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #13' => ['SELECT * FROM t1 LEFT ANTI JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #14' => ['SELECT * FROM t1 RIGHT ANTI JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #15' => ['SELECT * FROM t1 FULL JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #16' => ['SELECT * FROM tbl1 AS t1 JOIN tbl2 AS t2 USING(t2.col1)', self::ALL, ['tbl1', 'tbl2']];
        yield 'parse_natural_join #1' => ['SELECT * FROM t1 NATURAL JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_natural_join #2' => ['SELECT * FROM t1 NATURAL INNER JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_natural_join #3' => ['SELECT * FROM t1 NATURAL LEFT JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_natural_join #4' => ['SELECT * FROM t1 NATURAL LEFT OUTER JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_natural_join #5' => ['SELECT * FROM t1 NATURAL RIGHT JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_natural_join #6' => ['SELECT * FROM t1 NATURAL RIGHT OUTER JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_natural_join #7' => ['SELECT * FROM t1 NATURAL FULL JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_natural_join #8' => ['SELECT * FROM t1 NATURAL JOIN t2 AS t3', self::ALL, ['t1', 't2']];
        yield 'parse_complex_join #1' => ['SELECT c1, c2 FROM t1, t4 JOIN t2 ON t2.c = t1.c LEFT JOIN t3 USING(q, c) WHERE t4.c = t1.c', self::ALL, ['t1', 't4', 't2', 't3']];
        yield 'parse_join_nesting #1' => ['SELECT * FROM a NATURAL JOIN (b NATURAL JOIN (c NATURAL JOIN d NATURAL JOIN e)) NATURAL JOIN (f NATURAL JOIN (g NATURAL JOIN h))', self::ALL, ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']];
        yield 'parse_join_nesting #2' => ['SELECT * FROM (a NATURAL JOIN b) NATURAL JOIN c', self::ALL, ['a', 'b', 'c']];
        yield 'parse_join_nesting #3' => ['SELECT * FROM (((a NATURAL JOIN b)))', self::ALL, ['a', 'b']];
        yield 'parse_join_nesting #4' => ['SELECT * FROM a NATURAL JOIN (((b NATURAL JOIN c)))', self::ALL, ['a', 'b', 'c']];
        yield 'parse_join_nesting #5' => ['SELECT * FROM (a NATURAL JOIN b) AS c', self::ALL, ['a', 'b']];
        yield 'parse_join_syntax_variants #1' => ['SELECT c1 FROM t1 JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_join_syntax_variants #2' => ['SELECT c1 FROM t1 INNER JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_join_syntax_variants #3' => ['SELECT c1 FROM t1 LEFT JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_join_syntax_variants #4' => ['SELECT c1 FROM t1 LEFT OUTER JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_join_syntax_variants #5' => ['SELECT c1 FROM t1 RIGHT JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_join_syntax_variants #6' => ['SELECT c1 FROM t1 RIGHT OUTER JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_join_syntax_variants #7' => ['SELECT c1 FROM t1 FULL OUTER JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_join_syntax_variants #8' => ['SELECT c1 FROM t1 FULL JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_ctes #1' => ['WITH a AS (SELECT 1 AS foo), b AS (SELECT 2 AS bar) SELECT foo + bar FROM a, b', self::ALL, ['a', 'b']];
        yield 'parse_ctes #2' => ['SELECT (WITH a AS (SELECT 1 AS foo), b AS (SELECT 2 AS bar) SELECT foo + bar FROM a, b)', self::ALL, ['a', 'b']];
        yield 'parse_ctes #3' => ['SELECT * FROM (WITH a AS (SELECT 1 AS foo), b AS (SELECT 2 AS bar) SELECT foo + bar FROM a, b)', self::ALL, ['a', 'b']];
        yield 'parse_ctes #4' => ['WITH outer_cte AS (WITH a AS (SELECT 1 AS foo), b AS (SELECT 2 AS bar) SELECT foo + bar FROM a, b) SELECT * FROM outer_cte', self::ALL, ['a', 'b', 'outer_cte']];
        yield 'parse_derived_tables #1' => ['SELECT a.x, b.y FROM (SELECT x FROM foo) AS a CROSS JOIN (SELECT y FROM bar) AS b', self::ALL, ['foo', 'bar']];
        yield 'parse_derived_tables #2' => ['SELECT a.x, b.y FROM (SELECT x FROM foo) AS a (x) CROSS JOIN (SELECT y FROM bar) AS b (y)', self::ALL, ['foo', 'bar']];
        yield 'parse_derived_tables #3' => ['SELECT * FROM (((SELECT 1)))', self::ALL, []];
        yield 'parse_derived_tables #4' => ['SELECT * FROM t NATURAL JOIN (((SELECT 1)))', self::ALL, ['t']];
        yield 'parse_derived_tables #5' => ['SELECT * FROM (((SELECT 1) UNION (SELECT 2)) AS t1 NATURAL JOIN t2)', self::ALL, ['t2']];
        yield 'parse_exists_subquery #1' => ['SELECT * FROM t WHERE EXISTS (SELECT 1)', self::ALL, ['t']];
        yield 'parse_exists_subquery #2' => ['SELECT * FROM t WHERE NOT EXISTS (SELECT 1)', self::ALL, ['t']];
        yield 'parse_exists_subquery #3' => ['SELECT * FROM t WHERE EXISTS (WITH u AS (SELECT 1) SELECT * FROM u)', self::ALL, ['t', 'u']];
        yield 'parse_offset #1' => ['SELECT foo FROM bar OFFSET 2 ROWS', self::ALL, ['bar']];
        yield 'parse_offset #2' => ['SELECT foo FROM bar WHERE foo = 4 OFFSET 2 ROWS', self::ALL, ['bar']];
        yield 'parse_offset #3' => ['SELECT foo FROM bar ORDER BY baz OFFSET 2 ROWS', self::ALL, ['bar']];
        yield 'parse_offset #4' => ['SELECT foo FROM bar WHERE foo = 4 ORDER BY baz OFFSET 2 ROWS', self::ALL, ['bar']];
        yield 'parse_offset #5' => ['SELECT foo FROM (SELECT * FROM bar OFFSET 2 ROWS) OFFSET 2 ROWS', self::ALL, ['bar']];
        yield 'parse_fetch #1' => ['SELECT foo FROM bar FETCH FIRST 2 ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #2' => ['SELECT foo FROM bar FETCH FIRST ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #3' => ['SELECT foo FROM bar WHERE foo = 4 FETCH FIRST 2 ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #4' => ['SELECT foo FROM bar ORDER BY baz FETCH FIRST 2 ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #5' => ['SELECT foo FROM bar WHERE foo = 4 ORDER BY baz FETCH FIRST 2 ROWS WITH TIES', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #6' => ['SELECT foo FROM bar FETCH FIRST 50 PERCENT ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #7' => ['SELECT foo FROM bar WHERE foo = 4 ORDER BY baz OFFSET 2 ROWS FETCH FIRST 2 ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #8' => ['SELECT foo FROM (SELECT * FROM bar FETCH FIRST 2 ROWS ONLY) FETCH FIRST 2 ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #9' => ['SELECT foo FROM (SELECT * FROM bar OFFSET 2 ROWS FETCH FIRST 2 ROWS ONLY) OFFSET 2 ROWS FETCH FIRST 2 ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'lateral_derived #1' => ['SELECT * FROM customer LEFT JOIN (SELECT * FROM orders WHERE orders.customer = customer.id LIMIT 3) AS orders ON 1', self::ALL, ['customer', 'orders']];
        yield 'lateral_derived #2' => ['SELECT * FROM customer LEFT JOIN LATERAL (SELECT * FROM orders WHERE orders.customer = customer.id LIMIT 3) AS orders ON 1', self::ALL, ['customer', 'orders']];
        yield 'lateral_function #1' => ['SELECT * FROM customer LEFT JOIN LATERAL generate_series(1, customer.id)', self::ALL, ['customer']];
        yield 'test_lock_table #1' => ["SELECT * FROM student WHERE id = '1' FOR UPDATE OF school", self::ALL, ['student']];
        yield 'test_lock_table #2' => ["SELECT * FROM student WHERE id = '1' FOR SHARE OF school", self::ALL, ['student']];
        yield 'test_lock_table #3' => ["SELECT * FROM student WHERE id = '1' FOR SHARE OF school FOR UPDATE OF student", self::ALL, ['student']];
        yield 'test_lock #1' => ["SELECT * FROM student WHERE id = '1' FOR UPDATE", self::ALL, ['student']];
        yield 'test_lock #2' => ["SELECT * FROM student WHERE id = '1' FOR SHARE", self::ALL, ['student']];
        yield 'test_lock_nonblock #1' => ["SELECT * FROM student WHERE id = '1' FOR UPDATE OF school SKIP LOCKED", self::ALL, ['student']];
        yield 'test_lock_nonblock #2' => ["SELECT * FROM student WHERE id = '1' FOR SHARE OF school NOWAIT", self::ALL, ['student']];
        yield 'parse_select_table_with_index_hints #1' => ['SELECT * FROM t1 USE INDEX (i1) IGNORE INDEX FOR ORDER BY (i2) ORDER BY a', ['mysql'], ['t1']];
        yield 'parse_select_table_with_index_hints #2' => ['SELECT * FROM t1 USE INDEX (i1) USE INDEX (i1, i1)', ['mysql'], ['t1']];
        yield 'parse_select_table_with_index_hints #3' => ['SELECT * FROM t1 USE INDEX () IGNORE INDEX (i2) USE INDEX (i1) USE INDEX (i2)', ['mysql'], ['t1']];
        yield 'parse_select_table_with_index_hints #4' => ['SELECT * FROM t1 FORCE INDEX FOR JOIN (i2)', ['mysql'], ['t1']];
        yield 'parse_select_table_with_index_hints #5' => ['SELECT * FROM t1 IGNORE INDEX FOR JOIN (i2)', ['mysql'], ['t1']];
        yield 'parse_select_table_with_index_hints #6' => ['SELECT * FROM t USE INDEX (index1) IGNORE INDEX FOR ORDER BY (index1) IGNORE INDEX FOR GROUP BY (index1) WHERE A = B', ['mysql'], ['t']];
        yield 'parse_select_table_with_index_hints #7' => ['SELECT * FROM T USE LIMIT 1', ['pgsql', 'sqlite', 'sqlsrv'], ['T']];
        yield 'parse_non_latin_identifiers #1' => ['SELECT a.説明 FROM test.public.inter01 AS a', self::ALL, ['test.public.inter01']];
        yield 'parse_non_latin_identifiers #2' => ['SELECT a.説明 FROM inter01 AS a, inter01_transactions AS b WHERE a.説明 = b.取引 GROUP BY a.説明', self::ALL, ['inter01', 'inter01_transactions']];
        yield 'parse_non_latin_identifiers #3' => ['SELECT 説明, hühnervögel, garçon, Москва, 東京 FROM inter01', self::ALL, ['inter01']];
        yield 'parse_non_latin_identifiers #4' => ['SELECT 💝 FROM table1', self::ALL, ['table1']];
        yield 'parse_connect_by #3' => ["SELECT employee_id, manager_id, title FROM employees WHERE employee_id <> 42 START WITH title = 'president' CONNECT BY manager_id = PRIOR employee_id ORDER BY employee_id", ['sqlsrv'], ['employees']];
        yield 'parse_connect_by #5' => ['SELECT 0 FROM t WHERE 1 = 1 CONNECT BY 2 = 2 GROUP BY 3', ['sqlsrv'], ['t']];
        yield 'parse_connect_by #6' => ["SELECT 0 FROM t WHERE 1 = 1 START WITH 'a' = 'a' CONNECT BY 2 = 2 GROUP BY 3", ['sqlsrv'], ['t']];
        yield 'parse_connect_by #7' => ["SELECT 0 FROM t WHERE 1 = 1 CONNECT BY 2 = 2 START WITH 'a' = 'a' GROUP BY 3", ['sqlsrv'], ['t']];
        yield 'test_xmltable #1' => ["SELECT * FROM XMLTABLE('/root' PASSING data COLUMNS element TEXT)", self::ALL, []];
        yield 'test_xmltable #2' => ["SELECT y FROM XMLTABLE('/X' PASSING '<X><y>z</y></X>' COLUMNS y TEXT)", self::ALL, []];
        yield 'test_xmltable #3' => ["SELECT y FROM XMLTABLE((SELECT '/X') PASSING (SELECT CAST('<X><y>z</y></X>' AS xml)) COLUMNS y TEXT PATH (SELECT 'y'))", self::ALL, []];
        yield 'test_xmltable #4' => ["SELECT y FROM XMLTABLE('/X' PASSING '<X></X>' COLUMNS y TEXT NOT NULL)", self::ALL, []];
        yield 'test_xmltable #5' => ["SELECT * FROM XMLTABLE('/root/row' PASSING xmldata COLUMNS id INT PATH '@id', name TEXT PATH 'name/text()', value FLOAT PATH 'value')", self::ALL, []];
        yield 'test_xmltable #6' => ["SELECT * FROM XMLTABLE('//ROWS/ROW' PASSING data COLUMNS row_num FOR ORDINALITY, id INT PATH '@id', name TEXT PATH 'NAME' DEFAULT 'unnamed')", self::ALL, []];
        yield 'test_xmltable #7' => ['SELECT xmltable.* FROM xmldata, XMLTABLE(\'//ROWS/ROW\' PASSING data COLUMNS id INT PATH \'@id\', ordinality FOR ORDINALITY, "COUNTRY_NAME" TEXT, country_id TEXT PATH \'COUNTRY_ID\', size_sq_km FLOAT PATH \'SIZE[@unit = "sq_km"]\', size_other TEXT PATH \'concat(SIZE[@unit!="sq_km"], " ", SIZE[@unit!="sq_km"]/@unit)\', premier_name TEXT PATH \'PREMIER_NAME\' DEFAULT \'not specified\')', self::ALL, ['xmldata']];
        yield 'test_xmltable #8' => ['SELECT X.* FROM T1, XMLTABLE(\'$CUSTLIST/customers/customerinfo\' COLUMNS "Cid" BIGINT PATH \'@Cid\', "Info" XML PATH \'document{.}\', "History" XML PATH \'NULL\') AS X', self::ALL, ['T1']];
        yield 'test_xmltable #9' => ['SELECT xmltable.* FROM XMLTABLE(XMLNAMESPACES(\'http://example.com/myns\' AS x, \'http://example.com/b\' AS "B"), \'/x:example/x:item\' PASSING (SELECT data FROM xmldata) COLUMNS foo INT PATH \'@foo\', bar INT PATH \'@B:bar\')', self::ALL, ['xmldata']];
        yield 'test_qualified_keyword_function_calls #1' => ["SELECT pg_catalog.overlay('abc' PLACING 'xy' FROM 1)", ['pgsql'], []];
        yield 'test_qualified_keyword_function_calls #2' => ['SELECT t.interval FROM tbl t', ['pgsql'], ['tbl']];
        yield 'test_table_sample #1' => ['SELECT * FROM tbl AS t TABLESAMPLE BERNOULLI (50)', self::ALL, ['tbl']];
        yield 'test_table_sample #2' => ['SELECT * FROM tbl AS t TABLESAMPLE SYSTEM (50)', self::ALL, ['tbl']];
        yield 'test_table_sample #3' => ['SELECT * FROM tbl AS t TABLESAMPLE SYSTEM (50) REPEATABLE (10)', self::ALL, ['tbl']];
        yield 'parse_subquery_limit #1' => ['SELECT t1_id, t1_name FROM t1 WHERE t1_id IN (SELECT t2_id FROM t2 WHERE t1_name = t2_name LIMIT 10)', self::ALL, ['t1', 't2']];
        yield 'join_precedence #1' => ['SELECT * FROM t1 NATURAL JOIN t5 INNER JOIN t0 ON (t0.v1 + t5.v0) > 0 WHERE t0.v1 = t1.v0', self::ALL, ['t1', 't5', 't0']];
        yield 'parse_table_preserves_quotes_and_trailing_tokens #2' => ['SELECT * FROM (TABLE old_table ORDER BY x)', ['pgsql'], ['old_table']];
        yield 'quoted_on_allowed_as_table_name #1' => ['SELECT * FROM "ON"', ['pgsql', 'sqlite', 'sqlsrv'], ['ON']];
        yield 'quoted_on_allowed_as_table_name #2' => ['SELECT * FROM `ON`', ['mysql', 'sqlite'], ['ON']];
        yield 'parse_in_list_leading_subquery #1' => ['SELECT * FROM t WHERE x IN ((SELECT a FROM u), 1)', self::ALL, ['t', 'u']];
        yield 'parse_in_list_leading_subquery #2' => ['SELECT * FROM t WHERE x NOT IN (((SELECT a FROM u)), 1)', self::ALL, ['t', 'u']];
        yield 'parse_in_list_leading_subquery #3' => ['SELECT * FROM t WHERE x IN ((SELECT a FROM u) + 1, 2)', self::ALL, ['t', 'u']];
        yield 'parse_in_list_leading_subquery #4' => ['SELECT * FROM t WHERE x IN ((SELECT a FROM u))', self::ALL, ['t', 'u']];
        yield 'parse_in_list_leading_subquery #5' => ['(SELECT a FROM u)', self::ALL, ['u']];
        yield 'parse_in_list_leading_subquery #6' => ['SELECT * FROM t WHERE x IN ((SELECT a FROM u)[1], 2)', ['pgsql'], ['t', 'u']];
        yield 'parse_string_literal_alias #2' => ["SELECT a FROM t AS 'y'", ['sqlite', 'sqlsrv'], ['t']];
        yield 'parse_string_literal_alias #4' => ["SELECT a FROM t 'y'", ['sqlite', 'sqlsrv'], ['t']];
        yield 'parse_string_literal_alias_per_engine #2' => ["SELECT a FROM t AS 'y'", ['sqlite'], ['t']];
        yield 'parse_string_literal_alias_per_engine #4' => ["SELECT a FROM t 'y'", ['sqlite'], ['t']];
        yield 'parse_delimited_identifiers #1' => ['SELECT "alias"."bar baz", "myfun"(), "simple id" AS "column alias" FROM "a table" AS "alias"', ['pgsql', 'sqlsrv'], ['a table']];
        yield 'test_nested_join_without_parentheses #1' => ['SELECT DISTINCT p.product_id FROM orders AS o INNER JOIN customers AS c INNER JOIN products AS p ON p.customer_id = c.customer_id ON c.order_id = o.order_id', ['mysql', 'pgsql'], ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #2' => ['SELECT DISTINCT p.product_id FROM orders AS o INNER JOIN (customers AS c INNER JOIN products AS p ON p.customer_id = c.customer_id) ON c.order_id = o.order_id', self::ALL, ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #3' => ['SELECT DISTINCT p.product_id FROM orders AS o JOIN customers AS c JOIN products AS p ON p.customer_id = c.customer_id ON c.order_id = o.order_id', ['mysql', 'pgsql'], ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #4' => ['SELECT DISTINCT p.product_id FROM orders AS o JOIN (customers AS c JOIN products AS p ON p.customer_id = c.customer_id) ON c.order_id = o.order_id', self::ALL, ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #5' => ['SELECT DISTINCT p.product_id FROM orders AS o LEFT JOIN customers AS c LEFT JOIN products AS p ON p.customer_id = c.customer_id ON c.order_id = o.order_id', ['mysql', 'pgsql'], ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #6' => ['SELECT DISTINCT p.product_id FROM orders AS o LEFT JOIN (customers AS c LEFT JOIN products AS p ON p.customer_id = c.customer_id) ON c.order_id = o.order_id', self::ALL, ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #7' => ['SELECT DISTINCT p.product_id FROM orders AS o RIGHT JOIN customers AS c RIGHT JOIN products AS p ON p.customer_id = c.customer_id ON c.order_id = o.order_id', ['mysql', 'pgsql'], ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #8' => ['SELECT DISTINCT p.product_id FROM orders AS o RIGHT JOIN (customers AS c RIGHT JOIN products AS p ON p.customer_id = c.customer_id) ON c.order_id = o.order_id', self::ALL, ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #9' => ['SELECT DISTINCT p.product_id FROM orders AS o FULL JOIN customers AS c FULL JOIN products AS p ON p.customer_id = c.customer_id ON c.order_id = o.order_id', ['mysql', 'pgsql'], ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #10' => ['SELECT DISTINCT p.product_id FROM orders AS o FULL JOIN (customers AS c FULL JOIN products AS p ON p.customer_id = c.customer_id) ON c.order_id = o.order_id', self::ALL, ['orders', 'customers', 'products']];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function commonUnsupportedReads(): iterable
    {
        yield 'parse_invalid_table_name #1' => ['SELECT * FROM db.public..customer', self::ALL];
        yield 'parse_in_table_expression_malformed_targets #1' => ['SELECT a IN t.', ['sqlite']];
        yield 'parse_table_function #2' => ["SELECT * FROM TABLE '1' AS a", self::ALL];
        yield 'parse_table_function #3' => ['SELECT * FROM TABLE (FUN(a) AS a', self::ALL];
        yield 'parse_natural_join #9' => ['SELECT * FROM t1 natural', self::ALL];
        yield 'parse_join_syntax_variants #9' => ['SELECT * FROM a OUTER JOIN b ON 1', self::ALL];
        yield 'lateral_derived #3' => ['SELECT * FROM a LEFT JOIN LATERAL (b CROSS JOIN c)', self::ALL];
        yield 'parse_pivot_table #1' => ["SELECT * FROM monthly_sales AS a PIVOT(SUM(a.amount), SUM(b.amount) AS t, SUM(c.amount) AS u FOR a.MONTH IN (1 AS x, 'two', three AS y)) AS p (c, d) ORDER BY EMPID", self::ALL];
        yield 'parse_pivot_table #2' => ["SELECT * FROM monthly_sales PIVOT(SUM(a.amount) FOR a.MONTH IN ('JAN', 'FEB', 'MAR', 'APR')) AS p (c, d) ORDER BY EMPID", self::ALL];
        yield 'parse_pivot_table #3' => ["SELECT * FROM person PIVOT(SUM(age) AS a, AVG(class) AS c FOR (name, age) IN (('John', 30) AS c1, ('Mike', 40) AS c2))", self::ALL];
        yield 'parse_pivot_table #4' => ["SELECT * FROM t PIVOT(SUM(1) FOR a.abc IN (1 x, 'two' y, three z))", self::ALL];
        yield 'parse_pivot_table #5' => ["SELECT * FROM t PIVOT(SUM(1) FOR a.abc IN (1 AS x, 'two' AS y, three AS z))", self::ALL];
        yield 'parse_pivot_table #6' => ['SELECT * FROM t PIVOT(SUM(1) x, COUNT(42) y FOR a.abc IN (1))', self::ALL];
        yield 'parse_pivot_table #7' => ['SELECT * FROM t PIVOT(SUM(1) AS x, COUNT(42) AS y FOR a.abc IN (1))', self::ALL];
        yield 'parse_unpivot_table #1' => ['SELECT * FROM sales AS s UNPIVOT(quantity FOR quarter IN (Q1, Q2, Q3, Q4)) AS u (product, quarter, quantity)', self::ALL];
        yield 'parse_unpivot_table #2' => ['SELECT * FROM sales UNPIVOT(quantity FOR quarter IN (Q1, Q2, Q3, Q4))', self::ALL];
        yield 'parse_unpivot_table #3' => ['SELECT * FROM sales AS s UNPIVOT EXCLUDE NULLS (quantity FOR quarter IN (Q1, Q2, Q3, Q4)) AS u (product, quarter, quantity)', self::ALL];
        yield 'parse_unpivot_table #4' => ['SELECT * FROM sales AS s UNPIVOT INCLUDE NULLS (quantity FOR quarter IN (Q1, Q2, Q3, Q4)) AS u (product, quarter, quantity)', self::ALL];
        yield 'parse_unpivot_table #5' => ['SELECT * FROM sales AS s UNPIVOT INCLUDE NULLS (quantity FOR quarter IN (Q1 AS Quater1, Q2 AS Quater2, Q3 AS Quater3, Q4 AS Quater4)) AS u (product, quarter, quantity)', self::ALL];
        yield 'parse_pivot_unpivot_table #1' => ["SELECT * FROM census AS c UNPIVOT(population FOR year IN (population_2000, population_2010)) AS u PIVOT(sum(population) FOR year IN ('population_2000', 'population_2010')) AS p", self::ALL];
        yield 'parse_connect_by #1' => ["SELECT employee_id, manager_id, title FROM employees START WITH title = 'president' CONNECT BY manager_id = PRIOR employee_id ORDER BY employee_id", ['sqlsrv']];
        yield 'parse_connect_by #2' => ["SELECT employee_id, manager_id, title FROM employees CONNECT BY manager_id = PRIOR employee_id START WITH title = 'president' ORDER BY employee_id", ['sqlsrv']];
        yield 'parse_connect_by #4' => ['SELECT child, parent FROM t CONNECT BY NOCYCLE parent = PRIOR child', ['sqlsrv']];
        yield 'test_match_recognize #1' => ['SELECT * FROM my_table MATCH_RECOGNIZE(PARTITION BY company ORDER BY price_date MEASURES MATCH_NUMBER() AS match_number, FIRST(price_date) AS start_date, LAST(price_date) AS end_date ONE ROW PER MATCH AFTER MATCH SKIP TO LAST row_with_price_increase PATTERN (row_before_decrease row_with_price_decrease+ row_with_price_increase+) DEFINE row_with_price_decrease AS price < LAG(price), row_with_price_increase AS price > LAG(price))', self::ALL];
        yield 'test_match_recognize_patterns #1' => ['SELECT * FROM my_table MATCH_RECOGNIZE(PATTERN (S1+? S2?? S3{2,4}?) DEFINE DUMMY AS 1 = 1)', self::ALL];
        yield 'test_match_recognize_patterns #2' => ['SELECT * FROM my_table MATCH_RECOGNIZE(PATTERN (PERMUTE(A, B, C)) DEFINE DUMMY AS 1 = 1)', self::ALL];
        yield 'test_trailing_commas_in_from #1' => ['SELECT 1, 2 FROM t,', self::ALL];
        yield 'test_trailing_commas_in_from #2' => ['SELECT 1, 2 FROM t1, t2,', self::ALL];
        yield 'test_trailing_commas_in_from #3' => ['SELECT a, FROM b, LIMIT 1', self::ALL];
        yield 'test_trailing_commas_in_from #4' => ['SELECT a FROM b, HAVING COUNT(*) > 1', self::ALL];
        yield 'test_trailing_commas_in_from #5' => ['SELECT a FROM b, WHERE c = 1', self::ALL];
        yield 'test_trailing_commas_in_from #6' => ['SELECT 1, 2 FROM (SELECT * FROM t,),', self::ALL];
        yield 'test_trailing_commas_in_from #7' => ['SELECT 1, 2 FROM (SELECT * FROM t1), (SELECT * FROM t2),', self::ALL];
        yield 'on_keyword_rejected_as_table_name #1' => ['SELECT * FROM ON', self::ALL];
        yield 'on_keyword_rejected_as_table_name #2' => ['SELECT * FROM t JOIN ON', self::ALL];
        yield 'on_keyword_rejected_as_table_name #3' => ['SELECT * FROM t JOIN ON u ON TRUE', self::ALL];
        yield 'parse_stage_table_factor #1' => ['SELECT * FROM @stage', ['pgsql', 'sqlite']];
        yield 'parse_stage_table_factor #2' => ['SELECT * FROM @stage, my_table', ['pgsql', 'sqlite']];
        yield 'parse_string_literal_alias #1' => ["SELECT a FROM t AS 'y'", ['mysql', 'pgsql']];
        yield 'parse_string_literal_alias #3' => ["SELECT a FROM t 'y'", ['mysql', 'pgsql']];
        yield 'parse_string_literal_alias_per_engine #1' => ["SELECT a FROM t AS 'y'", ['mysql', 'pgsql']];
        yield 'parse_string_literal_alias_per_engine #3' => ["SELECT a FROM t 'y'", ['mysql', 'pgsql']];
        yield 'parse_table_factor_paren_chain_no_exponential_blowup #1' => ['SELECT 1 '.str_repeat('FROM '.str_repeat('(', 30), 5), self::ALL];
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function mysqlWrites(): iterable
    {
        yield 'parse_simple_insert #1' => ["INSERT INTO tasks (title, priority) VALUES ('Test Some Inserts', 1), ('Test Entry 2', 2), ('Test Entry 3', 3)", ['mysql'], 'INSERT tasks'];
        yield 'parse_ignore_insert #1' => ["INSERT IGNORE INTO tasks (title, priority) VALUES ('Test Some Inserts', 1)", ['mysql'], 'INSERT tasks'];
        yield 'parse_priority_insert #1' => ["INSERT HIGH_PRIORITY INTO tasks (title, priority) VALUES ('Test Some Inserts', 1)", ['mysql'], 'INSERT tasks'];
        yield 'parse_priority_insert #2' => ["INSERT LOW_PRIORITY INTO tasks (title, priority) VALUES ('Test Some Inserts', 1)", ['mysql'], 'INSERT tasks'];
        yield 'parse_insert_as #1' => ["INSERT INTO `table` (`date`) VALUES ('2024-01-01') AS `alias`", ['mysql'], 'INSERT table'];
        yield 'parse_insert_as #2' => ["INSERT INTO `table` (`date`) VALUES ('2024-01-01') AS `alias` ()", ['mysql'], 'INSERT table'];
        yield 'parse_insert_as #3' => ["INSERT INTO `table` (`id`, `date`) VALUES (1, '2024-01-01') AS `alias` (`mek_id`, `mek_date`)", ['mysql'], 'INSERT table'];
        yield 'parse_replace_insert #1' => ["REPLACE DELAYED INTO tasks (title, priority) VALUES ('Test Some Inserts', 1)", ['mysql'], 'INSERT tasks REPLACING ROWS'];
        yield 'parse_empty_row_insert #1' => ['INSERT INTO tb () VALUES (), ()', ['mysql'], 'INSERT tb'];
        yield 'parse_empty_row_insert #2' => ['INSERT INTO tb VALUES (), ()', ['mysql'], 'INSERT tb'];
        yield 'parse_insert_with_on_duplicate_update #1' => ["INSERT INTO permission_groups (name, description, perm_create, perm_read, perm_update, perm_delete) VALUES ('accounting_manager', 'Some description about the group', true, true, true, true) ON DUPLICATE KEY UPDATE description = VALUES(description), perm_create = VALUES(perm_create), perm_read = VALUES(perm_read), perm_update = VALUES(perm_update), perm_delete = VALUES(perm_delete)", ['mysql'], 'INSERT permission_groups SET description, perm_create, perm_read, perm_update, perm_delete'];
        yield 'parse_insert_with_numeric_prefix_column_name #1' => ['INSERT INTO s1.t1 (123col_$@length123) VALUES (67.654)', ['mysql'], 'INSERT s1.t1'];
        yield 'parse_update_with_joins #1' => ["UPDATE orders AS o JOIN customers AS c ON o.customer_id = c.id SET o.completed = true WHERE c.firstname = 'Peter'", ['mysql'], 'UPDATE orders SET completed'];
        yield 'parse_update_with_order_by #1' => ['UPDATE foo SET bar = false WHERE foo = true ORDER BY foo ASC', ['mysql'], 'UPDATE foo SET bar'];
        yield 'parse_update_with_order_by_and_limit #1' => ['UPDATE foo SET bar = false WHERE foo = true ORDER BY foo ASC LIMIT 10', ['mysql'], 'UPDATE foo SET bar'];
        yield 'parse_delete_with_order_by #1' => ['DELETE FROM customers ORDER BY id DESC', ['mysql'], 'DELETE customers'];
        yield 'parse_delete_with_limit #1' => ['DELETE FROM customers LIMIT 100', ['mysql'], 'DELETE customers'];
        yield 'parse_looks_like_single_line_comment #1' => ['UPDATE account SET balance=balance--1 WHERE account_id=5752', ['mysql'], 'UPDATE account SET balance'];
        yield 'parse_looks_like_single_line_comment #2' => ['UPDATE account SET balance = balance - -1 WHERE account_id = 5752', ['mysql'], 'UPDATE account SET balance'];
        yield 'parse_looks_like_single_line_comment #3' => ['UPDATE account SET balance=balance-- 1 WHERE account_id=5752', ['mysql'], 'UPDATE account SET balance'];
        yield 'parse_looks_like_single_line_comment #4' => ['UPDATE account SET balance = balance WHERE account_id = 5752', ['mysql'], 'UPDATE account SET balance'];
        yield 'test_variable_assignment_using_colon_equal #1' => ["UPDATE products SET price = @new_price := price * 1.1 WHERE category = 'Books'", ['mysql'], 'UPDATE products SET price'];
        yield 'test_optimizer_hints #1' => ['INSERT /*+ RESOURCE_GROUP(Batch) */ INTO t2 VALUES (2)', ['mysql'], 'INSERT t2'];
        yield 'test_optimizer_hints #2' => ["REPLACE /*+ foobar */ INTO test VALUES (1, 'Old', '2014-08-20 18:47:00')", ['mysql'], 'INSERT test REPLACING ROWS'];
        yield 'test_optimizer_hints #3' => ['UPDATE /*+ quux */ table_name SET column1 = 1 WHERE 1 = 1', ['mysql'], 'UPDATE table_name SET column1'];
        yield 'test_optimizer_hints #4' => ['DELETE /*+ foobar */ FROM table_name', ['mysql'], 'DELETE table_name'];
        yield 'test_optimizer_hints #5' => ['INSERT /*abc+ append */ INTO t2 VALUES (2)', ['mysql'], 'INSERT t2'];
        yield 'test_optimizer_hints #6' => ['UPDATE /*abc+ PARALLEL */ table_name SET column1 = 1', ['mysql'], 'UPDATE table_name SET column1'];
        yield 'test_optimizer_hints #7' => ['DELETE /*abc+ ENABLE_DML */ FROM table_name', ['mysql'], 'DELETE table_name'];
        yield 'parse_table_partition_selection #1' => ['UPDATE employees PARTITION (p0) SET salary = 1', ['mysql'], 'UPDATE employees SET salary'];
        yield 'parse_table_partition_selection #2' => ['DELETE FROM employees PARTITION (p0) WHERE id = 1', ['mysql'], 'DELETE employees'];
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function mysqlReads(): iterable
    {
        yield 'parse_json_table #1' => ['SELECT * FROM JSON_TABLE(\'[[1, 2], [3, 4]]\', \'$[*]\' COLUMNS(a INT PATH \'$[0]\', b INT PATH \'$[1]\')) AS t', ['mysql'], []];
        yield 'parse_json_table #2' => ['SELECT * FROM JSON_TABLE(\'["x", "y"]\', \'$[*]\' COLUMNS(a VARCHAR(20) PATH \'$\')) AS t', ['mysql'], []];
        yield 'parse_json_table #3' => ['SELECT * FROM JSON_TABLE(?, \'$[*]\' COLUMNS(a VARCHAR(20) PATH \'$\')) AS t', ['mysql'], []];
        yield 'parse_json_table #4' => ['SELECT * FROM JSON_TABLE(\'{"\'\'": [1,2,3]}\', \'$."\'\'"[*]\' COLUMNS(a VARCHAR(20) PATH \'$\')) AS t', ['mysql'], []];
        yield 'parse_json_table #5' => ['SELECT * FROM JSON_TABLE("[]", "$[*]" COLUMNS(a VARCHAR(20) PATH "$")) AS t', ['mysql'], []];
        yield 'parse_json_table #6' => ['SELECT * FROM JSON_TABLE(\'[{}, {"x":1}]\', \'$[*]\' COLUMNS(x INT EXISTS PATH \'$.x\')) AS t', ['mysql'], []];
        yield 'parse_json_table #7' => ['SELECT * FROM JSON_TABLE(\'[1,2]\', \'$[*]\' COLUMNS(x INT PATH \'$\' ERROR ON ERROR)) AS t', ['mysql'], []];
        yield 'parse_json_table #8' => ['SELECT * FROM JSON_TABLE(\'[1,2]\', \'$[*]\' COLUMNS(x INT PATH \'$\' ERROR ON EMPTY)) AS t', ['mysql'], []];
        yield 'parse_json_table #9' => ['SELECT * FROM JSON_TABLE(\'[1,2]\', \'$[*]\' COLUMNS(x INT PATH \'$\' ERROR ON EMPTY DEFAULT \'0\' ON ERROR)) AS t', ['mysql'], []];
        yield 'parse_json_table #10' => ['SELECT jt.* FROM JSON_TABLE(\'["Alice", "Bob", "Charlie"]\', \'$[*]\' COLUMNS(row_num FOR ORDINALITY, name VARCHAR(50) PATH \'$\')) AS jt', ['mysql'], []];
        yield 'parse_json_table #11' => ['SELECT * FROM JSON_TABLE(\'[ {"a": 1, "b": [11,111]}, {"a": 2, "b": [22,222]}, {"a":3}]\', \'$[*]\' COLUMNS(a INT PATH \'$.a\', NESTED PATH \'$.b[*]\' COLUMNS (b INT PATH \'$\'))) AS jt', ['mysql'], []];
        yield 'parse_json_table #12' => ['SELECT * FROM JSON_TABLE(\'[1,2]\', \'$[*]\' COLUMNS(x INT PATH \'$\' DEFAULT \'0\' ON EMPTY NULL ON ERROR)) AS t', ['mysql'], []];
        yield 'parse_straight_join #1' => ['SELECT a.*, b.* FROM table_a AS a STRAIGHT_JOIN table_b AS b ON a.b_id = b.id', ['mysql'], ['table_a', 'table_b']];
        yield 'parse_straight_join #2' => ['SELECT a.*, b.* FROM table_a STRAIGHT_JOIN table_b AS b ON a.b_id = b.id', ['mysql'], ['table_a', 'table_b']];
        yield 'parse_select_straight_join #1' => ['SELECT STRAIGHT_JOIN * FROM employees e JOIN dept_emp d ON e.emp_no = d.emp_no WHERE d.emp_no = 10001', ['mysql'], ['employees', 'dept_emp']];
        yield 'parse_select_straight_join #2' => ['SELECT STRAIGHT_JOIN e.emp_no, d.dept_no FROM employees e JOIN dept_emp d ON e.emp_no = d.emp_no', ['mysql'], ['employees', 'dept_emp']];
        yield 'parse_select_straight_join #3' => ['SELECT DISTINCT STRAIGHT_JOIN emp_no FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_straight_join #4' => ['SELECT * FROM employees', ['mysql'], ['employees']];
        yield 'test_optimizer_hints #8' => ['SELECT /*+ SET_VAR(target_partitions=1) */ * FROM (SELECT /*+ SET_VAR(target_partitions=8) */ * FROM t1 LIMIT 1) AS dt', ['mysql'], ['t1']];
        yield 'parse_table_partition_selection #3' => ['SELECT * FROM employees PARTITION (p0, p2)', ['mysql'], ['employees']];
        yield 'parse_table_partition_selection #4' => ['SELECT * FROM employees PARTITION (p0) AS e', ['mysql'], ['employees']];
        yield 'parse_table_partition_selection #5' => ['SELECT * FROM employees PARTITION (p0) JOIN departments PARTITION (p1) ON employees.dept_id = departments.id', ['mysql'], ['employees', 'departments']];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function mysqlUnsupportedReads(): iterable
    {
        yield 'parse_table_partition_selection #6' => ['SELECT * FROM employees PARTITION', ['mysql']];
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function postgresWrites(): iterable
    {
        yield 'parse_truncate_table #1' => ['TRUNCATE TABLE "users", "orders" RESTART IDENTITY RESTRICT', ['pgsql'], 'TRUNCATE users, orders'];
        yield 'parse_truncate_table #2' => ['TRUNCATE users, orders RESTART IDENTITY', ['pgsql'], 'TRUNCATE users, orders'];
        yield 'parse_pg_on_conflict_target_predicate #1' => ['INSERT INTO t (a, b) VALUES (1, 2) ON CONFLICT(a) WHERE b > 0 DO NOTHING', ['pgsql'], 'INSERT t'];
        yield 'parse_pg_on_conflict_target_predicate #2' => ['INSERT INTO t (a, b) VALUES (1, 2) ON CONFLICT(a) WHERE b > 0 DO UPDATE SET b = EXCLUDED.b', ['pgsql'], 'INSERT t SET b'];
        yield 'parse_pg_on_conflict_target_predicate #3' => ['INSERT INTO t (a, b) VALUES (1, 2) ON CONFLICT(a, c) WHERE b > 0 DO UPDATE SET b = EXCLUDED.b WHERE t.b < 10', ['pgsql'], 'INSERT t SET b'];
        yield 'parse_pg_on_conflict #1' => ["INSERT INTO distributors (did, dname) VALUES (5, 'Gizmo Transglobal'), (6, 'Associated Computing, Inc') ON CONFLICT(did) DO UPDATE SET dname = EXCLUDED.dname", ['pgsql'], 'INSERT distributors SET dname'];
        yield 'parse_pg_on_conflict #2' => ["INSERT INTO distributors (did, dname, area) VALUES (5, 'Gizmo Transglobal', 'Mars'), (6, 'Associated Computing, Inc', 'Venus') ON CONFLICT(did, area) DO UPDATE SET dname = EXCLUDED.dname, area = EXCLUDED.area", ['pgsql'], 'INSERT distributors SET dname, area'];
        yield 'parse_pg_on_conflict #3' => ["INSERT INTO distributors (did, dname) VALUES (5, 'Gizmo Transglobal'), (6, 'Associated Computing, Inc') ON CONFLICT DO NOTHING", ['pgsql'], 'INSERT distributors'];
        yield 'parse_pg_on_conflict #4' => ['INSERT INTO distributors (did, dname, dsize) VALUES (5, \'Gizmo Transglobal\', 1000), (6, \'Associated Computing, Inc\', 1010) ON CONFLICT(did) DO UPDATE SET dname = $1 WHERE dsize > $2', ['pgsql'], 'INSERT distributors SET dname'];
        yield 'parse_pg_on_conflict #5' => ['INSERT INTO distributors (did, dname, dsize) VALUES (5, \'Gizmo Transglobal\', 1000), (6, \'Associated Computing, Inc\', 1010) ON CONFLICT ON CONSTRAINT distributors_did_pkey DO UPDATE SET dname = $1 WHERE dsize > $2', ['pgsql'], 'INSERT distributors SET dname'];
        yield 'parse_pg_returning #1' => ["INSERT INTO distributors (did, dname) VALUES (DEFAULT, 'XYZ Widgets') RETURNING did", ['pgsql'], 'INSERT distributors'];
        yield 'parse_pg_returning #2' => ["UPDATE weather SET temp_lo = temp_lo + 1, temp_hi = temp_lo + 15, prcp = DEFAULT WHERE city = 'San Francisco' AND date = '2003-07-03' RETURNING temp_lo AS lo, temp_hi AS hi, prcp", ['pgsql'], 'UPDATE weather SET temp_lo, temp_hi, prcp'];
        yield 'parse_pg_returning #3' => ["DELETE FROM tasks WHERE status = 'DONE' RETURNING *", ['pgsql'], 'DELETE tasks'];
        yield 'parse_delimited_identifiers #2' => ['UPDATE foo SET "bar" = 5', ['pgsql'], 'UPDATE foo SET bar'];
        yield 'parse_update_has_keyword #1' => ['UPDATE test SET name=$1, value=$2, where=$3, create=$4, is_default=$5, classification=$6, sort=$7 WHERE id=$8', ['pgsql'], 'UPDATE test SET name, value, where, create, is_default, classification, sort'];
        yield 'parse_update_has_keyword #2' => ['UPDATE test SET name = $1, value = $2, where = $3, create = $4, is_default = $5, classification = $6, sort = $7 WHERE id = $8', ['pgsql'], 'UPDATE test SET name, value, where, create, is_default, classification, sort'];
        yield 'parse_truncate #1' => ['TRUNCATE db.table_name', ['pgsql'], 'TRUNCATE db.table_name'];
        yield 'parse_truncate_with_options #1' => ['TRUNCATE TABLE ONLY db.table_name RESTART IDENTITY CASCADE', ['pgsql'], 'TRUNCATE db.table_name'];
        yield 'parse_truncate_with_table_list #1' => ['TRUNCATE TABLE db.table_name, db.other_table_name RESTART IDENTITY CASCADE', ['pgsql'], 'TRUNCATE db.table_name, db.other_table_name'];
        yield 'parse_truncate_with_descendant #1' => ['TRUNCATE TABLE t *', ['pgsql'], 'TRUNCATE t'];
        yield 'parse_truncate_with_descendant #2' => ['TRUNCATE TABLE ONLY parent, child *, grandchild RESTART IDENTITY', ['pgsql'], 'TRUNCATE parent, child, grandchild'];
        yield 'test_simple_postgres_insert_with_alias #1' => ['INSERT INTO test_tables AS test_table (id, a) VALUES (DEFAULT, 123)', ['pgsql'], 'INSERT test_tables'];
        yield 'test_simple_insert_with_quoted_alias #1' => ['INSERT INTO test_tables AS "Test_Table" (id, a) VALUES (DEFAULT, \'0123\')', ['pgsql'], 'INSERT test_tables'];
        yield 'parse_merge_do_nothing #1' => ['MERGE INTO target USING source ON target.id = source.id WHEN MATCHED THEN DO NOTHING WHEN NOT MATCHED THEN DO NOTHING', ['pgsql'], 'MERGE target'];
        yield 'parse_insert_overriding #1' => ["INSERT INTO t (id, a) OVERRIDING SYSTEM VALUE VALUES (1, 'x')", ['pgsql'], 'INSERT t'];
        yield 'parse_insert_overriding #2' => ["INSERT INTO t (id, a) OVERRIDING USER VALUE VALUES (1, 'x')", ['pgsql'], 'INSERT t'];
        yield 'parse_insert_overriding #3' => ['INSERT INTO t (id, a) OVERRIDING SYSTEM VALUE SELECT id, a FROM u', ['pgsql'], 'INSERT t'];
        yield 'parse_insert_overriding #4' => ['INSERT INTO t (id) OVERRIDING USER VALUE VALUES (1) ON CONFLICT DO NOTHING RETURNING id', ['pgsql'], 'INSERT t'];
        yield 'parse_insert_overriding #5' => ["INSERT INTO t OVERRIDING SYSTEM VALUE VALUES (1, 'x')", ['pgsql'], 'INSERT t'];
        yield 'parse_insert_overriding #6' => ['INSERT INTO t OVERRIDING USER VALUE SELECT * FROM u', ['pgsql'], 'INSERT t'];
        yield 'parse_insert_overriding #7' => ['INSERT INTO t AS x (id) OVERRIDING SYSTEM VALUE VALUES (1)', ['pgsql'], 'INSERT t'];
        yield 'parse_insert_overriding #8' => ['INSERT INTO t (id) VALUES (1)', ['pgsql'], 'INSERT t'];
        yield 'parse_insert_by_name_keywords_as_table_and_alias #1' => ['INSERT INTO BY NAME SELECT 1 AS a', ['pgsql'], 'INSERT BY'];
        yield 'parse_table_inheritance_markers #1' => ['UPDATE ONLY t SET id = 1', ['pgsql'], 'UPDATE t SET id'];
        yield 'parse_table_inheritance_markers #2' => ['UPDATE ONLY (t) SET id = 1', ['pgsql'], 'UPDATE t SET id'];
        yield 'parse_table_inheritance_markers #3' => ['UPDATE t * SET id = 1', ['pgsql'], 'UPDATE t SET id'];
        yield 'parse_table_inheritance_markers #4' => ['DELETE FROM ONLY t', ['pgsql'], 'DELETE t'];
        yield 'parse_table_inheritance_markers #5' => ['DELETE FROM ONLY (t)', ['pgsql'], 'DELETE t'];
        yield 'parse_table_inheritance_markers #6' => ['DELETE FROM t *', ['pgsql'], 'DELETE t'];
        yield 'parse_table_inheritance_markers #7' => ['UPDATE t* SET id = 1', ['pgsql'], 'UPDATE t SET id'];
        yield 'parse_table_inheritance_markers #8' => ['DELETE FROM t*', ['pgsql'], 'DELETE t'];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function postgresUnsupportedWrites(): iterable
    {
        yield 'parse_update_in_with_subquery #1' => ['WITH "result" AS (UPDATE "Hero" SET "name" = \'Captain America\', "number_of_movies" = "number_of_movies" + 1 WHERE "secret_identity" = \'Sam Wilson\' RETURNING "id", "name", "secret_identity", "number_of_movies") SELECT * FROM "result"', ['pgsql']];
        yield 'test_complex_postgres_insert_with_alias #1' => ['WITH existing AS (SELECT test_table.id FROM test_tables AS test_table WHERE (a = 12) AND (b = 34)), inserted AS (INSERT INTO test_tables AS test_table (id, a, b, c) VALUES (DEFAULT, 56, 78, 90) ON CONFLICT(a, b) DO UPDATE SET c = EXCLUDED.c WHERE (test_table.c <> EXCLUDED.c)) SELECT c FROM existing', ['pgsql']];
        yield 'parse_table_inheritance_markers #25' => ['UPDATE t * SET id = 1', ['mysql', 'sqlite', 'sqlsrv']];
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function postgresReads(): iterable
    {
        yield 'parse_json_table_is_not_reserved #1' => ['SELECT * FROM JSON_TABLE', ['pgsql'], ['JSON_TABLE']];
        yield 'parse_join_constraint_unnest_alias #1' => ['SELECT * FROM t1 JOIN UNNEST(t1.a) AS f ON c1 = c2', ['pgsql'], ['t1']];
        yield 'test_table_function_with_ordinality #1' => ['SELECT * FROM generate_series(1, 10) WITH ORDINALITY AS t', ['pgsql'], []];
        yield 'test_lateral_function_with_ordinality_and_column_aliases #1' => ['SELECT * FROM tbl, LATERAL json_array_elements(c1::JSON) WITH ORDINALITY AS t (c1, index)', ['pgsql'], ['tbl']];
        yield 'test_table_unnest_with_ordinality #1' => ['SELECT * FROM UNNEST([10, 20, 30]) WITH ORDINALITY AS t', ['pgsql'], []];
        yield 'parse_right_deep_join_chain #1' => ['SELECT * FROM t0 INNER JOIN t1 INNER JOIN t2 ON true ON true', ['pgsql'], ['t0', 't1', 't2']];
        yield 'parse_right_deep_join_chain #2' => ['SELECT * FROM t0 INNER JOIN (t1 INNER JOIN t2 ON true) ON true', ['pgsql'], ['t0', 't1', 't2']];
        yield 'parse_right_deep_join_chain #3' => ['SELECT * FROM t0 INNER JOIN t1 INNER JOIN t2 INNER JOIN t3 ON true ON true ON true', ['pgsql'], ['t0', 't1', 't2', 't3']];
        yield 'parse_right_deep_join_chain #4' => ['SELECT * FROM t0 INNER JOIN (t1 INNER JOIN (t2 INNER JOIN t3 ON true) ON true) ON true', ['pgsql'], ['t0', 't1', 't2', 't3']];
        yield 'parse_right_deep_join_chain #5' => ['SELECT * FROM t0 NATURAL JOIN t1 INNER JOIN t2 ON true', ['pgsql'], ['t0', 't1', 't2']];
        yield 'parse_non_reserved_keywords_as_table_alias #1' => ['SELECT * FROM tbl_name AS cluster JOIN tbl_name_2 ON cluster.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #2' => ['SELECT * FROM tbl_name AS distribute JOIN tbl_name_2 ON distribute.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #3' => ['SELECT * FROM tbl_name AS explain JOIN tbl_name_2 ON explain.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #4' => ['SELECT * FROM tbl_name AS minus JOIN tbl_name_2 ON minus.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #5' => ['SELECT * FROM tbl_name AS sample JOIN tbl_name_2 ON sample.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #6' => ['SELECT * FROM tbl_name AS sort JOIN tbl_name_2 ON sort.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #7' => ['SELECT * FROM tbl_name AS start JOIN tbl_name_2 ON start.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #8' => ['SELECT * FROM tbl_name AS top JOIN tbl_name_2 ON top.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #9' => ['SELECT * FROM tbl_name AS view JOIN tbl_name_2 ON view.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #10' => ['SELECT * FROM tbl_name cluster JOIN tbl_name_2 ON cluster.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #11' => ['SELECT * FROM tbl_name distribute JOIN tbl_name_2 ON distribute.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #12' => ['SELECT * FROM tbl_name explain JOIN tbl_name_2 ON explain.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #13' => ['SELECT * FROM tbl_name minus JOIN tbl_name_2 ON minus.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #14' => ['SELECT * FROM tbl_name sample JOIN tbl_name_2 ON sample.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #15' => ['SELECT * FROM tbl_name sort JOIN tbl_name_2 ON sort.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #16' => ['SELECT * FROM tbl_name start JOIN tbl_name_2 ON start.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #17' => ['SELECT * FROM tbl_name top JOIN tbl_name_2 ON top.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #18' => ['SELECT * FROM tbl_name view JOIN tbl_name_2 ON view.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_join_using_alias #1' => ['SELECT * FROM t1 JOIN t2 USING (id) AS joined_cols', ['pgsql'], ['t1', 't2']];
        yield 'parse_join_using_alias #2' => ['SELECT * FROM t1 JOIN t2 USING(id) AS joined_cols', ['pgsql'], ['t1', 't2']];
        yield 'parse_string_literal_alias_rejected #3' => ['SELECT a AS "x" FROM t AS "y"', ['pgsql'], ['t']];
        yield 'parse_table_inheritance_markers #9' => ['SELECT * FROM ONLY t', ['pgsql'], ['t']];
        yield 'parse_table_inheritance_markers #10' => ['SELECT * FROM ONLY t AS x', ['pgsql'], ['t']];
        yield 'parse_table_inheritance_markers #11' => ['SELECT * FROM ONLY (t)', ['pgsql'], ['t']];
        yield 'parse_table_inheritance_markers #12' => ['SELECT * FROM ONLY (app.t) x', ['pgsql'], ['app.t']];
        yield 'parse_table_inheritance_markers #13' => ['SELECT * FROM t *', ['pgsql'], ['t']];
        yield 'parse_table_inheritance_markers #14' => ['SELECT * FROM app.t * AS x', ['pgsql'], ['app.t']];
        yield 'parse_table_inheritance_markers #15' => ['SELECT * FROM "only"', ['pgsql'], ['only']];
        yield 'parse_table_inheritance_markers #16' => ['SELECT * FROM ONLY a JOIN b * ON a.id = b.id', ['pgsql'], ['a', 'b']];
        yield 'parse_table_inheritance_markers #17' => ['SELECT * FROM t*', ['pgsql'], ['t']];
        yield 'parse_table_inheritance_markers #18' => ['SELECT * FROM ONLY(t)', ['pgsql'], ['t']];
        yield 'display_table_inheritance_propagates_write_errors #1' => ['SELECT * FROM ONLY (t)', ['pgsql'], ['t']];
        yield 'display_table_inheritance_propagates_write_errors #2' => ['SELECT * FROM ONLY t', ['pgsql'], ['t']];
        yield 'display_table_inheritance_propagates_write_errors #3' => ['SELECT * FROM t *', ['pgsql'], ['t']];
        yield 'display_table_inheritance_propagates_write_errors #4' => ['SELECT * FROM t', ['pgsql'], ['t']];
        yield 'parse_limit_after_locking_clause #1' => ['SELECT * FROM t ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 5', ['pgsql'], ['t']];
        yield 'parse_limit_after_locking_clause #2' => ['SELECT * FROM t FOR UPDATE LIMIT 5', ['pgsql'], ['t']];
        yield 'parse_limit_after_locking_clause #3' => ['SELECT * FROM t ORDER BY id LIMIT 5 FOR UPDATE SKIP LOCKED', ['pgsql'], ['t']];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function postgresUnsupportedReads(): iterable
    {
        yield 'parse_stage_table_factor_rejected #1' => ['SELECT * FROM @stage', ['pgsql']];
        yield 'parse_string_literal_alias_rejected #1' => ["SELECT a FROM t 'x'", ['pgsql']];
        yield 'parse_string_literal_alias_rejected #2' => ["SELECT a FROM t AS 'x'", ['pgsql']];
        yield 'parse_table_inheritance_markers #19' => ['SELECT * FROM ONLY', ['pgsql']];
        yield 'parse_table_inheritance_markers #20' => ['SELECT * FROM ONLY t *', ['pgsql']];
        yield 'parse_table_inheritance_markers #21' => ['SELECT * FROM ONLY (t *)', ['pgsql']];
        yield 'parse_table_inheritance_markers #22' => ['SELECT * FROM ONLY (t', ['pgsql']];
        yield 'parse_table_inheritance_markers #23' => ['SELECT * FROM ONLY ()', ['pgsql']];
        yield 'parse_table_inheritance_markers #24' => ['SELECT * FROM t *', ['mysql', 'sqlite', 'sqlsrv']];
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function sqliteWrites(): iterable
    {
        yield 'parse_update_tuple_row_values #1' => ['UPDATE x SET (a, b) = (1, 2)', ['sqlite'], 'UPDATE x SET a, b'];
        yield 'test_update_delete_limit #1' => ['UPDATE foo SET bar = 1 LIMIT 99', ['sqlite'], 'UPDATE foo SET bar'];
        yield 'test_update_delete_limit #2' => ['DELETE FROM foo LIMIT 99', ['sqlite'], 'DELETE foo'];
        yield 'parse_update_set_double_eq #1' => ['UPDATE t SET a == 1', ['sqlite'], 'UPDATE t SET a'];
        yield 'parse_update_set_double_eq #2' => ['UPDATE t SET a = 1', ['sqlite'], 'UPDATE t SET a'];
        yield 'parse_update_set_double_eq #3' => ['UPDATE t SET a == 1, b == 2', ['sqlite'], 'UPDATE t SET a, b'];
        yield 'parse_update_set_double_eq #4' => ['UPDATE t SET a = 1, b = 2', ['sqlite'], 'UPDATE t SET a, b'];
        yield 'parse_comma_join_constraint #1' => ['UPDATE t SET a = 1 FROM u, v ON u.a = v.a', ['sqlite'], 'UPDATE t SET a'];
        yield 'parse_comma_join_constraint #2' => ['UPDATE t SET a = 1 FROM u JOIN v ON u.a = v.a', ['sqlite'], 'UPDATE t SET a'];
        yield 'parse_indexed_by #1' => ['DELETE FROM t INDEXED BY i WHERE a = 1', ['sqlite'], 'DELETE t'];
        yield 'parse_indexed_by #2' => ['UPDATE t INDEXED BY i SET a = 2 WHERE a = 1', ['sqlite'], 'UPDATE t SET a'];
        yield 'parse_on_conflict_target_predicate #1' => ['INSERT INTO t VALUES (1, 2, 3) ON CONFLICT(a) WHERE b > 0 DO NOTHING', ['sqlite'], 'INSERT t'];
        yield 'test_dollar_sign_in_identifier #1' => ['INSERT INTO t$ VALUES (1)', ['sqlite'], 'INSERT t$'];
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function sqliteReads(): iterable
    {
        yield 'parse_single_quoted_identified #1' => ["SELECT 't'.*, t.'x' FROM 't'", ['sqlite'], ['t']];
        yield 'parse_in_table_keyword_names #1' => ['SELECT 1 IN view', ['sqlite'], ['view']];
        yield 'parse_in_table_keyword_names #2' => ['SELECT 1 IN offset', ['sqlite'], ['offset']];
        yield 'parse_in_table_keyword_names #3' => ['SELECT 1 IN key', ['sqlite'], ['key']];
        yield 'parse_in_table_keyword_names #4' => ['SELECT 1 IN abort', ['sqlite'], ['abort']];
        yield 'parse_in_table_keyword_names #5' => ['SELECT 1 IN "from"', ['sqlite'], ['from']];
        yield 'parse_comma_join_constraint #3' => ['SELECT * FROM t JOIN u ON t.a = u.a', ['sqlite'], ['t', 'u']];
        yield 'parse_comma_join_constraint #4' => ['SELECT * FROM t, u USING (a)', ['sqlite'], ['t', 'u']];
        yield 'parse_comma_join_constraint #5' => ['SELECT * FROM t JOIN u USING(a)', ['sqlite'], ['t', 'u']];
        yield 'parse_comma_join_constraint #6' => ['SELECT * FROM t, u ON t.a = u.a, v ON v.a = u.a', ['sqlite'], ['t', 'u', 'v']];
        yield 'parse_comma_join_constraint #7' => ['SELECT * FROM t JOIN u ON t.a = u.a JOIN v ON v.a = u.a', ['sqlite'], ['t', 'u', 'v']];
        yield 'parse_comma_join_constraint #8' => ['SELECT * FROM t, u ON t.a = u.a JOIN v ON v.a = u.a', ['sqlite'], ['t', 'u', 'v']];
        yield 'parse_comma_join_constraint #9' => ['SELECT * FROM t, u', ['sqlite'], ['t', 'u']];
        yield 'parse_comma_join_constraint #13' => ['SELECT * FROM t, u USING (a)', ['mysql', 'pgsql', 'sqlsrv'], ['t', 'u']];
        yield 'parse_parenthesized_table_factor #1' => ['SELECT * FROM (t)', ['sqlite'], ['t']];
        yield 'parse_parenthesized_table_factor #2' => ['SELECT * FROM t', ['sqlite'], ['t']];
        yield 'parse_parenthesized_table_factor #3' => ['SELECT * FROM ((t))', ['sqlite'], ['t']];
        yield 'parse_parenthesized_table_factor #4' => ['SELECT * FROM (t) AS a', ['sqlite'], ['t']];
        yield 'parse_parenthesized_table_factor #5' => ['SELECT * FROM t AS a', ['sqlite'], ['t']];
        yield 'parse_parenthesized_table_factor #6' => ['SELECT * FROM (t AS a)', ['sqlite'], ['t']];
        yield 'parse_parenthesized_table_factor #7' => ['SELECT * FROM ((SELECT 1) AS s)', ['sqlite'], []];
        yield 'parse_parenthesized_table_factor #8' => ['SELECT * FROM (SELECT 1) AS s', ['sqlite'], []];
        yield 'parse_parenthesized_table_factor #9' => ['SELECT * FROM f(), (t)', ['sqlite'], ['t']];
        yield 'parse_parenthesized_table_factor #10' => ['SELECT * FROM f(), t', ['sqlite'], ['t']];
        yield 'parse_indexed_by #3' => ['SELECT * FROM t INDEXED BY i WHERE a = 1', ['sqlite'], ['t']];
        yield 'parse_indexed_by #4' => ['SELECT * FROM t NOT INDEXED', ['sqlite'], ['t']];
        yield 'parse_indexed_by #5' => ['SELECT * FROM t AS x NOT INDEXED', ['sqlite'], ['t']];
        yield 'parse_indexed_by #6' => ['SELECT * FROM t INDEXED BY i, t AS u NOT INDEXED', ['sqlite'], ['t', 't']];
        yield 'parse_indexed_by #7' => ['SELECT * FROM t AS x INDEXED BY i WHERE a = 1', ['sqlite'], ['t']];
        yield 'parse_indexed_by #8' => ['SELECT * FROM t INDEXED BY i', ['sqlite'], ['t']];
        yield 'display_index_directives_propagates_formatter_errors #1' => ['SELECT * FROM t INDEXED BY idx_b', ['sqlite'], ['t']];
        yield 'display_index_directives_propagates_formatter_errors #2' => ['SELECT * FROM t NOT INDEXED', ['sqlite'], ['t']];
        yield 'test_dollar_sign_in_identifier #2' => ['SELECT a$ FROM t', ['sqlite'], ['t']];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function sqliteUnsupportedReads(): iterable
    {
        yield 'parse_comma_join_constraint #10' => ['SELECT * FROM t, u USING a', ['sqlite']];
        yield 'parse_comma_join_constraint #11' => ['SELECT * FROM t, u ON t.a = u.a CROSS', ['sqlite']];
        yield 'parse_comma_join_constraint #12' => ['SELECT * FROM t, u ON t.a = u.a', ['mysql', 'pgsql', 'sqlsrv']];
        yield 'parse_comma_join_constraint #14' => ['SELECT * FROM t, u ON t.a = u.a, v ON v.a = u.a', ['mysql', 'pgsql', 'sqlsrv']];
        yield 'parse_comma_join_constraint #15' => ['SELECT * FROM t, u ON t.a = u.a JOIN v ON v.a = u.a', ['mysql', 'pgsql', 'sqlsrv']];
        yield 'parse_parenthesized_table_factor #11' => ['SELECT * FROM (t)', ['mysql', 'pgsql', 'sqlsrv']];
        yield 'parse_indexed_by #9' => ['SELECT * FROM t INDEXED BY i', ['mysql', 'pgsql', 'sqlsrv']];
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function mssqlWrites(): iterable
    {
        yield 'parse_mssql_insert_with_output #1' => ["INSERT INTO customers (name, email) OUTPUT INSERTED.id, INSERTED.name VALUES ('John', 'john@example.com')", ['sqlsrv'], 'INSERT customers'];
        yield 'parse_mssql_insert_with_output_into #1' => ["INSERT INTO customers (name, email) OUTPUT INSERTED.id, INSERTED.name INTO @new_ids VALUES ('John', 'john@example.com')", ['sqlsrv'], 'INSERT customers'];
        yield 'parse_mssql_delete_with_output #1' => ['DELETE FROM customers OUTPUT DELETED.* WHERE id = 1', ['sqlsrv'], 'DELETE customers'];
        yield 'parse_mssql_delete_with_output_into #1' => ['DELETE FROM customers OUTPUT DELETED.id, DELETED.name INTO @deleted_rows WHERE active = 0', ['sqlsrv'], 'DELETE customers'];
        yield 'parse_mssql_update_with_output #1' => ["UPDATE employees SET salary = salary * 1.1 OUTPUT INSERTED.id, DELETED.salary, INSERTED.salary WHERE department = 'Engineering'", ['sqlsrv'], 'UPDATE employees SET salary'];
        yield 'parse_mssql_update_with_output_into #1' => ["UPDATE employees SET salary = salary * 1.1 OUTPUT INSERTED.id, DELETED.salary, INSERTED.salary INTO @changes WHERE department = 'Engineering'", ['sqlsrv'], 'UPDATE employees SET salary'];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function mssqlUnsupportedWrites(): iterable
    {
        yield 'parse_mssql_merge_with_output #1' => ['MERGE dso.products AS t USING dsi.products AS s ON s.ProductID = t.ProductID WHEN MATCHED AND NOT (t.ProductName = s.ProductName OR (ISNULL(t.ProductName, s.ProductName) IS NULL)) THEN UPDATE SET t.ProductName = s.ProductName WHEN NOT MATCHED BY TARGET THEN INSERT (ProductID, ProductName) VALUES (s.ProductID, s.ProductName) WHEN NOT MATCHED BY SOURCE THEN DELETE OUTPUT $action, deleted.ProductID INTO dsi.temp_products', ['sqlsrv']];
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function mssqlReads(): iterable
    {
        yield 'parse_mssql_identifiers #1' => ['SELECT @@version, _foo$123 FROM ##temp', ['sqlsrv'], ['##temp']];
        yield 'parse_table_time_travel #1' => ["SELECT 1 FROM t1 FOR SYSTEM_TIME AS OF '2023-08-18 23:08:18'", ['sqlsrv'], ['t1']];
        yield 'parse_mssql_delimited_identifiers #1' => ['SELECT [a.b!] [FROM] FROM foo [WHERE]', ['sqlsrv'], ['foo']];
        yield 'parse_mssql_delimited_identifiers #2' => ['SELECT [a.b!] AS [FROM] FROM foo [WHERE]', ['sqlsrv'], ['foo']];
        yield 'parse_mssql_apply_join #1' => ['SELECT * FROM sys.dm_exec_query_stats AS deqs CROSS APPLY sys.dm_exec_query_plan(deqs.plan_handle)', ['sqlsrv'], ['sys.dm_exec_query_stats']];
        yield 'parse_mssql_apply_join #2' => ['SELECT * FROM sys.dm_exec_query_stats AS deqs OUTER APPLY sys.dm_exec_query_plan(deqs.plan_handle)', ['sqlsrv'], ['sys.dm_exec_query_stats']];
        yield 'parse_mssql_apply_join #3' => ['SELECT * FROM foo OUTER APPLY (SELECT foo.x + 1) AS bar', ['sqlsrv'], ['foo']];
        yield 'parse_mssql_openjson #1' => ['SELECT B.kind, B.id_list FROM t_test_table AS A CROSS APPLY OPENJSON(A.param, \'$.config\') WITH (kind VARCHAR(20) \'$.kind\', [id_list] NVARCHAR(MAX) \'$.id_list\' AS JSON) AS B', ['sqlsrv'], ['t_test_table']];
        yield 'parse_mssql_openjson #2' => ['SELECT B.kind, B.id_list FROM t_test_table AS A CROSS APPLY OPENJSON(A.param) WITH (kind VARCHAR(20) \'$.kind\', [id_list] NVARCHAR(MAX) \'$.id_list\' AS JSON) AS B', ['sqlsrv'], ['t_test_table']];
        yield 'parse_mssql_openjson #3' => ['SELECT B.kind, B.id_list FROM t_test_table AS A CROSS APPLY OPENJSON(A.param) WITH (kind VARCHAR(20), [id_list] NVARCHAR(MAX)) AS B', ['sqlsrv'], ['t_test_table']];
        yield 'parse_mssql_openjson #4' => ['SELECT B.kind, B.id_list FROM t_test_table AS A CROSS APPLY OPENJSON(A.param, \'$.config\') AS B', ['sqlsrv'], ['t_test_table']];
        yield 'parse_mssql_openjson #5' => ['SELECT B.kind, B.id_list FROM t_test_table AS A CROSS APPLY OPENJSON(A.param) AS B', ['sqlsrv'], ['t_test_table']];
        yield 'parse_table_name_in_square_brackets #1' => ['SELECT [a column] FROM [a schema].[a table]', ['sqlsrv'], ['a schema.a table']];
        yield 'parse_mssql_table_identifier_with_default_schema #1' => ['SELECT * FROM mydatabase..MyTable', ['sqlsrv'], ['mydatabase..MyTable']];
        yield 'parse_non_reserved_keyword_table_names #1' => ['SELECT * FROM limit', ['sqlsrv'], ['limit']];
        yield 'parse_non_reserved_keyword_table_names #2' => ['SELECT * FROM t JOIN limit ON true', ['sqlsrv'], ['t', 'limit']];
        yield 'parse_quoted_statement_keyword_aliases #1' => ['SELECT * FROM t [PRINT]', ['sqlsrv'], ['t']];
        yield 'parse_quoted_statement_keyword_aliases #2' => ['SELECT * FROM t AS [PRINT]', ['sqlsrv'], ['t']];
        yield 'parse_for_clause #1' => ['SELECT a FROM t FOR JSON PATH', ['sqlsrv'], ['t']];
        yield 'parse_for_clause #2' => ['SELECT c FROM t FOR JSON AUTO, WITHOUT_ARRAY_WRAPPER', ['sqlsrv'], ['t']];
        yield 'parse_for_clause #3' => ['SELECT 2 FROM t FOR XML AUTO', ['sqlsrv'], ['t']];
        yield 'parse_for_clause #4' => ['SELECT * FROM t WHERE x FOR XML AUTO, ELEMENTS', ['sqlsrv'], ['t']];
        yield 'parse_for_clause #5' => ["SELECT y FROM t FOR XML PATH('x'), ROOT('y'), ELEMENTS", ['sqlsrv'], ['t']];
        yield 'parse_for_clause #6' => ['SELECT * FROM t FOR BROWSE', ['sqlsrv'], ['t']];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function mssqlUnsupportedReads(): iterable
    {
        yield 'parse_table_time_travel #2' => ["SELECT 1 FROM t1 FOR SYSTEM TIME AS OF 'some_timestamp'", ['sqlsrv']];
        yield 'test_sql_keywords_as_table_aliases #1' => ['SELECT * FROM tbl IF', ['sqlsrv']];
        yield 'test_sql_keywords_as_table_aliases #2' => ['SELECT * FROM tbl AS IF', ['sqlsrv']];
        yield 'test_sql_keywords_as_table_aliases #3' => ['SELECT * FROM tbl ELSE', ['sqlsrv']];
        yield 'test_sql_keywords_as_table_aliases #4' => ['SELECT * FROM tbl AS ELSE', ['sqlsrv']];
        yield 'test_tsql_statement_keywords_not_implicit_aliases #1' => ['select * from t declare @x as int', ['sqlsrv']];
        yield 'test_tsql_statement_keywords_not_implicit_aliases #2' => ['select * from t drop table t', ['sqlsrv']];
        yield 'test_tsql_statement_keywords_not_implicit_aliases #3' => ['select * from t create table u (id int)', ['sqlsrv']];
        yield 'test_tsql_statement_keywords_not_implicit_aliases #4' => ['select * from t exec sp_who', ['sqlsrv']];
        yield 'dont_parse_trailing_for #1' => ['SELECT * FROM foo FOR', ['sqlsrv']];
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function ladaWrites(): iterable
    {
        yield 'a column named output or returning in an assigned value' => ['UPDATE jobs SET a = output, b = returning, c = 2', ['mysql'], 'UPDATE jobs SET a, b, c'];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function ladaUnsupportedWrites(): iterable
    {
        yield 'OUTPUT INTO a table from an INSERT' => ['INSERT INTO t (a) OUTPUT inserted.a INTO audit (a) VALUES (1)', ['sqlsrv']];
        yield 'OUTPUT INTO a table from an UPDATE' => ['UPDATE t SET a = 1 OUTPUT inserted.a INTO audit WHERE b = 2', ['sqlsrv']];
        yield 'OUTPUT INTO a table from a DELETE' => ['DELETE FROM t OUTPUT deleted.a INTO dbo.audit WHERE b = 2', ['sqlsrv']];
        yield 'SQLite UPDATE OR REPLACE' => ['UPDATE OR REPLACE t SET a = 1', ['sqlite']];
        yield 'a MERGE into a derived table' => ['MERGE INTO (SELECT * FROM t) AS x USING s ON x.id = s.id WHEN MATCHED THEN DELETE', ['sqlsrv']];
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function ladaReads(): iterable
    {
        yield 'a parenthesized set operation joined to a table' => ['SELECT * FROM ((SELECT x FROM foo) UNION (SELECT y FROM bar)) AS t1 NATURAL JOIN t2', self::ALL, ['t2', 'foo', 'bar']];
        yield 'TABLE query bodies in a subquery and a set operation' => ['SELECT * FROM t WHERE x IN (TABLE u) UNION TABLE v', ['mysql', 'pgsql'], ['t', 'u', 'v']];
        yield 'a lateral subquery with a join' => ['SELECT * FROM a LEFT JOIN LATERAL (SELECT * FROM b JOIN c ON 1 = 1) x ON true', ['mysql', 'pgsql'], ['a', 'b', 'c']];
        yield 'a temporal table followed by a join' => ["SELECT * FROM t FOR SYSTEM_TIME AS OF '2020-01-01' x JOIN u ON u.id = x.id", ['sqlsrv'], ['t', 'u']];
        yield 'a table sample after a derived table alias' => ['SELECT * FROM (SELECT * FROM t) AS x TABLESAMPLE SYSTEM (10), u', ['pgsql'], ['u', 't']];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function ladaUnsupportedReads(): iterable
    {
        yield 'a temporal clause other than AS OF' => ['SELECT * FROM t FOR SYSTEM_TIME ALL, u', ['sqlsrv']];
    }

    /**
     * @param  list<string>  $drivers
     */
    #[DataProvider('commonWrites')]
    #[DataProvider('mysqlWrites')]
    #[DataProvider('postgresWrites')]
    #[DataProvider('sqliteWrites')]
    #[DataProvider('mssqlWrites')]
    #[DataProvider('ladaWrites')]
    public function testWritesNameTheirTargetsAndAssignedColumns(string $sql, array $drivers, string $expected): void
    {
        foreach ($drivers as $driver) {
            $this->assertSame($expected, self::describe(Parser::forSql(Dialects::forDriver($driver), $sql)->parseWriteStatement()), $driver);
        }
    }

    /**
     * @param  list<string>  $drivers
     */
    #[DataProvider('commonUnsupportedWrites')]
    #[DataProvider('postgresUnsupportedWrites')]
    #[DataProvider('mssqlUnsupportedWrites')]
    #[DataProvider('ladaUnsupportedWrites')]
    public function testWritesThatCannotBeFullyUnderstoodThrow(string $sql, array $drivers): void
    {
        foreach ($drivers as $driver) {
            $this->assertThrowsParserException(static fn () => Parser::forSql(Dialects::forDriver($driver), $sql)->parseWriteStatement(), $driver);
        }
    }

    /**
     * @param  list<string>  $drivers
     * @param  list<string>  $expected
     */
    #[DataProvider('commonReads')]
    #[DataProvider('mysqlReads')]
    #[DataProvider('postgresReads')]
    #[DataProvider('sqliteReads')]
    #[DataProvider('mssqlReads')]
    #[DataProvider('ladaReads')]
    public function testReadsNameEveryRelation(string $sql, array $drivers, array $expected): void
    {
        foreach ($drivers as $driver) {
            $tables = [];
            foreach (Parser::forSql(Dialects::forDriver($driver), $sql)->readRelations() as $relation) {
                foreach ($relation->tables() as $table) {
                    $this->assertNotNull($table->name);
                    $tables[] = implode('.', $table->name);
                }
            }

            $this->assertSame($expected, $tables, $driver);
        }
    }

    /**
     * @param  list<string>  $drivers
     */
    #[DataProvider('commonUnsupportedReads')]
    #[DataProvider('mysqlUnsupportedReads')]
    #[DataProvider('postgresUnsupportedReads')]
    #[DataProvider('sqliteUnsupportedReads')]
    #[DataProvider('mssqlUnsupportedReads')]
    #[DataProvider('ladaUnsupportedReads')]
    public function testReadsOfUnknownShapeThrow(string $sql, array $drivers): void
    {
        foreach ($drivers as $driver) {
            $this->assertThrowsParserException(static fn () => Parser::forSql(Dialects::forDriver($driver), $sql)->readRelations(), $driver);
        }
    }

    private function assertThrowsParserException(callable $parse, string $driver): void
    {
        try {
            $parse();
        } catch (ParserException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("Expected a ParserException on {$driver}");
    }

    private static function describe(InsertStatement|UpdateStatement|DeleteStatement|TruncateStatement|MergeStatement $statement): string
    {
        $targets = implode(', ', array_map(static fn (array $name): string => implode('.', $name), WriteTargets::of($statement)));

        return match (true) {
            $statement instanceof InsertStatement => 'INSERT '.$targets
                .($statement->replacesRows ? ' REPLACING ROWS' : '')
                .($statement->conflictAssignments === null ? '' : ' SET '.implode(', ', WriteTargets::assignedColumns($statement) ?? [])),
            $statement instanceof UpdateStatement => "UPDATE {$targets} SET ".implode(', ', WriteTargets::assignedColumns($statement) ?? []),
            $statement instanceof DeleteStatement => "DELETE {$targets}",
            $statement instanceof TruncateStatement => "TRUNCATE {$targets}",
            $statement instanceof MergeStatement => "MERGE {$targets}",
        };
    }
}
