<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/parser/mod.rs and src/parser/merge.rs (Apache-2.0), commit 8a176d9:
 * `parse_insert`, `parse_replace`, `parse_update`, `parse_assignment`, `parse_delete`,
 * `parse_truncate`, `parse_merge`, `maybe_parse_output_clause`, `parse_table_with_joins`,
 * `parse_table_and_joins`, `parse_joins`, `parse_table_factor`, `parse_derived_table_factor`,
 * `maybe_parse_table_alias`, `parse_table_index_hints`, `maybe_parse_table_version`,
 * `maybe_parse_table_sample`, `parse_join_constraint`, `parse_object_name`, `parse_identifier`
 * and the table target of `parse_in`, reduced to the relations and assigned columns a statement
 * names.
 *
 * Expressions are not parsed but skipped as balanced token runs up to the keyword or punctuation
 * that ends them in the surrounding grammar, so a parenthesis after FROM is told to open a
 * subquery rather than a join by looking ahead for the start of a query. Beyond upstream, MySQL's
 * DELETE and UPDATE modifiers (`LOW_PRIORITY`, `QUICK`, `IGNORE`), its comma-separated UPDATE
 * table list, `t.*` delete targets and SQLite's `REPLACE INTO` are accepted, as both databases
 * document them; `TABLE t` query bodies count as reads of `t`; and writes whose effect a table list
 * cannot describe throw: SQLite's `UPDATE OR REPLACE`, which deletes the rows it conflicts with,
 * and T-SQL's `OUTPUT ... INTO` a table rather than a table variable.
 */
final class Parser
{
    private const array CLAUSE_KEYWORDS = [
        'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'OFFSET', 'FETCH', 'UNION', 'EXCEPT', 'INTERSECT',
        'WINDOW', 'QUALIFY', 'LOCK', 'INTO', 'RETURNING', 'OUTPUT', 'SET', 'USING',
    ];

    private const array LOCKING_AND_FOR_CLAUSE_KEYWORDS = ['UPDATE', 'SHARE', 'NO', 'KEY', 'READ', 'XML', 'JSON', 'BROWSE'];

    private const array QUERY_CONTINUATION_KEYWORDS = ['UNION', 'EXCEPT', 'INTERSECT', 'ORDER', 'LIMIT', 'OFFSET', 'FETCH'];

    private const array JOIN_KEYWORDS = ['JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'NATURAL', 'STRAIGHT_JOIN', 'OUTER'];

    private const array FUNCTIONS_WITH_FROM = ['EXTRACT', 'SUBSTRING', 'SUBSTR', 'TRIM', 'OVERLAY'];

    /** @var list<Token> */
    private readonly array $tokens;

    private int $index = 0;

    /**
     * @param  list<Token>  $tokens
     */
    public function __construct(
        private readonly Dialect $dialect,
        array $tokens,
    ) {
        $this->tokens = array_values(array_filter($tokens, static fn (Token $token): bool => ! $token->isTrivia()));
    }

    public static function forSql(Dialect $dialect, string $sql): self
    {
        return new self($dialect, (new Tokenizer($dialect, $sql))->tokenize());
    }

    public function parseWriteStatement(): InsertStatement|UpdateStatement|DeleteStatement|TruncateStatement|MergeStatement
    {
        $token = $this->next();

        return match (true) {
            $token->isKeyword('INSERT') => $this->parseInsert(false),
            $token->isKeyword('REPLACE') => $this->parseReplace(),
            $token->isKeyword('UPDATE') => $this->parseUpdate(),
            $token->isKeyword('DELETE') => $this->parseDelete(),
            $token->isKeyword('TRUNCATE') => $this->parseTruncate(),
            $token->isKeyword('MERGE') => $this->parseMerge(),
            default => throw $this->expected('INSERT, REPLACE, UPDATE, DELETE, TRUNCATE or MERGE', $token),
        };
    }

    /**
     * Every relation named after FROM or JOIN anywhere in the statement, subqueries included, the
     * tables of `TABLE t` query bodies and those SQLite's `x IN t` tests against. Throws when a FROM clause has a shape this port
     * does not know, since a relation in it could be missed.
     *
     * @return list<TableFactor>
     */
    public function readRelations(): array
    {
        $openers = $this->enclosingOpeners();
        $relations = [];
        $parsedUntil = 0;

        foreach ($this->tokens as $position => $token) {
            $this->index = $position + 1;

            if ($token->isKeyword('FROM') && ! $this->isFunctionArgumentFrom($position, $openers)) {
                $relations = [...$relations, ...$this->parseTableWithJoins()];
                $this->expectEndOfFromClause();
            } elseif ($token->isKeyword('JOIN') && $position >= $parsedUntil) {
                $relations[] = $this->parseTableFactor();
            } elseif ($this->startsTableQueryBody($position)) {
                $relations[] = TableFactor::table($this->parseObjectName(), null);
            } elseif ($this->startsInTableTarget($position)) {
                $name = $this->parseObjectName();
                if (! $this->peekIs(TokenKind::LParen)) {
                    $relations[] = TableFactor::table($name, null);
                }
            } else {
                continue;
            }

            $parsedUntil = max($parsedUntil, $this->index);
        }

        return $relations;
    }

    private function startsInTableTarget(int $position): bool
    {
        $next = $this->tokens[$position + 1] ?? null;

        return $this->dialect->supportsInTableExpression()
            && $this->tokens[$position]->isKeyword('IN')
            && $next !== null && $next->isWord() && $this->dialect->isTableFactor($next);
    }

    /**
     * Whether the position holds the `TABLE` of a `TABLE t` query body, which reads all of `t`.
     */
    private function startsTableQueryBody(int $position): bool
    {
        $previous = $this->tokens[$position - 1] ?? null;
        $next = $this->tokens[$position + 1] ?? null;

        return $this->tokens[$position]->isKeyword('TABLE')
            && $next !== null && ! $next->is(TokenKind::LParen)
            && ($previous === null || $previous->is(TokenKind::LParen) || $previous->isKeyword('UNION', 'EXCEPT', 'INTERSECT', 'ALL', 'DISTINCT'));
    }

    private function parseReplace(): InsertStatement
    {
        if (! $this->dialect instanceof MySqlDialect && ! $this->dialect instanceof SQLiteDialect) {
            throw new ParserException('Unsupported statement REPLACE');
        }

        return $this->parseInsert(true);
    }

    private function parseInsert(bool $replaceInto): InsertStatement
    {
        $replaces = $this->parseConflictClause() === 'REPLACE' || $replaceInto;

        if ($this->dialect instanceof MySqlDialect) {
            $this->parseOneOfKeywords('LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY');
            $this->parseKeyword('IGNORE');
        }

        $this->parseKeyword('INTO');
        $this->parseKeyword('TABLE');
        $table = $this->parseObjectName();

        return new InsertStatement($table, $replaces, $this->parseConflictAssignments());
    }

    /**
     * @return list<Assignment>|null
     */
    private function parseConflictAssignments(): ?array
    {
        $assignments = null;

        while (($token = $this->peek()) !== null) {
            if ($token->is(TokenKind::LParen)) {
                $this->skipGroup();

                continue;
            }

            if ($token->isKeyword('ON') && $this->peekKeywords('ON', 'DUPLICATE', 'KEY', 'UPDATE')) {
                $this->index += 4;
                $assignments = [...$assignments ?? [], ...$this->parseAssignments()];

                continue;
            }

            if ($token->isKeyword('DO') && $this->peekKeywords('DO', 'UPDATE', 'SET')) {
                $this->index += 3;
                $assignments = [...$assignments ?? [], ...$this->parseAssignments()];

                continue;
            }

            if (! $this->maybeParseOutputClause()) {
                $this->index++;
            }
        }

        return $assignments;
    }

    private function parseUpdate(): UpdateStatement
    {
        if ($this->parseConflictClause() === 'REPLACE') {
            throw new ParserException('UPDATE OR REPLACE deletes the rows it conflicts with');
        }

        if ($this->dialect instanceof MySqlDialect) {
            $this->parseOneOfKeywords('LOW_PRIORITY');
            $this->parseKeyword('IGNORE');
        }

        $tables = $this->dialect instanceof MySqlDialect ? $this->parseCommaSeparatedTablesAndJoins() : $this->parseTableAndJoins();

        if ($this->parseKeyword('FROM')) {
            $this->parseTableWithJoins();
        }

        $this->expectKeyword('SET');
        $assignments = $this->parseAssignments();
        $this->maybeParseOutputClause();

        return new UpdateStatement($tables, $assignments);
    }

    private function parseConflictClause(): ?string
    {
        if (! $this->parseKeyword('OR')) {
            return null;
        }

        $conflict = $this->parseOneOfKeywords('REPLACE', 'ROLLBACK', 'ABORT', 'FAIL', 'IGNORE');

        return $conflict ?? throw $this->expected('REPLACE, ROLLBACK, ABORT, FAIL or IGNORE after OR', $this->peek());
    }

    /**
     * @return non-empty-list<Assignment>
     */
    private function parseAssignments(): array
    {
        $assignments = [$this->parseAssignment()];

        while ($this->consume(TokenKind::Comma)) {
            $assignments[] = $this->parseAssignment();
        }

        return $assignments;
    }

    private function parseAssignment(): Assignment
    {
        if ($this->consume(TokenKind::LParen)) {
            $targets = [$this->parseObjectName()];
            while ($this->consume(TokenKind::Comma)) {
                $targets[] = $this->parseObjectName();
            }
            $this->expect(TokenKind::RParen);
        } else {
            $targets = [$this->parseObjectName()];
        }

        $equals = $this->next();
        if (! $equals->is(TokenKind::Eq) && ! ($equals->is(TokenKind::DoubleEq) && $this->dialect instanceof SQLiteDialect)) {
            throw $this->expected('= for assignment', $equals);
        }

        $this->skipExpression('FROM', 'WHERE', 'ORDER', 'LIMIT', 'ON', ...$this->returningKeywords());

        return new Assignment($targets);
    }

    /**
     * The keyword that starts the clause returning a write's rows: none in MySQL, whose UPDATE
     * returns none, and T-SQL's `OUTPUT` instead of `RETURNING`. Either is a column name elsewhere.
     *
     * @return list<string>
     */
    private function returningKeywords(): array
    {
        return match (true) {
            $this->dialect instanceof MySqlDialect => [],
            $this->dialect instanceof MsSqlDialect => ['OUTPUT'],
            default => ['RETURNING'],
        };
    }

    /**
     * Skips a T-SQL `OUTPUT` clause, which may also insert the returned rows into a table variable;
     * inserting them into a table throws, as the statement then writes a table besides its target.
     */
    private function maybeParseOutputClause(): bool
    {
        if (! $this->dialect instanceof MsSqlDialect || ! $this->parseKeyword('OUTPUT')) {
            return false;
        }

        do {
            $this->skipExpression('INTO', 'FROM', 'WHERE', 'VALUES', 'SELECT', 'DEFAULT', 'WITH', 'EXEC', 'EXECUTE', 'OPTION');
        } while ($this->consume(TokenKind::Comma));

        if (! $this->parseKeyword('INTO')) {
            return true;
        }

        $target = $this->peek();
        if ($target === null || ! $target->isWord() || $target->quoteStyle !== null || ! str_starts_with($target->value, '@') || $this->peekIs(TokenKind::Period, 1)) {
            throw new ParserException('OUTPUT INTO writes a table besides the statement\'s target'.($target === null ? '' : " at offset {$target->offset}"));
        }

        $this->index++;
        if ($this->peekIs(TokenKind::LParen)) {
            $this->skipGroup();
        }

        return true;
    }

    private function parseDelete(): DeleteStatement
    {
        if ($this->dialect instanceof MySqlDialect) {
            do {
                $modifier = $this->parseOneOfKeywords('LOW_PRIORITY', 'QUICK', 'IGNORE');
            } while ($modifier !== null);
        }

        $targets = [];
        if (! $this->parseKeyword('FROM')) {
            $targets[] = $this->parseDeleteTarget();
            while ($this->consume(TokenKind::Comma)) {
                $targets[] = $this->parseDeleteTarget();
            }
            $this->expectKeyword('FROM');
        }

        $from = $this->parseCommaSeparatedTablesAndJoins();
        $this->maybeParseOutputClause();
        $using = $this->parseKeyword('USING') ? $this->parseCommaSeparatedTablesAndJoins() : null;

        return new DeleteStatement($targets, $from, $using);
    }

    /**
     * @return non-empty-list<string>
     */
    private function parseDeleteTarget(): array
    {
        $name = $this->parseObjectName();

        if ($this->peekIs(TokenKind::Period) && $this->peekIs(TokenKind::Mul, 1)) {
            $this->index += 2;
        }

        return $name;
    }

    private function parseTruncate(): TruncateStatement
    {
        $this->parseKeyword('TABLE');

        if ($this->peekKeywords('IF', 'EXISTS')) {
            $this->index += 2;
        }

        $tables = [];
        do {
            $this->parseKeyword('ONLY');
            $tables[] = $this->parseObjectName();
            $this->consume(TokenKind::Mul);
        } while ($this->consume(TokenKind::Comma));

        return new TruncateStatement($tables);
    }

    private function parseMerge(): MergeStatement
    {
        $this->parseKeyword('INTO');
        $table = $this->parseTableFactor();
        $this->expectKeyword('USING');
        $this->parseTableFactor();
        $this->expectKeyword('ON');

        while (($token = $this->peek()) !== null) {
            if ($token->is(TokenKind::LParen)) {
                $this->skipGroup();
            } elseif (! $this->maybeParseOutputClause()) {
                $this->index++;
            }
        }

        return new MergeStatement($table->name ?? throw new ParserException('MERGE writes a relation that is not a table'));
    }

    /**
     * @return list<TableFactor>
     */
    private function parseCommaSeparatedTablesAndJoins(): array
    {
        $relations = $this->parseTableAndJoins();

        while ($this->consume(TokenKind::Comma)) {
            $relations = [...$relations, ...$this->parseTableAndJoins()];
        }

        return $relations;
    }

    /**
     * The relations of a FROM clause; SQLite also joins a relation after a comma on a constraint
     * (`FROM a, b ON a.id = b.id`).
     *
     * @return list<TableFactor>
     */
    private function parseTableWithJoins(): array
    {
        $relations = $this->parseTableAndJoins();

        while ($this->consume(TokenKind::Comma)) {
            $table = $this->parseTableAndJoins();

            if ($this->dialect->supportsCommaJoinConstraint() && count($table) === 1 && $this->peek()?->isKeyword('ON', 'USING') === true) {
                $this->parseJoinConstraint(false);
                $table = [...$table, ...$this->parseJoins()];
            }

            $relations = [...$relations, ...$table];
        }

        return $relations;
    }

    /**
     * @return list<TableFactor> the relation and those of its joins
     */
    private function parseTableAndJoins(): array
    {
        return [$this->parseTableFactor(), ...$this->parseJoins()];
    }

    /**
     * @return list<TableFactor>
     */
    private function parseJoins(): array
    {
        $relations = [];

        while (true) {
            $global = $this->parseKeyword('GLOBAL');

            if ($this->parseKeyword('CROSS')) {
                $isJoin = $this->parseKeyword('JOIN');
                if (! $isJoin && ! $this->parseKeyword('APPLY')) {
                    throw $this->expected('JOIN or APPLY after CROSS', $this->peek());
                }
                $relations[] = $this->parseTableFactor();
                if ($isJoin && $this->dialect->supportsCrossJoinConstraint()) {
                    $this->parseJoinConstraint(false);
                }

                continue;
            }

            if ($this->parseKeyword('OUTER')) {
                $this->expectKeyword('APPLY');
                $relations[] = $this->parseTableFactor();

                continue;
            }

            $natural = $this->parseKeyword('NATURAL');
            $peek = $this->peek();

            if ($peek !== null && $peek->isKeyword('INNER', 'JOIN')) {
                $this->parseKeyword('INNER');
                $this->expectKeyword('JOIN');
            } elseif ($peek !== null && $peek->isKeyword('LEFT', 'RIGHT') && ! $this->peekIs(TokenKind::LParen, 1)) {
                $this->index++;
                $type = $this->parseOneOfKeywords('OUTER', 'SEMI', 'ANTI', 'JOIN');
                if ($type === null) {
                    throw $this->expected('OUTER, SEMI, ANTI or JOIN after '.$peek->value, $this->peek());
                }
                if ($type !== 'JOIN') {
                    $this->expectKeyword('JOIN');
                }
            } elseif ($peek !== null && $peek->isKeyword('ANTI', 'SEMI')) {
                $this->index++;
                $this->expectKeyword('JOIN');
            } elseif ($peek !== null && $peek->isKeyword('FULL')) {
                $this->index++;
                $this->parseKeyword('OUTER');
                $this->expectKeyword('JOIN');
            } elseif ($peek !== null && $peek->isKeyword('STRAIGHT_JOIN')) {
                $this->index++;
            } elseif ($natural) {
                throw $this->expected('a join type after NATURAL', $peek);
            } elseif ($global) {
                throw $this->expected('a join after GLOBAL', $peek);
            } else {
                break;
            }

            $relations[] = $this->parseTableFactor();

            if (! $this->dialect->supportsLeftAssociativeJoinsWithoutParens() && ! $natural && $this->peekParensLessNestedJoin()) {
                $relations = [...$relations, ...$this->parseJoins()];
            }

            $this->parseJoinConstraint($natural);
        }

        return $relations;
    }

    private function peekParensLessNestedJoin(): bool
    {
        $peek = $this->peek();

        return $peek !== null && $peek->isKeyword('JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL') && ! $this->peekIs(TokenKind::LParen, 1);
    }

    private function parseJoinConstraint(bool $natural): void
    {
        if ($natural) {
            return;
        }

        if ($this->parseKeyword('ON')) {
            $this->skipExpression('ON', 'FOR', ...self::JOIN_KEYWORDS, ...self::CLAUSE_KEYWORDS);

            return;
        }

        if ($this->parseKeyword('USING')) {
            $this->expectGroup();
            if ($this->parseKeyword('AS')) {
                $this->parseIdentifier();
            }
        }
    }

    private function parseTableFactor(): TableFactor
    {
        if ($this->parseKeyword('LATERAL')) {
            if ($this->peekIs(TokenKind::LParen)) {
                if (! $this->isDerivedTableAhead()) {
                    throw $this->expected('a subquery after LATERAL', $this->peek(1));
                }

                return $this->parseDerivedTableFactor();
            }

            $this->parseObjectName();
            $this->expectGroup();
            $this->parseWithOrdinality();

            return TableFactor::derived($this->maybeParseTableAlias());
        }

        if ($this->peekKeywords('TABLE') && $this->peekIs(TokenKind::LParen, 1)) {
            $this->index++;
            $this->expectGroup();

            return TableFactor::derived($this->maybeParseTableAlias());
        }

        if ($this->peekIs(TokenKind::LParen)) {
            return $this->isDerivedTableAhead() ? $this->parseDerivedTableFactor() : $this->parseParenthesizedTableFactor();
        }

        if ($this->dialect instanceof PostgreSqlDialect && $this->peekKeywords('UNNEST') && $this->peekIs(TokenKind::LParen, 1)) {
            $this->index++;
            $this->expectGroup();
            $this->parseWithOrdinality();
            $alias = $this->maybeParseTableAlias();
            if ($this->peekKeywords('WITH', 'OFFSET')) {
                $this->index += 2;
                $this->maybeParseTableAlias();
            }

            return TableFactor::derived($alias);
        }

        foreach (['JSON_TABLE', 'OPENJSON', 'XMLTABLE'] as $function) {
            if ($this->peekKeywords($function) && $this->peekIs(TokenKind::LParen, 1)) {
                $this->index++;
                $this->expectGroup();
                if ($function === 'OPENJSON' && $this->peekKeywords('WITH') && $this->peekIs(TokenKind::LParen, 1)) {
                    $this->index++;
                    $this->expectGroup();
                }

                return TableFactor::derived($this->maybeParseTableAlias());
            }
        }

        return $this->parseNamedTableFactor();
    }

    private function parseNamedTableFactor(): TableFactor
    {
        $inheritanceMarkers = $this->dialect->supportsTableInheritanceMarkers();
        $only = $inheritanceMarkers && $this->parseKeyword('ONLY');
        $onlyParenthesized = $only && $this->consume(TokenKind::LParen);

        $first = $this->peek();
        if ($first === null || ($first->isWord() && ! $this->dialect->isTableFactor($first))) {
            throw $this->expected('table name', $first);
        }

        $name = $this->parseObjectName();

        if ($onlyParenthesized) {
            $this->expect(TokenKind::RParen);
        } elseif ($inheritanceMarkers && ! $only) {
            $this->consume(TokenKind::Mul);
        }

        if ($this->dialect->supportsTablePartitions() && $this->parseKeyword('PARTITION')) {
            $this->expectGroup();
        }

        $this->maybeParseTableVersion();

        if ($this->peekIs(TokenKind::LParen)) {
            $this->expectGroup();
            $this->parseWithOrdinality();

            return TableFactor::derived($this->maybeParseTableAlias());
        }

        $this->parseWithOrdinality();
        $alias = $this->maybeParseTableAlias();

        if ($this->dialect->supportsTableHints()) {
            $this->parseTableIndexHints();
        }

        if ($this->dialect->supportsIndexedBy()) {
            if ($this->peekKeywords('INDEXED', 'BY')) {
                $this->index += 2;
                $this->parseIdentifier();
            } elseif ($this->peekKeywords('NOT', 'INDEXED')) {
                $this->index += 2;
            }
        }

        if ($this->peekKeywords('WITH') && $this->peekIs(TokenKind::LParen, 1)) {
            $this->index++;
            $this->expectGroup();
        }

        $this->maybeParseTableSample();

        return TableFactor::table($name, $alias);
    }

    private function maybeParseTableVersion(): void
    {
        if ($this->dialect->supportsTableVersioning() && $this->peekKeywords('FOR', 'SYSTEM_TIME', 'AS', 'OF')) {
            $this->index += 4;
            $this->skipExpression('AS', ...Keywords::RESERVED_FOR_TABLE_ALIAS);
        }
    }

    private function maybeParseTableSample(): void
    {
        if ($this->parseOneOfKeywords('TABLESAMPLE', 'SAMPLE') === null) {
            return;
        }

        $this->parseOneOfKeywords('BERNOULLI', 'ROW', 'SYSTEM', 'BLOCK');

        if ($this->peekIs(TokenKind::LParen)) {
            $this->skipGroup();
        } else {
            $quantity = $this->next();
            if (! $quantity->is(TokenKind::Number) && ! $quantity->is(TokenKind::Placeholder) && ! $quantity->isWord()) {
                throw $this->expected('a sample quantity', $quantity);
            }
            $this->parseOneOfKeywords('ROWS', 'PERCENT');
        }

        if ($this->parseOneOfKeywords('REPEATABLE', 'SEED') !== null) {
            $this->expectGroup();
        }
    }

    private function parseWithOrdinality(): void
    {
        if ($this->peekKeywords('WITH', 'ORDINALITY')) {
            $this->index += 2;
        }
    }

    /**
     * Whether the parenthesis ahead opens a subquery rather than a parenthesized join.
     */
    private function isDerivedTableAhead(): bool
    {
        return $this->startsQuery($this->index + 1);
    }

    /**
     * Whether a query starts at the position: a query keyword, or a parenthesized query that the
     * query goes on after or ends with.
     */
    private function startsQuery(int $position): bool
    {
        $token = $this->tokens[$position] ?? null;

        if ($token === null || ! $token->is(TokenKind::LParen)) {
            return $token !== null
                && ($token->isKeyword('SELECT', 'WITH', 'VALUES') || ($token->isKeyword('TABLE') && ($this->tokens[$position + 1] ?? null)?->is(TokenKind::LParen) === false));
        }

        if (! $this->startsQuery($position + 1)) {
            return false;
        }

        $after = $this->tokens[$this->closingParenthesis($position) + 1] ?? null;

        return $after !== null && ($after->is(TokenKind::RParen) || $after->isKeyword(...self::QUERY_CONTINUATION_KEYWORDS));
    }

    private function closingParenthesis(int $opening): int
    {
        $depth = 0;

        for ($position = $opening; isset($this->tokens[$position]); $position++) {
            if ($this->tokens[$position]->is(TokenKind::LParen)) {
                $depth++;
            } elseif ($this->tokens[$position]->is(TokenKind::RParen) && --$depth === 0) {
                return $position;
            }
        }

        throw new ParserException('Unbalanced parentheses');
    }

    private function parseDerivedTableFactor(): TableFactor
    {
        $this->expectGroup();
        $alias = $this->maybeParseTableAlias();
        $this->maybeParseTableSample();

        return TableFactor::derived($alias);
    }

    private function parseParenthesizedTableFactor(): TableFactor
    {
        $this->expect(TokenKind::LParen);
        $relations = $this->parseTableAndJoins();
        $this->expect(TokenKind::RParen);
        $alias = $this->maybeParseTableAlias();

        if (count($relations) === 1 && $relations[0]->nested === [] && ! $this->dialect->supportsParensAroundTableFactor()) {
            throw new ParserException('Expected joined table inside parentheses');
        }

        return TableFactor::nestedJoin($relations, $alias);
    }

    private function maybeParseTableAlias(): ?string
    {
        $afterAs = $this->parseKeyword('AS');
        $token = $this->peek();

        if ($token !== null
            && (($token->isWord() && $this->dialect->isTableFactorAlias($afterAs, $token, $this->peek(1)))
                || ($token->is(TokenKind::SingleQuotedString) && $this->dialect->supportsStringLiteralTableAlias($afterAs))
                || $token->is(TokenKind::DoubleQuotedString))) {
            $this->index++;
            if ($this->peekIs(TokenKind::LParen)) {
                $this->skipGroup();
            }

            return $token->value;
        }

        if ($afterAs) {
            throw $this->expected('an identifier after AS', $token);
        }

        return null;
    }

    private function parseTableIndexHints(): void
    {
        while ($this->parseOneOfKeywords('USE', 'IGNORE', 'FORCE') !== null) {
            if ($this->parseOneOfKeywords('INDEX', 'KEY') === null) {
                throw $this->expected('INDEX or KEY', $this->peek());
            }

            if ($this->parseKeyword('FOR') && ! $this->parseKeyword('JOIN')) {
                if (! $this->peekKeywords('ORDER', 'BY') && ! $this->peekKeywords('GROUP', 'BY')) {
                    throw $this->expected('JOIN, ORDER BY or GROUP BY after FOR', $this->peek());
                }
                $this->index += 2;
            }

            $this->expectGroup();
        }
    }

    /**
     * The parts of a possibly qualified name, without a `.*` that follows it. T-SQL's `db..t`
     * names the default schema with an empty part.
     *
     * @return non-empty-list<string>
     */
    private function parseObjectName(): array
    {
        $parts = [$this->parseIdentifier()];

        while ($this->peekIs(TokenKind::Period) && ! $this->peekIs(TokenKind::Mul, 1)) {
            $this->index++;
            $parts[] = $this->dialect->supportsObjectNameDoubleDotNotation() && count($parts) === 1 && $this->peekIs(TokenKind::Period)
                ? ''
                : $this->parseIdentifier();
        }

        return $parts;
    }

    private function parseIdentifier(): string
    {
        $token = $this->next();

        if (! $token->isWord() && ! $token->is(TokenKind::SingleQuotedString) && ! $token->is(TokenKind::DoubleQuotedString)) {
            throw $this->expected('identifier', $token);
        }

        return $token->value;
    }

    /**
     * Skips an expression: tokens up to a comma, a closing parenthesis or one of the keywords at
     * the expression's own depth, leaving that token next. `IS [NOT] DISTINCT FROM` and
     * functions such as `LEFT(...)` do not end it.
     */
    private function skipExpression(string ...$stopKeywords): void
    {
        while (($token = $this->peek()) !== null) {
            if ($token->is(TokenKind::LParen)) {
                $this->skipGroup();

                continue;
            }

            if ($token->is(TokenKind::RParen) || $token->is(TokenKind::Comma) || $token->is(TokenKind::SemiColon)) {
                return;
            }

            if ($token->isKeyword(...$stopKeywords)
                && ! $this->peekIs(TokenKind::LParen, 1)
                && ! ($token->isKeyword('FROM') && ($this->tokens[$this->index - 1] ?? null)?->isKeyword('DISTINCT') === true)) {
                return;
            }

            $this->index++;
        }
    }

    private function expectGroup(): void
    {
        if (! $this->peekIs(TokenKind::LParen)) {
            throw $this->expected('(', $this->peek());
        }

        $this->skipGroup();
    }

    private function skipGroup(): void
    {
        $depth = 0;

        while (($token = $this->peek()) !== null) {
            $this->index++;

            if ($token->is(TokenKind::LParen)) {
                $depth++;
            } elseif ($token->is(TokenKind::RParen) && --$depth === 0) {
                return;
            }
        }

        throw new ParserException('Unbalanced parentheses');
    }

    private function expectEndOfFromClause(): void
    {
        $token = $this->peek();

        if ($token === null
            || $token->is(TokenKind::RParen)
            || $token->is(TokenKind::SemiColon)
            || $token->isKeyword(...self::CLAUSE_KEYWORDS)
            || $this->peekKeywords('ON', 'DUPLICATE')
            || $this->peekKeywords('ON', 'CONFLICT')
            || ($token->isKeyword('FOR') && $this->peek(1)?->isKeyword(...self::LOCKING_AND_FOR_CLAUSE_KEYWORDS) === true)) {
            return;
        }

        throw $this->expected('the end of the FROM clause', $token);
    }

    /**
     * @param  array<int, int|null>  $openers
     */
    private function isFunctionArgumentFrom(int $position, array $openers): bool
    {
        if (($this->tokens[$position - 1] ?? null)?->isKeyword('DISTINCT') === true) {
            return true;
        }

        $opener = $openers[$position] ?? null;

        return $opener !== null && ($this->tokens[$opener - 1] ?? null)?->isKeyword(...self::FUNCTIONS_WITH_FROM) === true;
    }

    /**
     * @return array<int, int|null> token position => position of the parenthesis enclosing it
     */
    private function enclosingOpeners(): array
    {
        $openers = [];
        $stack = [];

        foreach ($this->tokens as $position => $token) {
            if ($token->is(TokenKind::RParen)) {
                array_pop($stack);
            }

            $openers[$position] = $stack === [] ? null : $stack[array_key_last($stack)];

            if ($token->is(TokenKind::LParen)) {
                $stack[] = $position;
            }
        }

        return $openers;
    }

    private function parseKeyword(string $keyword): bool
    {
        if ($this->peek()?->isKeyword($keyword) === true) {
            $this->index++;

            return true;
        }

        return false;
    }

    private function parseOneOfKeywords(string ...$keywords): ?string
    {
        $token = $this->peek();

        if ($token === null || ! $token->isKeyword(...$keywords)) {
            return null;
        }

        $this->index++;

        return strtoupper($token->value);
    }

    private function peekKeywords(string ...$keywords): bool
    {
        $offset = $this->index;

        foreach ($keywords as $keyword) {
            if (($this->tokens[$offset] ?? null)?->isKeyword($keyword) !== true) {
                return false;
            }
            $offset++;
        }

        return true;
    }

    private function expectKeyword(string $keyword): void
    {
        if (! $this->parseKeyword($keyword)) {
            throw $this->expected($keyword, $this->peek());
        }
    }

    private function consume(TokenKind $kind): bool
    {
        if ($this->peekIs($kind)) {
            $this->index++;

            return true;
        }

        return false;
    }

    private function expect(TokenKind $kind): void
    {
        if (! $this->consume($kind)) {
            throw $this->expected($kind->name, $this->peek());
        }
    }

    private function peekIs(TokenKind $kind, int $offset = 0): bool
    {
        return ($this->tokens[$this->index + $offset] ?? null)?->is($kind) === true;
    }

    private function peek(int $offset = 0): ?Token
    {
        return $this->tokens[$this->index + $offset] ?? null;
    }

    private function next(): Token
    {
        $token = $this->tokens[$this->index] ?? throw new ParserException('Unexpected end of statement');
        $this->index++;

        return $token;
    }

    private function expected(string $expected, ?Token $found): ParserException
    {
        return new ParserException($found === null
            ? "Expected {$expected}, found the end of the statement"
            : "Expected {$expected}, found {$found->kind->name} ".json_encode($found->value)." at offset {$found->offset}");
    }
}
