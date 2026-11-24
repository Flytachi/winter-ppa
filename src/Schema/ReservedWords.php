<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

/**
 * The words a server takes for its own: ppa writes names as they are, unquoted — a table or a
 * column of such a name is refused by the server at migrate, so {@see SchemaCheck} says it at
 * the snapshot. Read from the servers themselves: MySQL 8.4 and 9.7 (`information_schema.KEYWORDS`,
 * reserved), MariaDB 11.8 (its keywords a table cannot be named), PostgreSQL 18
 * (`pg_get_keywords()`, reserved and type-or-function names). `mysql` is both of the first.
 */
final class ReservedWords
{
    private const array MYSQL = [
        'accessible', 'add', 'all', 'alter', 'analyze', 'and', 'as', 'asc', 'asensitive', 'before', 'between',
        'bigint', 'binary', 'blob', 'both', 'by', 'call', 'cascade', 'case', 'change', 'char', 'character', 'check',
        'collate', 'column', 'condition', 'constraint', 'continue', 'convert', 'create', 'cross', 'cube',
        'cume_dist', 'current_date', 'current_role', 'current_time', 'current_timestamp', 'current_user', 'cursor',
        'database', 'databases', 'day_hour', 'day_microsecond', 'day_minute', 'day_second', 'dec', 'decimal',
        'declare', 'default', 'delayed', 'delete', 'delete_domain_id', 'dense_rank', 'desc', 'describe',
        'deterministic', 'distinct', 'distinctrow', 'div', 'do_domain_ids', 'double', 'drop', 'dual', 'each',
        'else', 'elseif', 'empty', 'enclosed', 'escaped', 'except', 'exists', 'exit', 'explain', 'external',
        'false', 'fetch', 'first_value', 'float', 'float4', 'float8', 'for', 'force', 'foreign', 'from', 'fulltext',
        'function', 'generated', 'get', 'grant', 'group', 'grouping', 'groups', 'having', 'high_priority',
        'hour_microsecond', 'hour_minute', 'hour_second', 'if', 'ignore', 'ignore_domain_ids', 'in', 'index',
        'infile', 'inner', 'inout', 'insensitive', 'insert', 'int', 'int1', 'int2', 'int3', 'int4', 'int8',
        'integer', 'intersect', 'interval', 'into', 'io_after_gtids', 'io_before_gtids', 'is', 'iterate', 'join',
        'json_table', 'key', 'keys', 'kill', 'lag', 'last_value', 'lateral', 'lead', 'leading', 'leave', 'left',
        'library', 'like', 'limit', 'linear', 'lines', 'load', 'localtime', 'localtimestamp', 'lock', 'long',
        'longblob', 'longtext', 'loop', 'low_priority', 'master_demote_to_replica', 'master_demote_to_slave',
        'match', 'maxvalue', 'mediumblob', 'mediumint', 'mediumtext', 'middleint', 'minute_microsecond',
        'minute_second', 'mod', 'modifies', 'natural', 'no_write_to_binlog', 'not', 'nth_value', 'ntile', 'null',
        'numeric', 'of', 'offset', 'on', 'optimize', 'optimizer_costs', 'option', 'optionally', 'or', 'order',
        'out', 'outer', 'outfile', 'over', 'page_checksum', 'parse_vcol_expr', 'partition', 'percent_rank',
        'portion', 'precision', 'primary', 'procedure', 'purge', 'qualify', 'range', 'rank', 'read', 'read_write',
        'reads', 'real', 'recursive', 'ref_system_id', 'references', 'regexp', 'release', 'rename', 'repeat',
        'replace', 'require', 'resignal', 'restrict', 'return', 'returning', 'revoke', 'right', 'rlike', 'row',
        'row_number', 'rows', 'schema', 'schemas', 'second_microsecond', 'select', 'sensitive', 'separator', 'set',
        'show', 'signal', 'smallint', 'spatial', 'specific', 'sql', 'sql_after_gtids', 'sql_before_gtids',
        'sql_big_result', 'sql_calc_found_rows', 'sql_small_result', 'sqlexception', 'sqlstate', 'sqlwarning',
        'ssl', 'starting', 'stats_auto_recalc', 'stats_persistent', 'stats_sample_pages', 'stored', 'straight_join',
        'system', 'table', 'tablesample', 'terminated', 'then', 'tinyblob', 'tinyint', 'tinytext', 'to', 'trailing',
        'trigger', 'true', 'undo', 'union', 'unique', 'unlock', 'unsigned', 'update', 'usage', 'use', 'using',
        'utc_date', 'utc_time', 'utc_timestamp', 'values', 'varbinary', 'varchar', 'varcharacter', 'varying',
        'vector', 'virtual', 'when', 'where', 'while', 'window', 'with', 'write', 'xor', 'year_month', 'zerofill',
    ];

    private const array PGSQL = [
        'all', 'analyse', 'analyze', 'and', 'any', 'array', 'as', 'asc', 'asymmetric', 'authorization', 'binary',
        'both', 'case', 'cast', 'check', 'collate', 'collation', 'column', 'concurrently', 'constraint', 'create',
        'cross', 'current_catalog', 'current_date', 'current_role', 'current_schema', 'current_time',
        'current_timestamp', 'current_user', 'default', 'deferrable', 'desc', 'distinct', 'do', 'else', 'end',
        'except', 'false', 'fetch', 'for', 'foreign', 'freeze', 'from', 'full', 'grant', 'group', 'having', 'ilike',
        'in', 'initially', 'inner', 'intersect', 'into', 'is', 'isnull', 'join', 'lateral', 'leading', 'left',
        'like', 'limit', 'localtime', 'localtimestamp', 'natural', 'not', 'notnull', 'null', 'offset', 'on', 'only',
        'or', 'order', 'outer', 'overlaps', 'placing', 'primary', 'references', 'returning', 'right', 'select',
        'session_user', 'similar', 'some', 'symmetric', 'system_user', 'table', 'tablesample', 'then', 'to',
        'trailing', 'true', 'union', 'unique', 'user', 'using', 'variadic', 'verbose', 'when', 'where', 'window',
        'with',
    ];

    private function __construct()
    {
    }

    /** Whether the server of `$driver` refuses `$name` as a table or a column name. */
    public static function is(string $name, string $driver): bool
    {
        static $sets = [];
        $sets[$driver] ??= array_flip(match ($driver) {
            'mysql' => self::MYSQL,
            'pgsql' => self::PGSQL,
            default => [],
        });
        return isset($sets[$driver][strtolower($name)]);
    }
}
