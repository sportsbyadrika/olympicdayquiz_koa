<?php
/** Expert entry to the shared support chat. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
require_role('expert');
require dirname(__DIR__) . '/includes/chat_support_view.php';
