<?php
declare(strict_types=1);

/*
 * Root front controller for hosts where DocumentRoot points at the project root.
 * The canonical front controller stays in public/index.php; this file only
 * normalises PATH_INFO-style routes and delegates to it.
 */
if (!empty($_SERVER['PATH_INFO']) && str_starts_with((string) ($_SERVER['PHP_SELF'] ?? ''), '/index.php')) {
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $_SERVER['REQUEST_URI'] = (string) $_SERVER['PATH_INFO'] . ($query !== '' ? '?' . $query : '');
}

require __DIR__ . '/public/index.php';
