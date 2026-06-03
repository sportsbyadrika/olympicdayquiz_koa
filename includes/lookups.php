<?php
/**
 * Master lookup helpers for dropdowns (school_types, syllabi).
 * The table name is whitelisted to keep it safe to interpolate.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

const LOOKUP_TABLES = ['school_types', 'syllabi'];

/** Rows [id => name] for a whitelisted lookup table, ordered by name. */
function lookup_map(string $table): array
{
    if (!in_array($table, LOOKUP_TABLES, true)) {
        return [];
    }
    $map = [];
    foreach (db()->query("SELECT id, name FROM {$table} ORDER BY name")->fetchAll() as $row) {
        $map[(int) $row['id']] = $row['name'];
    }
    return $map;
}

/** Resolve a lookup value name to its id (case-insensitive), or null. */
function lookup_id_by_name(string $table, string $name): ?int
{
    if (!in_array($table, LOOKUP_TABLES, true) || trim($name) === '') {
        return null;
    }
    $st = db()->prepare("SELECT id FROM {$table} WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $st->execute([trim($name)]);
    $id = $st->fetchColumn();
    return $id !== false ? (int) $id : null;
}
