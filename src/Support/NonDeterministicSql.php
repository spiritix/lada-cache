<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Spiritix\LadaCache\Support\Sql\Dialect;
use Spiritix\LadaCache\Support\Sql\Tokenizer;
use Spiritix\LadaCache\Support\Sql\TokenKind;

/**
 * Whether SQL calls a function whose result depends on time, randomness or the session, which
 * makes a cached result wrong for the next caller.
 */
final class NonDeterministicSql
{
    private const array FUNCTIONS = [
        'NOW', 'SYSDATE', 'CURDATE', 'CURTIME', 'UTC_TIMESTAMP', 'UTC_DATE', 'UTC_TIME', 'UNIX_TIMESTAMP', 'RAND', 'RANDOM',
        'RANDOMBLOB', 'UUID', 'UUID_SHORT', 'GEN_RANDOM_UUID', 'NEWID', 'NEWSEQUENTIALID', 'GETDATE', 'GETUTCDATE',
        'SYSDATETIME', 'SYSUTCDATETIME', 'SYSDATETIMEOFFSET', 'CLOCK_TIMESTAMP', 'STATEMENT_TIMESTAMP',
        'TRANSACTION_TIMESTAMP', 'TIMEOFDAY', 'LAST_INSERT_ID', 'LAST_INSERT_ROWID', 'CHANGES', 'CONNECTION_ID',
        'FOUND_ROWS', 'ROW_COUNT', 'NEXTVAL', 'CURRVAL', 'LASTVAL', 'TXID_CURRENT', 'PG_BACKEND_PID', 'USER',
        'SESSION_USER', 'SYSTEM_USER', 'CURRENT_USER', 'CURRENT_ROLE', 'DATABASE', 'SCHEMA',
    ];

    private const array BARE_KEYWORDS = [
        'CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME', 'LOCALTIME', 'LOCALTIMESTAMP', 'CURRENT_USER', 'SESSION_USER',
    ];

    /**
     * Function calls by name, the SQL standard's bare session keywords outside qualified names,
     * and SQLite's `'now'` time value.
     */
    public static function isNonDeterministic(string $sql, Dialect $dialect): bool
    {
        $tokens = array_values(array_filter(
            (new Tokenizer($dialect, $sql))->tokenize(),
            static fn (Sql\Token $token): bool => ! $token->isTrivia(),
        ));

        foreach ($tokens as $position => $token) {
            $previous = $tokens[$position - 1] ?? null;
            $next = $tokens[$position + 1] ?? null;

            if ($token->isKeyword(...self::FUNCTIONS) && $next?->is(TokenKind::LParen) === true) {
                return true;
            }

            if ($token->isKeyword(...self::BARE_KEYWORDS) && $previous?->is(TokenKind::Period) !== true && $next?->is(TokenKind::Period) !== true) {
                return true;
            }

            if ($token->is(TokenKind::SingleQuotedString) && strtolower($token->value) === 'now') {
                return true;
            }
        }

        return false;
    }
}
