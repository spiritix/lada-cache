<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Spiritix\LadaCache\Support\Sql\DeleteStatement;
use Spiritix\LadaCache\Support\Sql\Dialect;
use Spiritix\LadaCache\Support\Sql\InsertStatement;
use Spiritix\LadaCache\Support\Sql\MergeStatement;
use Spiritix\LadaCache\Support\Sql\MsSqlDialect;
use Spiritix\LadaCache\Support\Sql\MySqlDialect;
use Spiritix\LadaCache\Support\Sql\Parser;
use Spiritix\LadaCache\Support\Sql\ParserException;
use Spiritix\LadaCache\Support\Sql\SqlMemo;
use Spiritix\LadaCache\Support\Sql\Token;
use Spiritix\LadaCache\Support\Sql\Tokenizer;
use Spiritix\LadaCache\Support\Sql\TokenizerException;
use Spiritix\LadaCache\Support\Sql\TokenKind;
use Spiritix\LadaCache\Support\Sql\TruncateStatement;
use Spiritix\LadaCache\Support\Sql\UpdateStatement;
use Spiritix\LadaCache\Support\Sql\WriteTargets;

/**
 * Classifies SQL run outside the query builder by parsing it: statements known to be free of
 * side effects change nothing, writes change the tables they target the way their kind does, and
 * anything that does not parse, writes to another MySQL database or is not known changes
 * everything.
 */
final class RawStatementClassifier
{
    private const array SIDE_EFFECT_FREE_KEYWORDS = [
        'SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'VALUES', 'TABLE', 'SET', 'BEGIN', 'START', 'COMMIT',
        'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'USE', 'PRAGMA', 'ANALYZE', 'OPTIMIZE', 'CHECK', 'CHECKSUM',
        'LOCK', 'UNLOCK', 'VACUUM', 'RESET', 'DISCARD', 'LISTEN', 'UNLISTEN', 'NOTIFY', 'PREPARE',
        'DEALLOCATE', 'XA',
    ];

    private const array WRITE_KEYWORDS = ['INSERT', 'REPLACE', 'UPDATE', 'DELETE', 'MERGE', 'TRUNCATE'];

    private const array EXPLAIN_OPTIONS = [
        'ANALYZE', 'ANALYSE', 'VERBOSE', 'COSTS', 'BUFFERS', 'TIMING', 'SUMMARY', 'SETTINGS', 'GENERIC_PLAN',
        'MEMORY', 'SERIALIZE', 'WAL', 'EXTENDED', 'PARTITIONS',
    ];

    private const array EXPLAINABLE_KEYWORDS = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE', 'WITH', 'VALUES', 'TABLE', 'EXECUTE', 'CREATE', 'DECLARE',
    ];

    /** @var SqlMemo<RawStatementEffect>|null */
    private static ?SqlMemo $effects = null;

    public static function classify(string $sql, string $tablePrefix, Dialect $dialect): RawStatementEffect
    {
        return (self::$effects ??= new SqlMemo)->remember(
            $dialect,
            $tablePrefix."\0".$sql,
            static fn (): RawStatementEffect => self::effectOf($sql, $tablePrefix, $dialect),
        );
    }

    private static function effectOf(string $sql, string $tablePrefix, Dialect $dialect): RawStatementEffect
    {
        try {
            $tokens = array_values(array_filter(
                (new Tokenizer($dialect, $sql))->tokenize(),
                static fn (Token $token): bool => ! $token->isTrivia(),
            ));
        } catch (TokenizerException) {
            return RawStatementEffect::everything();
        }

        while ($tokens !== [] && $tokens[array_key_last($tokens)]->is(TokenKind::SemiColon)) {
            array_pop($tokens);
        }

        foreach ($tokens as $token) {
            if ($token->is(TokenKind::SemiColon)) {
                return RawStatementEffect::everything();
            }
        }

        if ($dialect instanceof MsSqlDialect && self::batchesAnotherWrite($tokens)) {
            return RawStatementEffect::everything();
        }

        return self::classifyStatement($tokens, $tablePrefix, $dialect);
    }

    /**
     * T-SQL separates the statements of a batch without semicolons, so a write keyword after the
     * first statement's own may start another statement. A MERGE names INSERT, UPDATE and DELETE
     * in its own clauses and must end with a semicolon; `REPLACE(` is a function.
     *
     * @param  list<Token>  $tokens
     */
    private static function batchesAnotherWrite(array $tokens): bool
    {
        $first = null;

        foreach ($tokens as $position => $token) {
            if ($first === null) {
                if ($token->is(TokenKind::LParen)) {
                    continue;
                }

                $first = $token;
                if ($first->isKeyword('MERGE')) {
                    return false;
                }

                continue;
            }

            if ($token->isKeyword(...self::WRITE_KEYWORDS) && ($tokens[$position + 1] ?? null)?->is(TokenKind::LParen) !== true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the statement begins a transaction (`BEGIN`, `START TRANSACTION`) or ends one
     * (`COMMIT`, `ROLLBACK`, `END`, `ABORT`). `ROLLBACK TO` a savepoint stays inside it, and a
     * T-SQL `BEGIN` only begins one as `BEGIN TRAN[SACTION]`; otherwise it opens a block.
     *
     * @param  list<Token>  $statement
     */
    private static function transactionBoundary(array $statement, Dialect $dialect): ?TransactionBoundary
    {
        $first = $statement[0];
        $second = $statement[1] ?? null;

        return match (true) {
            $first->isKeyword('START') && $second?->isKeyword('TRANSACTION') === true => TransactionBoundary::Begins,
            $first->isKeyword('BEGIN') && ($second?->isKeyword('TRAN', 'TRANSACTION') === true || ! $dialect instanceof MsSqlDialect) => TransactionBoundary::Begins,
            $first->isKeyword('ROLLBACK') && $second?->isKeyword('TO') !== true => TransactionBoundary::Ends,
            $first->isKeyword('COMMIT', 'END', 'ABORT') => TransactionBoundary::Ends,
            default => null,
        };
    }

    /**
     * @param  list<Token>  $tokens
     */
    private static function classifyStatement(array $tokens, string $tablePrefix, Dialect $dialect): RawStatementEffect
    {
        $start = 0;
        while (($tokens[$start] ?? null)?->is(TokenKind::LParen) === true) {
            $start++;
        }

        $statement = array_slice($tokens, $start);
        $first = $statement[0] ?? null;

        if ($first === null) {
            return RawStatementEffect::none();
        }

        if ($first->isKeyword('EXPLAIN')) {
            return self::classifyExplain(array_slice($statement, 1), $tablePrefix, $dialect);
        }

        $boundary = self::transactionBoundary($statement, $dialect);

        if ($boundary !== null) {
            return RawStatementEffect::transaction($boundary);
        }

        if ($first->isKeyword(...self::SIDE_EFFECT_FREE_KEYWORDS) || self::isTemporaryTableStatement($statement)) {
            return RawStatementEffect::none();
        }

        if ($first->isKeyword('WITH')) {
            foreach ($statement as $token) {
                if ($token->isKeyword(...self::WRITE_KEYWORDS)) {
                    return RawStatementEffect::everything();
                }
            }

            return RawStatementEffect::none();
        }

        if (! $first->isKeyword(...self::WRITE_KEYWORDS)) {
            return RawStatementEffect::everything();
        }

        try {
            return self::classifyWrite((new Parser($dialect, $statement))->parseWriteStatement(), $tablePrefix, $dialect);
        } catch (ParserException) {
            return RawStatementEffect::everything();
        }
    }

    /**
     * MySQL qualifiers name other databases, whose tables this connection's invalidation cannot
     * reach; elsewhere they name schemas of the connection's database, which reads are tagged
     * without, as `Tagger` does.
     */
    private static function classifyWrite(InsertStatement|UpdateStatement|DeleteStatement|TruncateStatement|MergeStatement $statement, string $tablePrefix, Dialect $dialect): RawStatementEffect
    {
        $tables = [];
        foreach (WriteTargets::of($statement) as $name) {
            if (count($name) !== 1 && $dialect instanceof MySqlDialect) {
                return RawStatementEffect::everything();
            }
            $tables[TableName::withoutPrefix($name[array_key_last($name)], $tablePrefix)] = true;
        }

        $tables = array_map('strval', array_keys($tables));
        sort($tables, SORT_STRING);

        return RawStatementEffect::writes($tables, self::write($statement));
    }

    private static function write(InsertStatement|UpdateStatement|DeleteStatement|TruncateStatement|MergeStatement $statement): Write
    {
        return match (true) {
            $statement instanceof InsertStatement && $statement->replacesRows, $statement instanceof DeleteStatement => Write::delete(),
            $statement instanceof InsertStatement, $statement instanceof UpdateStatement => self::assigning(WriteTargets::assignedColumns($statement)),
            $statement instanceof TruncateStatement => Write::truncate(),
            $statement instanceof MergeStatement => Write::merge(),
        };
    }

    /**
     * @param  list<string>|null  $columns
     */
    private static function assigning(?array $columns): Write
    {
        return $columns === null ? Write::insert() : Write::update($columns);
    }

    /**
     * EXPLAIN only plans a statement, except with ANALYZE, which runs it (PostgreSQL even runs
     * writes this way).
     *
     * @param  list<Token>  $tokens  the tokens after EXPLAIN
     */
    private static function classifyExplain(array $tokens, string $tablePrefix, Dialect $dialect): RawStatementEffect
    {
        $analyzes = false;
        $position = 0;

        if (($tokens[0] ?? null)?->is(TokenKind::LParen) === true) {
            for ($position = 1; isset($tokens[$position]) && ! $tokens[$position]->is(TokenKind::RParen); $position++) {
                $analyzes = $analyzes || $tokens[$position]->isKeyword('ANALYZE', 'ANALYSE');
            }
            $position++;
        } else {
            while (isset($tokens[$position])) {
                $token = $tokens[$position];
                if ($token->isKeyword(...self::EXPLAIN_OPTIONS)) {
                    $analyzes = $analyzes || $token->isKeyword('ANALYZE', 'ANALYSE');
                    $position++;
                } elseif ($token->isKeyword('FORMAT')) {
                    $position += ($tokens[$position + 1] ?? null)?->is(TokenKind::Eq) === true ? 3 : 2;
                } else {
                    break;
                }
            }
        }

        if (($tokens[$position] ?? null)?->isKeyword(...self::EXPLAINABLE_KEYWORDS) !== true) {
            foreach ($tokens as $token) {
                if ($token->isKeyword('ANALYZE', 'ANALYSE')) {
                    return RawStatementEffect::everything();
                }
            }

            return RawStatementEffect::none();
        }

        return $analyzes ? self::classifyStatement(array_slice($tokens, $position), $tablePrefix, $dialect) : RawStatementEffect::none();
    }

    /**
     * @param  list<Token>  $tokens
     */
    private static function isTemporaryTableStatement(array $tokens): bool
    {
        if (($tokens[0] ?? null)?->isKeyword('CREATE', 'DROP') !== true) {
            return false;
        }

        $position = ($tokens[1] ?? null)?->isKeyword('GLOBAL', 'LOCAL') === true ? 2 : 1;

        return ($tokens[$position] ?? null)?->isKeyword('TEMP', 'TEMPORARY') === true
            && ($tokens[$position + 1] ?? null)?->isKeyword('TABLE') === true;
    }
}
