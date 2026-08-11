<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Read/execute access to the MySQL databases the panel manages.
 *
 * Runs over the `forge_mysql` connection, whose user holds ALL PRIVILEGES ON
 * *.* — every statement here is effectively database root. Callers are gated
 * behind the panel's single-admin auth; this class does not attempt to be a
 * privilege boundary, only to keep payloads bounded and identifiers safe.
 */
class DatabaseInspector
{
    /** Rows returned to the browser from a single query. */
    private const MAX_ROWS = 500;

    /** Characters kept per cell before ellipsis. */
    private const MAX_VALUE_CHARS = 512;

    private const CONNECTION = 'forge_mysql_browse';

    /**
     * Tables in a database with an approximate row count.
     *
     * TABLE_ROWS is InnoDB's estimate, not an exact count: an exact
     * COUNT(*) per table would scan every index on a page load.
     *
     * @return list<array{name: string, row_estimate: int, size: int}>
     */
    public function tables(string $database): array
    {
        $rows = $this->connection($database)->select(
            'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_estimate,
                    COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS size
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ?
             ORDER BY TABLE_NAME',
            [$database],
        );

        $tables = [];

        foreach ($rows as $row) {
            $tables[] = [
                'name' => (string) $row->name,
                'row_estimate' => (int) $row->row_estimate,
                'size' => (int) $row->size,
            ];
        }

        return $tables;
    }

    /**
     * A page of rows from one table, or null when the table does not exist.
     *
     * Deliberately avoids COUNT(*) for pagination: it fetches one row more
     * than requested and reports whether another page exists, so paging stays
     * O(page size) rather than O(table size).
     *
     * @return array{table: string, columns: list<string>, rows: list<list<mixed>>, page: int, per_page: int, has_more: bool, row_estimate: int}|null
     */
    public function rows(string $database, string $table, int $page = 1, int $perPage = 50): ?array
    {
        $known = $this->tables($database);
        $match = null;

        foreach ($known as $candidate) {
            if ($candidate['name'] === $table) {
                $match = $candidate;
                break;
            }
        }

        // The information_schema listing is the whitelist — a table name that
        // is not already in it never reaches the query below.
        if ($match === null) {
            return null;
        }

        $page = max(1, $page);
        $perPage = max(1, min($perPage, self::MAX_ROWS));
        $offset = ($page - 1) * $perPage;

        $result = $this->execute($database, sprintf(
            'SELECT * FROM `%s` LIMIT %d OFFSET %d',
            $this->escapeIdentifier($table),
            $perPage + 1,
            $offset,
        ), $perPage + 1);

        $rows = $result['rows'];
        $hasMore = count($rows) > $perPage;

        return [
            'table' => $table,
            'columns' => $result['columns'],
            'rows' => array_slice($rows, 0, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
            'row_estimate' => $match['row_estimate'],
        ];
    }

    /**
     * Run an arbitrary statement against one database.
     *
     * A failing statement is returned as data rather than thrown: the error
     * text is the useful output of a SQL console. PDO_MySQL rejects stacked
     * statements by default, so one call runs one statement.
     *
     * @return array{kind: string, columns: list<string>, rows: list<list<mixed>>, row_count: int, affected: int, truncated: bool, duration_ms: float, error: string|null}
     */
    public function query(string $database, string $sql): array
    {
        $started = microtime(true);

        try {
            $result = $this->execute($database, $sql, self::MAX_ROWS);
        } catch (Throwable $exception) {
            return [
                'kind' => 'error',
                'columns' => [],
                'rows' => [],
                'row_count' => 0,
                'affected' => 0,
                'truncated' => false,
                'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'kind' => $result['is_result_set'] ? 'rows' : 'statement',
            'columns' => $result['columns'],
            'rows' => $result['rows'],
            'row_count' => count($result['rows']),
            'affected' => $result['affected'],
            'truncated' => $result['truncated'],
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            'error' => null,
        ];
    }

    /**
     * @return array{columns: list<string>, rows: list<list<mixed>>, affected: int, truncated: bool, is_result_set: bool}
     */
    private function execute(string $database, string $sql, int $limit): array
    {
        $pdo = $this->connection($database)->getPdo();
        $statement = $pdo->query($sql);

        if (! $statement instanceof PDOStatement) {
            return ['columns' => [], 'rows' => [], 'affected' => 0, 'truncated' => false, 'is_result_set' => false];
        }

        $columnCount = $statement->columnCount();

        // No columns means it was an INSERT/UPDATE/DDL rather than a select.
        if ($columnCount === 0) {
            $affected = $statement->rowCount();
            $statement->closeCursor();

            return ['columns' => [], 'rows' => [], 'affected' => $affected, 'truncated' => false, 'is_result_set' => false];
        }

        $columns = [];

        for ($i = 0; $i < $columnCount; $i++) {
            $meta = $statement->getColumnMeta($i);
            $columns[] = is_array($meta) ? (string) $meta['name'] : 'column_'.$i;
        }

        $rows = [];
        $truncated = false;

        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            if (count($rows) >= $limit) {
                $truncated = true;
                break;
            }

            $values = [];

            foreach ((array) $row as $value) {
                $values[] = $this->presentValue($value);
            }

            $rows[] = $values;
        }

        // Required before the connection can be reused: the result set is
        // unbuffered, so the remaining rows are still on the wire.
        $statement->closeCursor();

        return ['columns' => $columns, 'rows' => $rows, 'affected' => 0, 'truncated' => $truncated, 'is_result_set' => true];
    }

    /**
     * Make one cell safe to ship as JSON.
     *
     * BLOB and VARBINARY columns return raw bytes that are not valid UTF-8;
     * handing those to json_encode fails the whole response, so they are
     * rendered as a hex preview instead. Long text is clipped so a single
     * megabyte-sized cell cannot bloat the payload.
     */
    private function presentValue(mixed $value): mixed
    {
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        $string = (string) $value;

        if (! mb_check_encoding($string, 'UTF-8')) {
            $preview = strtoupper(bin2hex(substr($string, 0, 16)));

            return '0x'.$preview.(strlen($string) > 16 ? '… ('.strlen($string).' bytes)' : '');
        }

        if (mb_strlen($string) > self::MAX_VALUE_CHARS) {
            return mb_substr($string, 0, self::MAX_VALUE_CHARS).'…';
        }

        return $string;
    }

    /** Backticks are the only character that can break out of a quoted identifier. */
    private function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * A per-database clone of the forge_mysql connection.
     *
     * forge_mysql itself has no default schema, and issuing `USE` would leave
     * that state on a shared connection. Unbuffered queries let a SELECT over
     * a huge table stream so the row cap in execute() actually bounds memory
     * instead of arriving after PHP has already materialised every row.
     */
    private function connection(string $database): Connection
    {
        config([
            'database.connections.'.self::CONNECTION => array_merge(
                (array) config('database.connections.forge_mysql'),
                [
                    'database' => $database,
                    'options' => [PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false],
                ],
            ),
        ]);

        DB::purge(self::CONNECTION);

        return DB::connection(self::CONNECTION);
    }
}
