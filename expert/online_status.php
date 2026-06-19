<?php
/** Expert entry to the shared Online Status live dashboard. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin', 'expert');
require dirname(__DIR__) . '/includes/online_status_view.php';
