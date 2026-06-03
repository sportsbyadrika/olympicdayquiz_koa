<?php
/** Root entry point — forwards to the public home page. */
declare(strict_types=1);
require_once __DIR__ . '/config/settings.php';
header('Location: ' . BASE_URL . '/public/index.php');
exit;
