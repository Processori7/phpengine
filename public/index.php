<?php
declare(strict_types=1);

/*
 * Never print errors to the browser: a stack trace or a warning can reveal
 * paths, versions and configuration. Everything goes to a private log file.
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('html_errors', '0');
error_reporting(E_ALL);
$logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
ini_set('error_log', $logDir . '/php-error.log');
header_remove('X-Powered-By');
header_remove('Server');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    @error_log(sprintf('[%s] %s in %s:%d', date('c'), $message, $file, $line));
    return true;
});

foreach (['Config', 'SecureStore', 'Database', 'Security', 'Telegram', 'Auth', 'Captcha', 'Process', 'Runner', 'RuntimeManager', 'Env', 'ProjectManager', 'VenvManager', 'TOTP', 'App'] as $class) {
    require_once __DIR__ . '/../app/' . $class . '.php';
}

/** Short aliases used by templates. */
function e(mixed $value): string
{
    return Security::e($value);
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . Security::e(Security::csrfToken()) . '">';
}

try {
    App::boot();
    App::run();
} catch (Throwable $ex) {
    @error_log(
        '[' . date('c') . '] ' . get_class($ex) . ': ' . $ex->getMessage()
        . ' @ ' . $ex->getFile() . ':' . $ex->getLine() . PHP_EOL,
        3,
        $logDir . '/error.log'
    );
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header_remove('X-Powered-By');
    // Details are shown only in CLI (local maintenance), never to a web request.
    if (PHP_SAPI === 'cli') {
        echo '500 — ' . $ex->getMessage() . PHP_EOL;
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>500</title>'
           . '<h1>500 — Internal Server Error</h1>'
           . '<p>The request could not be processed. Details were written to a private log.</p>';
    }
}
