<?php
declare(strict_types=1);

/** Project storage: uploads, ZIP extraction, file tree and safe file I/O. */
final class ProjectManager
{
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM projects WHERE id = ?', [$id]);
    }

    public static function all(): array
    {
        return Database::all('SELECT * FROM projects ORDER BY updated_at DESC');
    }

    /** Collects files from $_FILES and returns normalised [relative path => tmp file / content]. */
    public static function collectUploads(): array
    {
        $out = [];
        $max = (int) Config::get('security.max_upload', 26214400);

        // Plain multi-file input (JS adds webkitRelativePath into file_paths).
        $relPaths = [];
        if (isset($_POST['file_paths'])) {
            $decoded = json_decode((string) $_POST['file_paths'], true);
            if (is_array($decoded)) $relPaths = $decoded;
        }
        if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
            foreach ($_FILES['files']['name'] as $i => $name) {
                $error = (int) $_FILES['files']['error'][$i];
                if ($error !== UPLOAD_ERR_OK) continue;
                if ((int) $_FILES['files']['size'][$i] > $max) {
                    throw new RuntimeException('Файл "' . $name . '" превышает лимит ' . Runner::humanBytes($max) . '.');
                }
                $rel = (string) ($relPaths[$i] ?? $name);
                $out[] = ['rel' => $rel, 'tmp' => $_FILES['files']['tmp_name'][$i]];
            }
        }

        if (isset($_FILES['single_file']) && (int) $_FILES['single_file']['error'] === UPLOAD_ERR_OK) {
            if ((int) $_FILES['single_file']['size'] > $max) {
                throw new RuntimeException('Файл слишком большой.');
            }
            $out[] = ['rel' => $_FILES['single_file']['name'], 'tmp' => $_FILES['single_file']['tmp_name']];
        }

        // Text editor: create one file directly from the form.
        if (!empty($_POST['code_name']) && isset($_POST['code_body'])) {
            $name = Security::sanitizeFilename((string) $_POST['code_name']);
            if (!Security::allowedExtension($name)) {
                throw new RuntimeException('Неподдерживаемое расширение файла.');
            }
            if (strlen((string) $_POST['code_body']) > $max) {
                throw new RuntimeException('Содержимое файла превышает лимит ' . Runner::humanBytes($max) . '.');
            }
            $tmp = tempnam(sys_get_temp_dir(), 'pyh_');
            file_put_contents($tmp, (string) $_POST['code_body']);
            $out[] = ['rel' => $name, 'tmp' => $tmp, 'temporary' => true];
        }

        if (isset($_FILES['archive']) && (int) $_FILES['archive']['error'] === UPLOAD_ERR_OK && (int) $_FILES['archive']['size'] > 0) {
            $out = array_merge($out, self::extractZip((string) $_FILES['archive']['tmp_name']));
        }

        return $out;
    }

    public static function create(string $name, array $uploads, string $entrypoint = ''): array
    {
        $name = trim(mb_substr($name, 0, 100));
        if ($name === '') {
            throw new InvalidArgumentException('Укажите название проекта.');
        }
        if ($uploads === []) {
            throw new InvalidArgumentException('Загрузите хотя бы один файл.');
        }

        $base = (string) Config::get('projects');
        if (!is_dir($base)) mkdir($base, 0775, true);

        $slug = self::slug($name);
        while (is_dir($base . '/' . $slug)) {
            $slug = self::slug($name) . '-' . Security::randomToken(3);
        }
        $dir = $base . '/' . $slug;
        if (!mkdir($dir, 0775, true)) {
            throw new RuntimeException('Не удалось создать каталог проекта.');
        }

        try {
            $count = 0;
            foreach ($uploads as $item) {
                $rel = self::normaliseRelative((string) $item['rel']);
                $target = Security::safePath($dir, $rel);
                if ($target === null || is_link($target)) {
                    throw new RuntimeException('Недопустимый путь файла: ' . $rel);
                }
                if (!Security::allowedExtension($rel)) {
                    throw new RuntimeException('Расширение файла не разрешено: ' . $rel);
                }
                if (!@copy($item['tmp'], $target)) {
                    throw new RuntimeException('Не удалось сохранить файл: ' . $rel);
                }
                if (!empty($item['temporary']) && is_file($item['tmp'])) @unlink($item['tmp']);
                $count++;
                if ($count > (int) Config::get('security.max_files', 300)) {
                    throw new RuntimeException('Слишком много файлов в проекте.');
                }
            }
        } catch (Throwable $e) {
            self::deleteTree($dir);
            throw $e;
        }

        if ($entrypoint === '') {
            $entrypoint = self::detectEntrypoint($dir) ?? 'main.py';
        } else {
            $entrypoint = self::normaliseRelative($entrypoint);
            if (!is_file($dir . '/' . $entrypoint)) {
                self::deleteTree($dir);
                throw new RuntimeException('Точка входа не найдена: ' . $entrypoint);
            }
        }

        $now = time();
        $id = Database::insert('projects', [
            'name' => $name, 'slug' => $slug, 'dir' => $dir,
            'entrypoint' => $entrypoint, 'args' => '', 'interpreter' => 'venv',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Security::audit('project_created', $name . ' (' . $count . ' файлов)');
        return self::find($id);
    }

    public static function files(array $project): array
    {
        $out = [];
        $base = realpath($project['dir']);
        if ($base === false) return $out;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($base))), '/');
            if (str_contains($rel, '__pycache__/') || str_contains($rel, '.git/')) continue;
            $out[] = ['path' => $rel, 'size' => $file->getSize()];
            if (count($out) >= 500) break;
        }
        usort($out, fn($a, $b) => strcmp($a['path'], $b['path']));
        return $out;
    }

    public static function read(array $project, string $rel): ?string
    {
        $path = Security::safePath($project['dir'], $rel);
        if ($path === null || !is_file($path) || filesize($path) > 2097152) return null;
        return (string) file_get_contents($path);
    }

    public static function write(array $project, string $rel, string $content): bool
    {
        $rel = self::normaliseRelative($rel);
        if (!Security::allowedExtension($rel)) return false;
        $path = Security::safePath($project['dir'], $rel);
        if ($path === null) return false;
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0775, true)) return false;
        return file_put_contents($path, $content) !== false;
    }

    public static function removeFile(array $project, string $rel): bool
    {
        $path = Security::safePath($project['dir'], $rel);
        if ($path === null || !is_file($path)) return false;
        return @unlink($path);
    }

    public static function remove(int $id): bool
    {
        $project = self::find($id);
        if ($project === null) return false;
        if (!Security::isInside((string) Config::get('projects'), $project['dir'])) return false;
        VenvManager::removeByProjectId($id);
        Database::run('DELETE FROM runs WHERE project_id = ?', [$id]);
        Database::run('DELETE FROM projects WHERE id = ?', [$id]);
        self::deleteTree($project['dir']);
        Security::audit('project_deleted', $project['name']);
        return true;
    }

    public static function detectEntrypoint(string $dir): ?string
    {
        foreach (['main.py', 'app.py', 'run.py', 'index.py', 'start.py'] as $candidate) {
            if (is_file($dir . '/' . $candidate)) return $candidate;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'py') {
                return ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dir))), '/');
            }
        }
        return null;
    }

    public static function slug(string $name): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($name)) ?? 'project';
        return trim($slug, '-') ?: 'project-' . Security::randomToken(4);
    }

    public static function normaliseRelative(string $path): string
    {
        $path = str_replace(['\\', "\0"], ['/', ''], trim($path));
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = Security::sanitizeFilename($part);
        }
        return implode('/', $parts) ?: 'file.txt';
    }

    public static function deleteTree(string $dir): void
    {
        $base = realpath((string) Config::get('projects'));
        $real = realpath($dir);
        if ($real === false || $base === false || !Security::isInside($base, $real)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($real);
    }

    private static function extractZip(string $tmp): array
    {
        $maxTotal = (int) Config::get('security.max_upload', 26214400);
        $maxFiles = (int) Config::get('security.max_files', 300);
        $entries  = [];

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) throw new RuntimeException('Не удалось открыть ZIP-архив.');
            if ($zip->numFiles > $maxFiles + 10) {
                $zip->close();
                throw new RuntimeException('В архиве слишком много файлов.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with($name, '/')) continue;
                $stat = $zip->statIndex($i);
                if (($stat['size'] ?? 0) > $maxTotal) { $zip->close(); throw new RuntimeException('Файл в архиве слишком большой.'); }
                $entries[] = ['name' => $name, 'source' => $name, 'index' => $i];
            }
            $entries = self::stripCommonRoot($entries);
            $out = [];
            $total = 0;
            $compressed = 0;
            foreach ($entries as $entry) {
                $stat = $zip->statIndex($entry['index']) ?: [];
                $external = (int) ($stat['external_attributes'] ?? 0);
                $unixMode = ($external >> 16) & 0xffff;
                if (($unixMode & 0170000) === 0120000) {
                    $zip->close();
                    throw new RuntimeException('Архив содержит символическую ссылку — это запрещено.');
                }
                $compressed += (int) ($stat['comp_size'] ?? 0);
                if ($compressed > $maxTotal) {
                    $zip->close();
                    throw new RuntimeException('Слишком большой сжатый архив.');
                }
                $rel = self::normaliseRelative($entry['name']);
                if (!Security::allowedExtension($rel)) { $zip->close(); throw new RuntimeException('В архиве есть запрещённый тип файла: ' . $rel); }
                $stream = $zip->getStream($entry['source']);
                if ($stream === false) continue;
                $tmpFile = tempnam(sys_get_temp_dir(), 'pyz_');
                $fh = fopen($tmpFile, 'wb');
                while (!feof($stream)) {
                    $chunk = fread($stream, 8192);
                    $total += strlen($chunk);
                    if ($total > $maxTotal) { fclose($fh); fclose($stream); $zip->close(); throw new RuntimeException('Архив слишком большой.'); }
                    fwrite($fh, $chunk);
                }
                fclose($fh);
                fclose($stream);
                $out[] = ['rel' => $rel, 'tmp' => $tmpFile, 'temporary' => true];
            }
            $zip->close();
            return $out;
        }

        // Fallback for hosts without ext-zip.
        if (!Env::commandExists('unzip')) {
            throw new RuntimeException('На хостинге нет ZipArchive и unzip. Загрузите файлы по отдельности.');
        }
        $dest = sys_get_temp_dir() . '/pyh_unzip_' . Security::randomToken(8);
        mkdir($dest, 0700, true);
        $res = Process::execute(['unzip', '-qq', '-o', $tmp, '-d', $dest], null, [], 60, 4096);
        if ($res['code'] !== 0) throw new RuntimeException('Не удалось распаковать архив: ' . $res['out']);
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->isLink()) continue;
            $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dest))), '/');
            if (!Security::allowedExtension($rel)) continue;
            $out[] = ['rel' => $rel, 'tmp' => $f->getPathname()];
            if (count($out) > $maxFiles) break;
        }
        return $out;
    }

    private static function stripCommonRoot(array $entries): array
    {
        $roots = [];
        foreach ($entries as $e) {
            $parts = explode('/', str_replace('\\', '/', $e['name']));
            if (count($parts) < 2) return $entries;
            $roots[$parts[0]] = true;
        }
        if (count($roots) !== 1) return $entries;
        $root = array_key_first($roots);
        foreach ($entries as &$e) {
            $e['name'] = preg_replace('#^' . preg_quote($root, '#') . '/#', '', str_replace('\\', '/', $e['name']));
        }
        return $entries;
    }
}
