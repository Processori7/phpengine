<?php
declare(strict_types=1);

/**
 * Process execution with graceful degradation.
 * Hosting panels disable exec functions one by one — this class picks whatever
 * is still available and always passes arguments as an escaped argv string.
 */
final class Process
{
    private const MODES = ['proc_open', 'exec', 'shell_exec', 'popen', 'system', 'passthru'];

    public static function mode(): ?string
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        foreach (self::MODES as $mode) {
            if (function_exists($mode) && !in_array($mode, $disabled, true)) {
                return $mode;
            }
        }
        return null;
    }

    public static function has(string $mode): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists($mode) && !in_array($mode, $disabled, true);
    }

    /** Escapes every argv element for the shell-based fallback modes. */
    public static function commandString(array $cmd): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // PHP's escapeshellarg() on Windows replaces inner double quotes with
            // spaces, which silently breaks `python -c "..."`. Use cmd.exe quoting.
            return implode(' ', array_map(static function ($a) {
                return '"' . str_replace('"', '""', (string) $a) . '"';
            }, $cmd));
        }
        return implode(' ', array_map(static fn($a) => escapeshellarg((string) $a), $cmd));
    }

    private static function withCwd(string $cmd, ?string $cwd): string
    {
        if ($cwd === null || !is_dir($cwd)) return $cmd;
        $prefix = DIRECTORY_SEPARATOR === '\\'
            ? 'cd /d ' . escapeshellarg($cwd) . ' & '
            : 'cd ' . escapeshellarg($cwd) . ' && ';
        return $prefix . $cmd;
    }

    private static function withTimeout(string $cmd, int $timeout): string
    {
        if (DIRECTORY_SEPARATOR === '\\') return $cmd;
        $hasTimeout = @is_file('/usr/bin/timeout') || @is_file('/bin/timeout');
        return $hasTimeout ? 'timeout ' . max(1, $timeout) . 's ' . $cmd : $cmd;
    }

    /**
     * Run a command without proc_open. Returns [code, out, mode, timedOut].
     *
     * $stdin is only honoured in proc_open mode — the shell-based fallbacks have
     * no portable way to feed a child's stdin, so callers that need a body must
     * check Process::mode() first (WebApp::runCgi does exactly that).
     */
    public static function execute(array $cmd, ?string $cwd, array $env, int $timeout, int $maxBytes, ?string $stdin = null): array
    {
        $mode = self::mode();
        if ($mode === null) {
            return [
                'code' => 127,
                'out'  => "[error] На хостинге отключены все функции запуска процессов "
                        . "(proc_open, exec, shell_exec, popen, system, passthru). "
                        . "Выполнение Python невозможно без одной из них.",
                'mode' => null,
                'timedOut' => false,
            ];
        }
        if ($mode === 'proc_open') {
            $res = Runner::capture($cmd, $cwd, $env, $timeout, $stdin);
            return [
                'code' => $res['code'],
                'out'  => mb_strcut($res['out'], 0, $maxBytes),
                'mode' => $mode,
                'timedOut' => str_contains($res['out'], '[timeout]'),
            ];
        }

        // environ for shell-based modes: putenv() is inherited by children.
        $restore = [];
        foreach ($env as $key => $value) {
            $restore[$key] = getenv($key);
            @putenv($key . '=' . $value);
        }

        $raw = self::withTimeout(self::withCwd(self::commandString($cmd), $cwd), $timeout);
        $code = 0;
        $out = '';
        $timedOut = false;

        try {
            switch ($mode) {
                case 'exec':
                    $lines = [];
                    @exec($raw . ' 2>&1', $lines, $code);
                    $out = implode("\n", $lines) . "\n";
                    break;

                case 'shell_exec':
                    $out = (string) @shell_exec($raw . ' 2>&1; printf "\\n__PYHOST_EXIT__%s" "$?"');
                    if (preg_match('/\n__PYHOST_EXIT__(-?\d+)\s*$/', $out, $m)) {
                        $code = (int) $m[1];
                        $out = substr($out, 0, strlen($out) - strlen($m[0]));
                    }
                    break;

                case 'popen':
                    $handle = @popen($raw . ' 2>&1', 'r');
                    if (is_resource($handle)) {
                        while (!feof($handle)) {
                            $chunk = (string) fread($handle, 8192);
                            if (strlen($out) + strlen($chunk) > $maxBytes) {
                                $out .= mb_strcut($chunk, 0, max(0, $maxBytes - strlen($out)));
                                $timedOut = true;
                                break;
                            }
                            $out .= $chunk;
                        }
                        $code = (int) @pclose($handle);
                    } else {
                        $code = 127;
                    }
                    break;

                case 'system':
                case 'passthru':
                    ob_start();
                    if ($mode === 'system') {
                        @system($raw . ' 2>&1', $code);
                    } else {
                        @passthru($raw . ' 2>&1', $code);
                    }
                    $out = (string) ob_get_clean();
                    break;
            }
        } finally {
            foreach ($restore as $key => $value) {
                if ($value === false) {
                    @putenv($key);
                } else {
                    @putenv($key . '=' . $value);
                }
            }
        }

        if (strlen($out) > $maxBytes) {
            $out = mb_strcut($out, 0, $maxBytes);
            $timedOut = true;
        }
        if ($timedOut) {
            $out .= "\n[limit] Превышен лимит вывода или времени.\n";
        }
        return ['code' => $code, 'out' => $out, 'mode' => $mode, 'timedOut' => $timedOut];
    }
}
