<?php
/**
 * Environment/config loader.
 *
 * Resolution order for every setting:
 *   1. config/local.php   (server-only, gitignored, NOT deployed — survives deploys)
 *   2. environment variable
 *   3. built-in default
 *
 * Because config/local.php is never committed and never copied by .cpanel.yml,
 * your production credentials are preserved across `git pull` / cPanel deploys.
 * Copy config/local.example.php to config/local.php on the server and fill it in.
 */

declare(strict_types=1);

/** Load and cache the optional local override array. */
function local_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/local.php';
        $cfg = is_file($path) ? (array) require $path : [];
    }
    return $cfg;
}

/**
 * Read a config value: local.php[$section][$key] → getenv($env) → $default.
 * Pass $section = null to look at the top level of local.php.
 */
function cfg(?string $section, string $key, string $env, ?string $default = null): ?string
{
    $local = local_config();
    if ($section !== null) {
        if (isset($local[$section][$key]) && $local[$section][$key] !== '') {
            return (string) $local[$section][$key];
        }
    } elseif (isset($local[$key]) && $local[$key] !== '') {
        return (string) $local[$key];
    }
    $env = getenv($env);
    if ($env !== false && $env !== '') {
        return $env;
    }
    return $default;
}
