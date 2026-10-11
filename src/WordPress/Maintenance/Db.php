<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress\Maintenance;

use HtmlPhp\Exception\TemplateException;

final class Db
{
    private function __construct() {}

    /**
     * The global $wpdb instance (or null outside WordPress).
     */
    public static function wpdb(): ?object
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;

        return \is_object($wpdb) ? $wpdb : null;
    }

    /**
     * Prepares SQL through $wpdb->prepare().
     *
     * @param list<mixed> $params
     */
    public static function prepare(string $sql, array $params = []): string
    {
        $wpdb = self::wpdb();

        if ($wpdb === null) {
            throw new TemplateException('$wpdb is not available.');
        }

        return $params === [] ? $sql : (string) $wpdb->prepare($sql, ...$params);
    }

    /**
     * @param list<mixed> $params
     */
    public static function query(string $sql, array $params = []): int|bool
    {
        $wpdb = self::wpdb() ?? throw new TemplateException('$wpdb is not available.');

        return $wpdb->query(self::prepare($sql, $params));
    }

    /**
     * @param list<mixed> $params
     */
    public static function getVar(string $sql, array $params = []): mixed
    {
        $wpdb = self::wpdb() ?? throw new TemplateException('$wpdb is not available.');

        return $wpdb->get_var(self::prepare($sql, $params));
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public static function getRow(string $sql, array $params = []): ?array
    {
        $wpdb = self::wpdb() ?? throw new TemplateException('$wpdb is not available.');
        $row = $wpdb->get_row(self::prepare($sql, $params), \ARRAY_A);

        return \is_array($row) ? $row : null;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function getResults(string $sql, array $params = []): array
    {
        $wpdb = self::wpdb() ?? throw new TemplateException('$wpdb is not available.');
        $rows = $wpdb->get_results(self::prepare($sql, $params), \ARRAY_A);

        return \is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Per-table row counts and byte sizes (information_schema based).
     *
     * @return list<array{table: string, rows: int, bytes: int}>
     */
    public static function tableSizes(): array
    {
        $rows = self::getResults(
            'SELECT TABLE_NAME AS table_name, TABLE_ROWS AS table_rows,
                    (DATA_LENGTH + INDEX_LENGTH) AS bytes
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY bytes DESC',
        );

        $sizes = [];

        foreach ($rows as $row) {
            $sizes[] = [
                'table' => (string) ($row['table_name'] ?? ''),
                'rows' => (int) ($row['table_rows'] ?? 0),
                'bytes' => (int) ($row['bytes'] ?? 0),
            ];
        }

        return $sizes;
    }

    /**
     * Counts rows of a core table, e.g. posts, postmeta, options.
     */
    public static function countTable(string $table): int
    {
        $wpdb = self::wpdb() ?? throw new TemplateException('$wpdb is not available.');

        if (preg_match('~^[a-z0-9_]+$~i', $table) !== 1) {
            throw new TemplateException('Invalid table name.');
        }

        $prefixed = property_exists($wpdb, 'prefix') ? $wpdb->prefix . $table : $table;

        return (int) self::getVar('SELECT COUNT(*) FROM `' . $prefixed . '`');
    }
}
