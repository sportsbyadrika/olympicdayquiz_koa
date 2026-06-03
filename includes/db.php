<?php
/**
 * Convenience include that exposes the shared PDO handle.
 * Pages can `require_once __DIR__ . '/../includes/db.php';` then use db().
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
