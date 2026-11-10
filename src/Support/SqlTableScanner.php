<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Spiritix\LadaCache\Support\Sql\Dialect;
use Spiritix\LadaCache\Support\Sql\Parser;
use Spiritix\LadaCache\Support\Sql\ParserException;
use Spiritix\LadaCache\Support\Sql\Token;
use Spiritix\LadaCache\Support\Sql\Tokenizer;
use Spiritix\LadaCache\Support\Sql\TokenKind;
use Spiritix\LadaCache\Support\Sql\WriteTargets;

/**
 * Finds the tables SQL text reads or writes, including those only named inside raw expressions
 * such as `whereRaw('exists (select 1 from pets ...)')` that the builder structure does not
 * reveal, and those written by trigger bodies. Names keep their database or schema qualifier,
 * without the table prefix. SQL whose tables cannot be told throws.
 */
final class SqlTableScanner
{
    private const array WRITE_KEYWORDS = ['INSERT', 'REPLACE', 'UPDATE', 'DELETE', 'MERGE', 'TRUNCATE'];

    /**
     * Keywords before or after a write keyword that name a trigger event or a lock, as in
     * `AFTER INSERT ON pets`, `ON DELETE CASCADE` or `FOR UPDATE`, rather than start a statement;
     * `INSERT(` and `REPLACE(` are MySQL's string functions.
     */
    private const array EVENT_NEIGHBOURS = ['ON', 'OF', 'OR', 'FOR', 'BEFORE', 'AFTER', 'INSTEAD'];

    /**
     * @return list<string>
     */
    public static function tables(string $sql, string $tablePrefix, Dialect $dialect): array
    {
        return array_map('strval', array_keys(self::tableReferenceCounts($sql, $tablePrefix, $dialect)));
    }

    /**
     * How often the SQL reads each table, counting every relation after FROM or JOIN of the
     * statement and of its subqueries. A table read more than once, such as one joined to itself
     * or named again inside a subquery, is not limited to the rows a query's conditions pin.
     *
     * @return array<string, int>
     */
    public static function tableReferenceCounts(string $sql, string $tablePrefix, Dialect $dialect): array
    {
        $counts = [];

        foreach (Parser::forSql($dialect, $sql)->readRelations() as $relation) {
            foreach ($relation->tables() as $table) {
                $name = self::tableName($table->name ?? [], $tablePrefix);
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Every table an INSERT, REPLACE, UPDATE, DELETE, MERGE or TRUNCATE in the SQL writes, such
     * as the statements of a trigger body.
     *
     * @return list<string>
     */
    public static function writtenTables(string $sql, string $tablePrefix, Dialect $dialect): array
    {
        $tokens = array_values(array_filter(
            (new Tokenizer($dialect, $sql))->tokenize(),
            static fn (Token $token): bool => ! $token->isTrivia(),
        ));
        $tables = [];
        $statementEnd = -1;

        foreach ($tokens as $position => $token) {
            if ($position <= $statementEnd || ! $token->isKeyword(...self::WRITE_KEYWORDS) || self::namesAnEvent($tokens, $position)) {
                continue;
            }

            $statementEnd = self::statementEnd($tokens, $position);

            try {
                $statement = (new Parser($dialect, array_slice($tokens, $position)))->parseWriteStatement();
            } catch (ParserException $exception) {
                throw new ParserException("Lada Cache cannot tell which table the {$token->value} at offset {$token->offset} writes: {$exception->getMessage()}", 0, $exception);
            }

            foreach (WriteTargets::of($statement) as $name) {
                $tables[self::tableName($name, $tablePrefix)] = true;
            }
        }

        return array_map('strval', array_keys($tables));
    }

    /**
     * The position of the semicolon ending the statement that starts at the position, or of its
     * last token, so the clauses of a statement (`WHEN MATCHED THEN DELETE`) are not read as
     * statements of their own.
     *
     * @param  list<Token>  $tokens
     */
    private static function statementEnd(array $tokens, int $start): int
    {
        $depth = 0;

        for ($position = $start; isset($tokens[$position]); $position++) {
            $token = $tokens[$position];
            $depth += $token->is(TokenKind::LParen) ? 1 : ($token->is(TokenKind::RParen) ? -1 : 0);

            if ($depth <= 0 && $token->is(TokenKind::SemiColon)) {
                return $position;
            }
        }

        return $position - 1;
    }

    /**
     * @param  list<Token>  $tokens
     */
    private static function namesAnEvent(array $tokens, int $position): bool
    {
        $previous = $tokens[$position - 1] ?? null;
        $next = $tokens[$position + 1] ?? null;

        return ($previous !== null && ($previous->is(TokenKind::Comma) || $previous->isKeyword(...self::EVENT_NEIGHBOURS)))
            || ($next !== null && ($next->is(TokenKind::Comma) || $next->isKeyword('ON', 'OF', 'OR', 'AS')))
            || ($tokens[$position]->isKeyword('INSERT', 'REPLACE') && $next?->is(TokenKind::LParen) === true);
    }

    /**
     * @param  list<string>  $name
     */
    private static function tableName(array $name, string $tablePrefix): string
    {
        $table = (string) array_pop($name);

        return implode('.', [...$name, TableName::withoutPrefix($table, $tablePrefix)]);
    }
}
