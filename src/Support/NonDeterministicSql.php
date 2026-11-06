<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

final class NonDeterministicSql
{
    private const string QUOTED = '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[[^\]]*\]/';

    private const string FUNCTION_CALL = '/\b(?:now|sysdate|curdate|curtime|utc_timestamp|utc_date|utc_time|unix_timestamp|rand|random|randomblob|uuid|uuid_short|gen_random_uuid|newid|newsequentialid|getdate|getutcdate|sysdatetime|sysutcdatetime|sysdatetimeoffset|clock_timestamp|statement_timestamp|transaction_timestamp|timeofday|last_insert_id|last_insert_rowid|changes|connection_id|found_rows|row_count|nextval|currval|lastval|txid_current|pg_backend_pid|user|session_user|system_user|current_user|current_role|database|schema)\s*\(/i';

    private const string BARE_KEYWORD = '/(?<!\.)\b(?:current_timestamp|current_date|current_time|localtime|localtimestamp|current_user|session_user)\b(?!\s*\.)/i';

    private const string SQLITE_NOW = '/\'now\'/i';

    public static function isNonDeterministic(string $sql): bool
    {
        $code = (string) preg_replace(self::QUOTED, "''", $sql);

        return preg_match(self::FUNCTION_CALL, $code) === 1
            || preg_match(self::BARE_KEYWORD, $code) === 1
            || preg_match(self::SQLITE_NOW, $sql) === 1;
    }
}
