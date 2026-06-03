<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
auth_boot();
logout();
redirect('/public/login.php');
