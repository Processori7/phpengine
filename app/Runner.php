<?php
declare(strict_types=1);

/** Safe process execution: no shell, tokenised commands, captured stdout/stderr. */
final class Runner
{
    /**
     * Small helper used for quick probes (python -V, pip list, etc.).
     *
     * $stdin is streamed into the child while its output is being drained, so a
     * CGI request body can be large without deadlocking on a full pipe buffer.
     */
    public static function capture(array $cmd, ?string $cwd = null, array $extraEnv = [], int $timeout = 30, ?string $stdin = null): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, self::env($extraEnv));
        if (!is_resource($proc)) {
            return ['code' => 127, 'out' => 'Не удалось запустить процесс.'];
        }

        $input  = (string) $stdin;
        $sent   = 0;
        $total  = strlen($input);
        if ($total === 0) {
            fclose($pipes[0]);
            $pipes[0] = null;
        } else {
            stream_set_blocking($pipes[0], false);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $start = microtime(true);
        while (true) {
            if ($pipes[0] !== null) {
                if ($sent < $total) {
                    $written = @fwrite($pipes[0], substr($input, $sent, 65536));
                    if ($written === false) {
                        // The child closed stdin early; stop feeding it.
                        @fclose($pipes[0]);
                        $pipes[0] = null;
                    } else {
                        $sent += $written;
                    }
                }
                if ($pipes[0] !== null && $sent >= $total) {
                    @fclose($pipes[0]);
                    $pipes[0] = null;
                }
            }
            $out .= (string) fread($pipes[1], 8192);
            $out .= (string) fread($pipes[2], 8192);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                // Drain remaining bytes before closing.
                usleep(20000);
                $out .= stream_get_contents($pipes[1]);
                $out .= stream_get_contents($pipes[2]);
                break;
            }
            if (microtime(true) - $start > $timeout) {
                self::kill($proc);
                $out .= "\n[timeout] Процесс превысил лимит времени ({$timeout} с).\n";
                break;
            }
            usleep(20000);
        }
        if ($pipes[0] !== null) {
            @fclose($pipes[0]);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ['code' => $code, 'out' => mb_convert_encoding($out, 'UTF-8', 'UTF-8')];
    }

    /**
     * Full run with a streaming log file. Returns status for the DB row.
     * @return array{status: string, exit_code: int, bytes: int}
     */
    public static function execute(array $tokens, string $cwd, array $extraEnv, int $timeout, string $logFile): array
    {
        if (!Process::has('proc_open')) {
            return self::executeFallback($tokens, $cwd, $extraEnv, $timeout, $logFile);
        }

        $fh = fopen($logFile, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Не удалось открыть файл лога: ' . $logFile);
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $start = microtime(true);
        fwrite($fh, '$ ' . Security::logSafe(implode(' ', array_map(fn($t) => self::quoteArg($t), $tokens))) . "\n");
        fwrite($fh, 'cwd: ' . Security::logSafe($cwd) . "\n");
        fwrite($fh, str_repeat('─', 60) . "\n");
        fflush($fh);

        $proc = @proc_open($tokens, $descriptors, $pipes, $cwd, self::env($extraEnv));
        if (!is_resource($proc)) {
            fwrite($fh, "[error] Не удалось запустить процесс. Проверьте, что exec/proc_open разрешены на хостинге.\n");
            fclose($fh);
            return ['status' => 'failed', 'exit_code' => 127, 'bytes' => (int) filesize($logFile)];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $bytes = 0;
        $max   = (int) Config::get('security.max_output', 8388608);
        $timedOut = false;
        $truncated = false;

        while (true) {
            $stdout = (string) fread($pipes[1], 8192);
            $stderr = (string) fread($pipes[2], 8192);
            if ($stdout !== '' || $stderr !== '') {
                if ($stderr !== '') {
                    $stderr = '[stderr] ' . str_replace("\n", "\n[stderr] ", rtrim($stderr, "\n")) . "\n";
                }
                $chunk = $stdout . $stderr;
                if ($bytes + strlen($chunk) > $max) {
                    $chunk = mb_strcut($chunk, 0, max(0, $max - $bytes));
                    $truncated = true;
                }
                if ($chunk !== '') {
                    fwrite($fh, $chunk);
                    $bytes += strlen($chunk);
                    fflush($fh);
                }
                if ($truncated) {
                    break;
                }
            }

            $status = proc_get_status($proc);
            if (!$status['running']) {
                usleep(20000);
                $restOut = (string) stream_get_contents($pipes[1]);
                $restErr = (string) stream_get_contents($pipes[2]);
                if ($restErr !== '') {
                    $restErr = '[stderr] ' . str_replace("\n", "\n[stderr] ", rtrim($restErr, "\n")) . "\n";
                }
                $rest = $restOut . $restErr;
                if ($bytes + strlen($rest) > $max) {
                    $rest = mb_strcut($rest, 0, max(0, $max - $bytes));
                    $truncated = true;
                }
                fwrite($fh, $rest);
                $bytes += strlen($rest);
                break;
            }
            if (microtime(true) - $start > $timeout) {
                $timedOut = true;
                self::kill($proc);
                break;
            }
            usleep(15000);
        }

        if ($truncated) {
            self::kill($proc);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $duration = round(microtime(true) - $start, 2);

        $footer = str_repeat('─', 60) . "\n";
        if ($timedOut) {
            $footer .= "[timeout] Превышен лимит {$timeout} с. Процесс остановлен.\n";
        } elseif ($truncated) {
            $footer .= "[limit] Превышен лимит вывода " . self::humanBytes($max) . ". Процесс остановлен.\n";
        }
        $footer .= '[exit code] ' . $code . '   [time] ' . $duration . " s\n";
        fwrite($fh, $footer);
        fclose($fh);
        $bytes = (int) filesize($logFile);

        $status = $timedOut ? 'timeout' : ($truncated ? 'failed' : ($code === 0 ? 'success' : 'failed'));
        return ['status' => $status, 'exit_code' => $code, 'bytes' => $bytes];
    }

    /**
     * Turns a user-entered command line into an argv array.
     * Only quoting is interpreted — there is no shell, no globbing, no expansion.
     */
    public static function tokenize(string $raw): array
    {
        $tokens = [];
        $current = '';
        $quote = null;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                } else {
                    $current .= $ch;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }
            if ($ch === ' ' || $ch === "\t") {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
                continue;
            }
            $current .= $ch;
        }
        if ($quote !== null) {
            throw new InvalidArgumentException('Незакрытая кавычка в команде.');
        }
        if ($current !== '') {
            $tokens[] = $current;
        }
        return $tokens;
    }

    /** Maps aliases to concrete, allowed executables and rejects metacharacters. */
    public static function buildCommand(string $raw, ?string $venvPath, array $pythonCmd): array
    {
        $tokens = self::tokenize(trim($raw));
        if ($tokens === []) {
            throw new InvalidArgumentException('Команда пуста.');
        }
        if (!Security::safeCommand($raw)) {
            throw new InvalidArgumentException('Команда содержит запрещённые символы вне кавычек: ; & | $ ` > <.');
        }
        foreach ($tokens as $t) {
            if (preg_match('/[\x00-\x1f\x7f]/', $t)) {
                throw new InvalidArgumentException('Команда содержит недопустимые управляющие символы.');
            }
        }

        $first = strtolower(basename(array_shift($tokens)));
        $binDir = $venvPath ? self::venvBinDir($venvPath) : null;
        $pipInVenv = $binDir ? self::firstExisting([$binDir . '/pip', $binDir . '/pip3', $binDir . '/pip.exe']) : null;
        // A venv only shadows site-packages for its own interpreter, so `python`
        // must resolve to the venv binary whenever the project has one.
        $pythonInVenv = $binDir ? self::firstExisting([
            $binDir . '/python', $binDir . '/python3', $binDir . '/python.exe', $binDir . '/python3.exe',
        ]) : null;

        if (in_array($first, ['python', 'python3', 'python.exe', 'python3.exe', 'py'], true)) {
            if ($first === 'py' && ($tokens[0] ?? '') === '-3') {
                array_shift($tokens);
            }
            $exe = $pythonInVenv !== null ? [$pythonInVenv] : $pythonCmd;
        } elseif (in_array($first, ['pip', 'pip3', 'pip.exe', 'pip3.exe'], true)) {
            $exe = $pipInVenv ? [$pipInVenv] : array_merge($pythonCmd, ['-m', 'pip']);
        } elseif ($first === 'pytest' || $first === 'pytest.exe') {
            $pytest = $binDir ? self::firstExisting([$binDir . '/pytest', $binDir . '/pytest.exe']) : null;
            $exe = $pytest ? [$pytest] : array_merge($pythonCmd, ['-m', 'pytest']);
        } elseif ($first === 'uv' || $first === 'uv.exe') {
            $uv = $binDir ? self::firstExisting([$binDir . '/uv', $binDir . '/uv.exe']) : null;
            if (!$uv) {
                throw new InvalidArgumentException('uv не найден в venv. Установите его через pip.');
            }
            $exe = [$uv];
        } else {
            throw new InvalidArgumentException("Команда «{$first}» не разрешена. Доступно: python, pip, pytest, uv.");
        }

        return array_merge($exe, $tokens);
    }

    public static function venvBinDir(string $venvPath): string
    {
        if (is_dir($venvPath . '/bin')) {
            return $venvPath . '/bin';
        }
        return $venvPath . '/Scripts'; // Windows
    }

    public static function venvEnv(?string $venvPath): array
    {
        if ($venvPath === null) {
            return [];
        }
        $bin = self::venvBinDir($venvPath);
        $path = getenv('PATH') ?: '';
        return [
            'VIRTUAL_ENV'          => $venvPath,
            'PATH'                 => $bin . PATH_SEPARATOR . $path,
            'PYTHONUNBUFFERED'     => '1',
            'PYTHONDONTWRITEBYTECODE' => '1',
            'PYTHONIOENCODING'     => 'utf-8',
        ];
    }

    /** Used when the host disabled proc_open but left exec/shell_exec/popen. */
    private static function executeFallback(array $tokens, string $cwd, array $extraEnv, int $timeout, string $logFile): array
    {
        $fh = fopen($logFile, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Не удалось открыть файл лога: ' . $logFile);
        }
        $mode = Process::mode();
        fwrite($fh, '$ ' . Security::logSafe(implode(' ', array_map(fn($t) => self::quoteArg($t), $tokens))) . "\n");
        fwrite($fh, 'cwd: ' . Security::logSafe($cwd) . "\n");
        fwrite($fh, 'exec mode: ' . ($mode ?? 'none') . "\n");
        fwrite($fh, str_repeat('─', 60) . "\n");
        fflush($fh);

        $result = Process::execute($tokens, $cwd, $extraEnv, $timeout, (int) Config::get('security.max_output', 8388608));
        fwrite($fh, $result['out']);
        fwrite($fh, str_repeat('─', 60) . "\n");
        $status = $result['code'] === 127 ? 'failed' : ($result['code'] === 0 ? 'success' : 'failed');
        if ($result['timedOut']) $status = 'timeout';
        fwrite($fh, '[exit code] ' . $result['code'] . "   [mode] {$result['mode']}\n");
        fclose($fh);

        return ['status' => $status, 'exit_code' => $result['code'], 'bytes' => (int) filesize($logFile)];
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    private static function env(array $extra): array
    {
        return array_merge(getenv() ?: [], $extra);
    }

    private static function firstExisting(array $paths): ?string
    {
        foreach ($paths as $p) {
            if (is_file($p)) return $p;
        }
        return null;
    }

    private static function quoteArg(string $arg): string
    {
        return preg_match('/^[\w.\/:=@,+-]+$/', $arg) ? $arg : '"' . str_replace('"', '\\"', $arg) . '"';
    }

    private static function kill($proc): void
    {
        $status = proc_get_status($proc);
        if (!empty($status['pid'])) {
            if (function_exists('posix_kill')) {
                @posix_kill($status['pid'], 15);
            }
        }
        proc_terminate($proc);
        usleep(300000);
        $status = proc_get_status($proc);
        if ($status['running']) {
            if (function_exists('posix_kill') && !empty($status['pid'])) {
                @posix_kill($status['pid'], 9);
            }
            proc_terminate($proc, 9);
        }
    }
}
