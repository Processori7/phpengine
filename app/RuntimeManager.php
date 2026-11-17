<?php
declare(strict_types=1);

/**
 * Ships a portable CPython with the application so a PHP-only host can run Python.
 * Artifacts come from astral-sh/python-build-standalone and are SHA256-verified.
 */
final class RuntimeManager
{
    private const RELEASE = '20261110';
    private const PYTHON  = '3.12.16';
    private const BASE_URL = 'https://github.com/astral-sh/python-build-standalone/releases/download/' . self::RELEASE . '/';
    private const MAX_DOWNLOAD = 157286400; // 150 MB

    public static function platform(): ?string
    {
        $machine = self::machine();
        $family = PHP_OS_FAMILY;

        if ($family === 'Windows') {
            return match ($machine) {
                'aarch64', 'arm64' => 'aarch64-pc-windows-msvc',
                default            => 'x86_64-pc-windows-msvc',
            };
        }
        if ($family === 'Darwin') {
            return match ($machine) {
                'arm64', 'aarch64' => 'aarch64-apple-darwin',
                default            => 'x86_64-apple-darwin',
            };
        }
        if ($family !== 'Linux') return null;

        $arch = match ($machine) {
            'x86_64', 'amd64'  => self::isMusl() ? 'x86_64-unknown-linux-musl' : 'x86_64-unknown-linux-gnu',
            'aarch64', 'arm64' => self::isMusl() ? 'aarch64-unknown-linux-musl' : 'aarch64-unknown-linux-gnu',
            'armv7l'           => 'armv7-unknown-linux-gnueabihf',
            'ppc64le'          => 'ppc64le-unknown-linux-gnu',
            'riscv64'          => 'riscv64-unknown-linux-gnu',
            's390x'            => 's390x-unknown-linux-gnu',
            default            => null,
        };
        return $arch;
    }

    /**
     * CPU architecture. php_uname() is disabled on some shared hosts (it returns
     * false there), so fall back to the process environment and word size instead
     * of reporting the platform as unsupported.
     */
    private static function machine(): string
    {
        $raw = php_uname('m');
        $machine = strtolower(trim(is_string($raw) ? $raw : ''));
        if ($machine !== '') return $machine;

        if (PHP_OS_FAMILY === 'Windows') {
            $env = strtolower(trim((string) getenv('PROCESSOR_ARCHITECTURE')));
            return match ($env) {
                'amd64', 'x86_64' => 'x86_64',
                'arm64'           => 'aarch64',
                'x86'             => 'i686',
                default           => PHP_INT_SIZE === 8 ? 'x86_64' : 'i686',
            };
        }
        // Every mainstream 64-bit Linux target is x86_64 unless php_uname() says otherwise.
        return PHP_INT_SIZE === 8 ? 'x86_64' : 'i686';
    }

    public static function assetName(): ?string
    {
        $platform = self::platform();
        return $platform === null
            ? null
            : sprintf('cpython-%s+%s-%s-install_only_stripped.tar.gz', self::PYTHON, self::RELEASE, $platform);
    }

    public static function directory(): string
    {
        self::migrateLegacy();
        return (string) Config::get('runtime') . '/python/' . (self::platform() ?? 'unknown');
    }

    /**
     * Older builds kept the interpreter in ./runtime/python. That directory sits in
     * the web root, where a real "runtime" folder shadows the /runtime route, so the
     * interpreter now lives under storage/. Move an existing install once.
     */
    public static function migrateLegacy(): void
    {
        $legacy = (string) Config::get('root') . '/runtime/python';
        $current = (string) Config::get('runtime') . '/python';
        if ($legacy === $current || !is_dir($legacy) || is_dir($current)) return;
        if (!is_dir(dirname($current))) @mkdir(dirname($current), 0775, true);
        @rename($legacy, $current);
        @rmdir((string) Config::get('root') . '/runtime');
    }

    public static function binary(): string
    {
        $dir = self::directory();
        return PHP_OS_FAMILY === 'Windows'
            ? $dir . '/python/python.exe'
            : $dir . '/python/bin/' . (PHP_OS_FAMILY === 'Darwin' ? 'python3' : 'python3');
    }

    public static function installed(): bool
    {
        return is_file(self::binary()) && is_file(self::directory() . '/installed.json');
    }

    public static function command(): ?array
    {
        if (!self::installed()) return null;
        $bin = self::binary();
        return ['cmd' => [$bin], 'bin' => $bin, 'exe' => $bin, 'source' => 'bundled', 'version' => self::version($bin)];
    }

    public static function version(?string $bin = null): string
    {
        $bin = $bin ?? self::binary();
        if (!is_file($bin)) return '';
        $res = Process::execute([$bin, '--version'], null, [], 20, 4096);
        return trim($res['out']);
    }

    public static function status(): array
    {
        $asset = self::assetName();
        $meta = self::installed() ? json_decode((string) file_get_contents(self::directory() . '/installed.json'), true) : null;
        $bytes = 0;
        if (self::installed()) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::directory(), FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) if ($f->isFile()) $bytes += $f->getSize();
        }
        return [
            'platform'   => self::platform() ?? 'unsupported',
            'asset'      => $asset ?? '—',
            'installed'  => self::installed(),
            'version'    => self::installed() ? self::version() : '',
            'binary'     => self::binary(),
            'size'       => $bytes,
            'installed_at' => is_array($meta) ? ($meta['installed_at'] ?? 0) : 0,
            'sha256'     => is_array($meta) ? ($meta['sha256'] ?? '') : '',
            'supported'  => $asset !== null,
            'can_exec'   => Process::mode() !== null,
            'exec_mode'  => Process::mode() ?? 'none',
            'free_space' => @disk_free_space((string) Config::get('root')),
        ];
    }

    /** Downloads, verifies and unpacks the portable interpreter. */
    public static function install(): array
    {
        $asset = self::assetName();
        if ($asset === null) {
            return ['ok' => false, 'message' => 'Архитектура хостинга не поддерживается сборкой.'];
        }
        if (Process::mode() === null) {
            return ['ok' => false, 'message' => 'Все функции запуска процессов отключены — установить и запустить Python невозможно.'];
        }
        if (!ini_get('allow_url_fopen') && !function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'На хостинге отключён allow_url_fopen и нет curl: нельзя скачать интерпретатор. Загрузите архив вручную (см. подсказку на странице среды).'];
        }

        $tmpDir = sys_get_temp_dir() . '/pyhost_runtime_' . Security::randomToken(6);
        if (!mkdir($tmpDir, 0700, true)) return ['ok' => false, 'message' => 'Не удалось создать временный каталог.'];

        try {
            $sumsFile = $tmpDir . '/SHA256SUMS';
            self::download(self::BASE_URL . 'SHA256SUMS', $sumsFile);
            $expected = self::hashFromSums($sumsFile, $asset);
            if ($expected === null) {
                return ['ok' => false, 'message' => 'Не найдена контрольная сумма для ' . $asset . '. Установка прервана.'];
            }

            $archive = $tmpDir . '/' . $asset;
            self::download(self::BASE_URL . $asset, $archive);
            $actual = hash_file('sha256', $archive);
            if (!hash_equals($expected, (string) $actual)) {
                return ['ok' => false, 'message' => 'SHA256 не совпадает. Архив повреждён или подменён — установка прервана.'];
            }

            $target = self::directory();
            if (is_dir($target)) self::remove();
            if (!mkdir($target, 0775, true)) return ['ok' => false, 'message' => 'Не удалось создать каталог runtime/python.'];

            $extracted = self::extract($archive, $target);
            if (!$extracted) {
                self::remove();
                return ['ok' => false, 'message' => 'Не удалось распаковать архив. Нужен PHP-модуль phar или утилита tar.'];
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                @chmod(self::binary(), 0755);
            }

            $res = Process::execute([self::binary(), '-c', 'import sys; print(sys.version.split()[0])'], null, [], 30, 1024);
            if ($res['code'] !== 0) {
                self::remove();
                return ['ok' => false, 'message' => 'Интерпретатор распакован, но не запускается: ' . trim($res['out'])];
            }

            file_put_contents($target . '/installed.json', json_encode([
                'asset'        => $asset,
                'version'      => trim($res['out']),
                'sha256'       => $expected,
                'installed_at' => time(),
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            Security::audit('runtime_installed', $asset);
            Telegram::notify('runtime', '🐍 Встроенный Python установлен: <b>' . trim($res['out']) . '</b> (' . $asset . ')');
            return ['ok' => true, 'message' => 'Встроенный Python ' . trim($res['out']) . ' установлен и проверен (SHA256 совпадает).'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Ошибка установки: ' . $e->getMessage()];
        } finally {
            self::deleteTree($tmpDir);
        }
    }

    public static function remove(): void
    {
        $base = realpath((string) Config::get('runtime') . '/python');
        $target = realpath(self::directory());
        if ($base !== false && $target !== false && Security::isInside($base, $target)) {
            self::deleteTree($target);
        }
        Security::audit('runtime_removed');
    }

    /** Manual fallback: upload the archive through the admin form. */
    public static function installFromUpload(string $tmp): array
    {
        $asset = self::assetName();
        if ($asset === null) return ['ok' => false, 'message' => 'Платформа не поддерживается.'];
        $expected = self::expectedHashRemote($asset);
        if ($expected === null) return ['ok' => false, 'message' => 'Не удалось получить SHA256SUMS с GitHub.'];
        if (!hash_equals($expected, (string) hash_file('sha256', $tmp))) {
            return ['ok' => false, 'message' => 'SHA256 загруженного архива не совпадает с официальным.'];
        }
        $local = sys_get_temp_dir() . '/' . $asset;
        copy($tmp, $local);
        try {
            $target = self::directory();
            if (is_dir($target)) self::remove();
            mkdir($target, 0775, true);
            if (!self::extract($local, $target)) throw new RuntimeException('Не удалось распаковать архив (нужен phar или tar).');
            if (PHP_OS_FAMILY !== 'Windows') @chmod(self::binary(), 0755);
            $res = Process::execute([self::binary(), '-c', 'print(1)'], null, [], 30, 1024);
            if ($res['code'] !== 0) throw new RuntimeException('Интерпретатор не запускается.');
            file_put_contents($target . '/installed.json', json_encode([
                'asset' => $asset, 'sha256' => $expected, 'installed_at' => time(),
            ], JSON_PRETTY_PRINT));
            return ['ok' => true, 'message' => 'Встроенный Python установлен из загруженного архива.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            @unlink($local);
        }
    }

    private static function expectedHashRemote(string $asset): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sums_');
        try {
            self::download(self::BASE_URL . 'SHA256SUMS', (string) $tmp);
            return self::hashFromSums((string) $tmp, $asset);
        } catch (Throwable) {
            return null;
        } finally {
            @unlink((string) $tmp);
        }
    }

    private static function hashFromSums(string $file, string $asset): ?string
    {
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', trim($line), $m) && basename(trim($m[2])) === $asset) {
                return strtolower($m[1]);
            }
        }
        return null;
    }

    private static function download(string $url, string $dest): void
    {
        $ctx = stream_context_create(['http' => [
            'timeout' => 120,
            'follow_location' => 1,
            'max_redirects' => 5,
            'header' => "User-Agent: PyHostEngine/1.0\r\n",
        ]]);
        $in = @fopen($url, 'rb', false, $ctx);
        if ($in === false) {
            if (function_exists('curl_init')) {
                self::downloadCurl($url, $dest);
                return;
            }
            throw new RuntimeException('Не удалось скачать ' . basename($url) . '. Проверьте доступ к github.com.');
        }
        $out = fopen($dest, 'wb');
        if ($out === false) { fclose($in); throw new RuntimeException('Не удалось записать временный файл.'); }
        $total = 0;
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false || $chunk === '') break;
            $total += strlen($chunk);
            if ($total > self::MAX_DOWNLOAD) { fclose($in); fclose($out); throw new RuntimeException('Файл слишком большой.'); }
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);
    }

    private static function downloadCurl(string $url, string $dest): void
    {
        $fh = fopen($dest, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_USERAGENT => 'PyHostEngine/1.0',
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if (!$ok) throw new RuntimeException('curl: ' . $err);
    }

    private static function extract(string $archive, string $target): bool
    {
        if (class_exists('PharData')) {
            try {
                $phar = new PharData($archive);
                $phar->extractTo($target, null, true);
                return true;
            } catch (Throwable) {
                // fall through to tar
            }
        }
        if (Process::mode() !== null) {
            $res = Process::execute(['tar', '-xzf', $archive, '-C', $target], null, [], 300, 4096);
            return $res['code'] === 0;
        }
        return false;
    }

    private static function isMusl(): bool
    {
        return @is_file('/lib/ld-musl-x86_64.so.1') || @is_file('/lib/ld-musl-aarch64.so.1');
    }

    private static function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isLink() || $item->isFile()) @unlink($item->getPathname());
            else @rmdir($item->getPathname());
        }
        @rmdir($dir);
    }
}
