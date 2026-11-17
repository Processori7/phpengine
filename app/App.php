<?php
declare(strict_types=1);

/** Front controller + route handlers. */
final class App
{
    public static function boot(): void
    {
        date_default_timezone_set((string) Config::get('timezone', 'UTC'));
        Config::load();
        // A leftover ./runtime directory shadows the /runtime route on hosts that
        // resolve directories before rewrite rules, so move it out of the web root.
        if (is_dir((string) Config::get('root') . '/runtime')) {
            RuntimeManager::migrateLegacy();
        }
        Database::init();
        Config::applySettings(Database::settings());
        Security::setHeaders();
        Auth::boot();

        if (!Security::ipAllowed()) {
            http_response_code(403);
            exit('Доступ с вашего IP запрещён.');
        }
        if (!Security::userAgentAllowed()) {
            http_response_code(403);
            exit('Запрос без допустимого User-Agent отклонён.');
        }

        // Debugging cannot be enabled over HTTP: these parameters are ignored and logged.
        if (isset($_GET['debug']) || isset($_POST['debug']) || isset($_COOKIE['debug']) || isset($_GET['phpinfo'])) {
            Security::audit('debug_attempt', 'remote debug/phpinfo parameter');
            Telegram::notify('security', '⚠️ Попытка включить отладку/phpinfo удалённо (IP ' . Security::clientIp() . ')');
        }
        unset($_GET['debug'], $_POST['debug'], $_COOKIE['debug'], $_GET['phpinfo']);
        if (PHP_SAPI !== 'cli-server' && PHP_SAPI !== 'cli') {
            // Static assets are served directly by the web server. Public project
            // sites and CGI apps get their own budgets, so the panel limiter must
            // not throttle them (one page load is dozens of requests).
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $ownBudget = str_starts_with($path, '/assets/')
                || str_starts_with($path, '/site/')
                || str_starts_with($path, '/cgi/');
            if (!$ownBudget) {
                $rpm = max(1, (int) Config::get('security.global_rpm', 120));
                if (!Security::rateLimit('global:' . Security::clientIp(), $rpm, 60)) {
                    http_response_code(429);
                    exit('Слишком много запросов. Повторите через минуту.');
                }
            }
        }
    }

    public static function run(): void
    {
        $path = self::path();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $login = self::loginPath();
        $users = (int) Database::scalar('SELECT COUNT(*) FROM users');
        if ($users === 0 && $path !== '/install') {
            self::redirect('/install');
        }
        if ($users > 0 && $path === '/install') {
            self::redirect($login);
        }

        if ($path === '/install') {
            if ($method === 'POST') self::installPost();
            else self::view('install', ['title' => 'Первый запуск']);
            return;
        }
        if ($path === '/login' && $login !== '/login') {
            self::redirect($login);
        }
        if ($path === $login) {
            if ($method === 'POST') self::loginPost();
            else {
                $captchaOn = (bool) Config::get('security.login_captcha', true);
                $captcha = $captchaOn ? Captcha::current() : null;
                if ($captchaOn && $captcha === null) $captcha = Captcha::issue();
                $twofa = Auth::twoFactorPending();
                self::view('login', [
                    'title' => $twofa ? 'Двухфакторная аутентификация' : 'Вход',
                    'twofa' => $twofa,
                    'captcha' => $captcha,
                ]);
            }
            return;
        }
        if ($path === '/health') {
            // Public liveness probe only. No versions, no paths, no build info.
            self::json(['ok' => true]);
            return;
        }

        // Public surface of a project — served before the auth gate on purpose.
        $raw = self::rawPath();
        if (preg_match('#^/site/([A-Za-z0-9_-]{1,64})(/.*)?$#', $raw, $m)) {
            WebApp::serveStatic($m[1], $m[2] ?? '');
            return;
        }
        if (preg_match('#^/cgi/([A-Za-z0-9_-]{1,64})(/.*)?$#', $raw, $m)) {
            WebApp::runCgi($m[1], $m[2] ?? '/');
            return;
        }

        Auth::requireUser();

        if ($path === '/logout' && $method === 'POST') {
            Security::requireCsrf();
            Auth::logout();
            self::redirect(self::loginPath());
        }

        if ($path === '/') {
            self::dashboard();
            return;
        }
        if ($path === '/projects' && $method === 'GET') {
            self::view('projects', ['title' => 'Проекты', 'projects' => ProjectManager::all()]);
            return;
        }
        if ($path === '/projects/upload' && $method === 'POST') {
            self::uploadPost();
            return;
        }
        if ($path === '/runs' && $method === 'GET') {
            $runs = Database::all(
                'SELECT r.*, p.name AS project_name FROM runs r LEFT JOIN projects p ON p.id = r.project_id
                 ORDER BY r.id DESC LIMIT 200'
            );
            self::view('runs', ['title' => 'История запусков', 'runs' => $runs]);
            return;
        }
        if ($path === '/settings') {
            if ($method === 'POST') self::settingsPost();
            else self::settingsPage();
            return;
        }
        if ($path === '/runtime') {
            if ($method === 'POST') self::runtimePost();
            else self::runtimePage();
            return;
        }
        if (preg_match('#^/projects/(\d+)/browser$#', $path, $m) && $method === 'GET') {
            self::browserPythonPage((int) $m[1]);
            return;
        }
        if (preg_match('#^/projects/(\d+)/requirements$#', $path, $m) && $method === 'POST') {
            self::saveRequirements((int) $m[1]);
            return;
        }
        if (preg_match('#^/projects/(\d+)/source$#', $path, $m) && $method === 'GET') {
            self::projectSource((int) $m[1]);
            return;
        }
        if ($path === '/security' && $method === 'GET') {
            self::view('security', [
                'title' => 'Диагностика и безопасность',
                'checks' => Env::diagnostics(),
                'audit' => Database::all('SELECT * FROM audit ORDER BY id DESC LIMIT 100'),
            ]);
            return;
        }

        if (preg_match('#^/projects/(\d+)$#', $path, $m)) {
            self::projectPage((int) $m[1]);
            return;
        }
        if (preg_match('#^/projects/(\d+)/(delete|file|file-delete|run|venv-create|venv-install|venv-package|venv-remove|set-entry)$#', $path, $m) && $method === 'POST') {
            Security::requireCsrf();
            $project = ProjectManager::find((int) $m[1]);
            if ($project === null) self::notFound();
            match ($m[2]) {
                'delete'        => self::deleteProject($project),
                'file'          => self::saveFile($project),
                'file-delete'   => self::deleteFile($project),
                'run'           => self::runPost($project),
                'venv-create'   => self::venvCreate($project),
                'venv-install'  => self::venvInstall($project),
                'venv-package'  => self::venvPackage($project),
                'venv-remove'   => self::venvRemove($project),
                'set-entry'     => self::setEntrypoint($project),
            };
            return;
        }
        if (preg_match('#^/runs/(\d+)$#', $path, $m)) {
            $run = Database::one('SELECT r.*, p.name AS project_name FROM runs r LEFT JOIN projects p ON p.id=r.project_id WHERE r.id = ?', [(int) $m[1]]);
            if ($run === null) self::notFound();
            self::view('run', ['title' => 'Лог запуска #' . $run['id'], 'run' => $run, 'log' => self::readLog($run)]);
            return;
        }
        if (preg_match('#^/runs/(\d+)/log$#', $path, $m) && $method === 'GET') {
            $run = Database::one('SELECT * FROM runs WHERE id = ?', [(int) $m[1]]);
            if ($run === null) self::json(['error' => 'not found'], 404);
            self::json([
                'status'     => $run['status'],
                'exit_code'  => $run['exit_code'],
                'log'        => self::readLog($run),
                'finished'   => $run['finished_at'] !== null,
            ]);
            return;
        }

        self::notFound();
    }

    /* ---------- Pages ---------- */

    private static function dashboard(): void
    {
        $stats = [
            'projects' => (int) Database::scalar('SELECT COUNT(*) FROM projects'),
            'runs'     => (int) Database::scalar('SELECT COUNT(*) FROM runs'),
            'ok'       => (int) Database::scalar("SELECT COUNT(*) FROM runs WHERE status = 'success'"),
            'venvs'    => (int) Database::scalar("SELECT COUNT(*) FROM venvs WHERE status = 'ready'"),
        ];
        $recent = Database::all(
            'SELECT r.*, p.name AS project_name FROM runs r LEFT JOIN projects p ON p.id = r.project_id ORDER BY r.id DESC LIMIT 8'
        );
        self::view('dashboard', ['title' => 'Панель управления', 'stats' => $stats, 'recent' => $recent]);
    }

    private static function projectPage(int $id): void
    {
        $project = ProjectManager::find($id);
        if ($project === null) self::notFound();
        $runId = isset($_GET['run']) ? (int) $_GET['run'] : 0;
        $run = $runId > 0
            ? Database::one('SELECT * FROM runs WHERE id = ? AND project_id = ?', [$runId, $id])
            : Database::one('SELECT * FROM runs WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$id]);
        self::view('project', [
            'title'    => 'Проект: ' . $project['name'],
            'project'  => $project,
            'files'    => ProjectManager::files($project),
            'venv'     => VenvManager::forProject($project),
            'venvReady'=> VenvManager::exists($project),
            'venvLog'  => self::tail((string) Config::get('logs') . '/venv_' . $project['slug'] . '.log', 12000),
            'pipLog'   => self::tail((string) Config::get('logs') . '/pip_' . $project['slug'] . '.log', 12000),
            'packages' => VenvManager::exists($project) ? VenvManager::packages($project) : '',
            'run'      => $run,
            'log'      => $run ? self::readLog($run) : '',
            'runs'     => Database::all('SELECT * FROM runs WHERE project_id = ? ORDER BY id DESC LIMIT 20', [$id]),
            'python'   => Env::python(),
        ]);
    }

    /**
     * Browser-side runner: Pyodide is CPython compiled to WebAssembly, so the code
     * executes inside the visitor's browser. It needs no process on the host, which
     * makes it the only way to run Python on hosts that disable proc_open/exec.
     */
    private static function browserPythonPage(int $id): void
    {
        $project = ProjectManager::find($id);
        if ($project === null) self::notFound();
        if (!Config::get('security.browser_python', true)) {
            self::flash('error', 'Браузерный запуск отключён в настройках.');
            self::redirect('/projects/' . $id);
        }
        $entry = (string) $project['entrypoint'];
        // Telegram Bot API sends CORS headers, so bots work from the browser as
        // soon as the CSP allows the origin. Other hosts stay opt-in.
        $connect = self::pyodideConnectHosts();
        $connect[] = 'https://api.telegram.org';
        Security::cspAllowCdn((string) Config::get('security.pyodide_cdn'), $connect);
        self::view('browser-python', [
            'title'   => 'Браузер: ' . $project['name'],
            'project' => $project,
            'entry'   => $entry,
            'code'    => $entry !== '' ? (ProjectManager::read($project, $entry) ?? '') : '',
            'indexUrl' => self::pyodideIndexUrl(),
            'botExample' => self::botExample($entry !== '' ? $entry : 'main.py'),
            // Static files are served by the web server, so bust the cache by mtime.
            'scripts' => [
                '/assets/js/browser-python.js?v=' . (string) @filemtime((string) Config::get('public') . '/assets/js/browser-python.js'),
            ],
        ]);
    }

    private static function botExample(string $entry): string
    {
        return <<<PY
# $entry — эхо-бот на getUpdates (работает в браузерном режиме)
import time
import pyhost_bot as tg

offset = None
print("bot started")
while True:
    try:
        updates = tg.get_updates(offset=offset, timeout=20)
    except Exception as exc:
        print("telegram error:", exc)
        time.sleep(3)
        continue
    for update in updates:
        offset = update["update_id"] + 1
        message = update.get("message") or {}
        text = message.get("text")
        chat = (message.get("chat") or {}).get("id")
        if text and chat:
            tg.send_message(chat, "echo: " + text)
PY;
    }

    private static function pyodideIndexUrl(): string
    {
        return rtrim((string) Config::get('security.pyodide_cdn'), '/')
            . '/v' . (string) Config::get('security.pyodide_version') . '/full/';
    }

    /** @return string[] extra origins allowed in connect-src for the browser runtime */
    private static function pyodideConnectHosts(): array
    {
        $raw = (string) Config::get('security.pyodide_connect_hosts', '');
        if ($raw === '') return [];
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw) ?: [])));
    }

    /** Text-only source bundle for the browser runner (the VM lives in the browser). */
    private static function projectSource(int $id): void
    {
        $project = ProjectManager::find($id);
        if ($project === null) self::json(['error' => 'not found'], 404);
        if (!Config::get('security.browser_python', true)) self::json(['error' => 'disabled'], 403);
        $allowed = ['py', 'pyw', 'txt', 'md', 'json', 'jsonl', 'csv', 'tsv', 'xml', 'yaml', 'yml', 'toml', 'ini', 'cfg', 'conf', 'sql', 'html', 'htm', 'css', 'js'];
        $files = [];
        $total = 0;
        $skipped = 0;
        foreach (ProjectManager::files($project) as $file) {
            $rel = (string) $file['path'];
            if (!in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), $allowed, true)
                || (int) $file['size'] > 524288
                || $total > 4194304) {
                $skipped++;
                continue;
            }
            $content = ProjectManager::read($project, $rel);
            if ($content === null) {
                $skipped++;
                continue;
            }
            $total += strlen($content);
            $files[] = ['path' => $rel, 'content' => $content];
        }
        self::json([
            'entry'   => (string) $project['entrypoint'],
            'files'   => $files,
            'skipped' => $skipped,
            'bytes'   => $total,
        ]);
    }

    /** Stores requirements.txt edited in the browser runner (JSON, CSRF-protected). */
    private static function saveRequirements(int $id): void
    {
        Security::requireCsrf();
        $project = ProjectManager::find($id);
        if ($project === null) self::json(['error' => 'not found'], 404);
        $body = (string) ($_POST['requirements'] ?? '');
        if (strlen($body) > 65536) self::json(['error' => 'too large'], 413);
        if (!ProjectManager::write($project, 'requirements.txt', $body)) {
            self::json(['error' => 'write failed'], 500);
        }
        Database::run('UPDATE projects SET updated_at = ? WHERE id = ?', [time(), $project['id']]);
        Security::audit('file_saved', $project['slug'] . '/requirements.txt');
        self::json(['ok' => true, 'bytes' => strlen($body)]);
    }

    private static function settingsPage(): void
    {
        $user = Auth::user();
        $secret = (string) ($_SESSION['twofa_setup_secret'] ?? '');
        self::view('settings', [
            'title' => 'Настройки',
            'twofaSecret' => $secret,
            'twofaUri' => $secret !== '' ? TOTP::provisioningUri($secret, 'PyHostEngine:' . ($user['username'] ?? 'admin')) : '',
            'twofaEnabled' => !empty($user['totp_enabled']),
            'db' => Database::connectionForForm(),
        ]);
    }

    private static function runtimePage(): void
    {
        self::view('runtime', [
            'title'  => 'Среда выполнения',
            'status' => RuntimeManager::status(),
            'python' => Env::python(),
        ]);
    }

    /* ---------- Handlers ---------- */

    private static function runtimePost(): void
    {
        Security::requireCsrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'remove') {
            RuntimeManager::remove();
            self::flash('success', 'Встроенный Python удалён.');
            self::redirect('/runtime');
        }
        if ($action === 'upload') {
            $error = (int) ($_FILES['runtime_archive']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                self::flash('error', 'Выберите архив .tar.gz с официальной сборкой Python.');
                self::redirect('/runtime');
            }
            $res = RuntimeManager::installFromUpload((string) $_FILES['runtime_archive']['tmp_name']);
            self::flash($res['ok'] ? 'success' : 'error', $res['message']);
            self::redirect('/runtime');
        }

        $res = RuntimeManager::install();
        self::flash($res['ok'] ? 'success' : 'error', $res['message']);
        self::redirect('/runtime');
    }

    private static function installPost(): void
    {
        Security::requireCsrf();
        try {
            $username = (string) ($_POST['username'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $confirm  = (string) ($_POST['confirm'] ?? '');
            if ($password !== $confirm) throw new InvalidArgumentException('Пароли не совпадают.');
            Auth::createUser($username, $password);
            Security::audit('installed');
            self::flash('success', 'Администратор создан. Войдите в систему.');
            self::redirect(self::loginPath());
        } catch (Throwable $e) {
            self::flash('error', $e->getMessage());
            self::redirect('/install');
        }
    }

    private static function loginPost(): void
    {
        Security::requireCsrf();
        $username = (string) ($_POST['username'] ?? '');

        if (Config::get('security.login_captcha', true) && ($_POST['action'] ?? '') !== 'twofa') {
            if (!Captcha::valid($_POST['captcha'] ?? null)) {
                $msg = Auth::noteFailedAttempt($username);
                Captcha::clear();
                self::flash('error', 'Неверный или просроченный ответ на капчу. ' . $msg);
                self::redirect(self::loginPath());
            }
            Captcha::clear();
        }

        if (($_POST['action'] ?? '') === 'twofa') {
            $res = Auth::verifyTwoFactor((string) ($_POST['code'] ?? ''));
            if (!$res['ok']) {
                self::flash('error', $res['message']);
                self::redirect(self::loginPath());
            }
            self::flash('success', $res['message']);
            self::redirect('/');
        }

        $result = Auth::attempt(
            $username,
            (string) ($_POST['password'] ?? ''),
            isset($_POST['remember'])
        );
        if (!$result['ok']) {
            if (!empty($result['twofa'])) {
                self::view('login', ['title' => 'Двухфакторная аутентификация', 'twofa' => true]);
                return;
            }
            self::flash('error', $result['message']);
            self::redirect(self::loginPath());
        }
        self::flash('success', $result['message']);
        self::redirect('/');
    }

    private static function uploadPost(): void
    {
        Security::requireCsrf();
        self::requireExecutionAccess();
        try {
            $uploads = ProjectManager::collectUploads();
            $project = ProjectManager::create(
                (string) ($_POST['name'] ?? ''),
                $uploads,
                (string) ($_POST['entrypoint'] ?? '')
            );
            self::flash('success', "Проект «{$project['name']}» загружен (" . count($uploads) . " файлов).");
            Telegram::notify('project', '📦 Новый проект: <b>' . Security::e($project['name']) . '</b> (' . count($uploads) . ' файлов)');
            self::redirect('/projects/' . $project['id']);
        } catch (Throwable $e) {
            self::flash('error', $e->getMessage());
            self::redirect('/projects');
        }
    }

    private static function deleteProject(array $project): void
    {
        ProjectManager::remove((int) $project['id']);
        self::flash('success', 'Проект удалён.');
        self::redirect('/projects');
    }

    private static function saveFile(array $project): void
    {
        $rel = (string) ($_POST['path'] ?? '');
        $body = (string) ($_POST['body'] ?? '');
        if (!ProjectManager::write($project, $rel, $body)) {
            self::flash('error', 'Не удалось сохранить файл. Проверьте имя и путь.');
        } else {
            Database::run('UPDATE projects SET updated_at = ? WHERE id = ?', [time(), $project['id']]);
            Security::audit('file_saved', $project['slug'] . '/' . $rel);
            self::flash('success', 'Файл сохранён.');
        }
        self::redirect('/projects/' . $project['id']);
    }

    private static function deleteFile(array $project): void
    {
        $rel = (string) ($_POST['path'] ?? '');
        if (ProjectManager::removeFile($project, $rel)) {
            Security::audit('file_deleted', $project['slug'] . '/' . $rel);
            self::flash('success', 'Файл удалён.');
        } else {
            self::flash('error', 'Файл не найден.');
        }
        self::redirect('/projects/' . $project['id']);
    }

    private static function setEntrypoint(array $project): void
    {
        $rel = ProjectManager::normaliseRelative((string) ($_POST['entrypoint'] ?? ''));
        if (!is_file($project['dir'] . '/' . $rel)) {
            self::flash('error', 'Файл точки входа не найден.');
        } else {
            Database::update('projects', ['entrypoint' => $rel, 'updated_at' => time()], 'id = ?', [$project['id']]);
            self::flash('success', 'Точка входа обновлена.');
        }
        self::redirect('/projects/' . $project['id']);
    }

    private static function venvCreate(array $project): void
    {
        self::requireExecutionAccess();
        $res = VenvManager::create($project);
        self::flash($res['ok'] ? 'success' : 'error', $res['message']);
        self::redirect('/projects/' . $project['id']);
    }

    private static function venvInstall(array $project): void
    {
        self::requireExecutionAccess();
        $res = VenvManager::installRequirements($project);
        self::flash($res['ok'] ? 'success' : 'error', $res['message']);
        self::redirect('/projects/' . $project['id']);
    }

    private static function venvPackage(array $project): void
    {
        self::requireExecutionAccess();
        $res = VenvManager::installPackage($project, (string) ($_POST['package'] ?? ''));
        self::flash($res['ok'] ? 'success' : 'error', $res['message']);
        self::redirect('/projects/' . $project['id']);
    }

    private static function venvRemove(array $project): void
    {
        VenvManager::remove($project);
        self::flash('success', 'Виртуальное окружение удалено.');
        self::redirect('/projects/' . $project['id']);
    }

    private static function runPost(array $project): void
    {
        self::requireExecutionAccess();
        $rpm = max(1, (int) Config::get('security.run_rpm', 20));
        if (!Security::rateLimit('run:' . (int) $_SESSION['user_id'], $rpm, 60)) {
            self::flash('error', 'Лимит запусков исчерпан. Подождите минуту.');
            self::redirect('/projects/' . $project['id']);
        }

        $command = trim((string) ($_POST['command'] ?? ''));
        if ($command === '') {
            $command = 'python ' . $project['entrypoint'] . ' ' . trim((string) $project['args']);
        }
        $python = Env::python();
        if ($python === null) {
            self::flash('error', 'Python не найден. Проверьте диагностику.');
            self::redirect('/projects/' . $project['id']);
        }

        try {
            $venvReady = VenvManager::exists($project) && $project['interpreter'] === 'venv';
            $tokens = Runner::buildCommand($command, $venvReady ? VenvManager::path($project) : null, $python['cmd']);
        } catch (Throwable $e) {
            self::flash('error', $e->getMessage());
            self::redirect('/projects/' . $project['id']);
        }

        $logFile = (string) Config::get('logs') . '/run_' . date('Ymd_His') . '_' . Security::randomToken(6) . '.log';
        if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0775, true);
        $runId = Database::insert('runs', [
            'project_id'  => $project['id'],
            'user_id'     => $_SESSION['user_id'] ?? null,
            'command'     => $command,
            'cwd'         => $project['dir'],
            'status'      => 'running',
            'output_file' => $logFile,
            'started_at'  => time(),
        ]);

        $timeout = max(5, min(3600, (int) Config::get('security.run_timeout', 120)));
        $result = Runner::execute(
            $tokens,
            $project['dir'],
            $venvReady ? Runner::venvEnv(VenvManager::path($project)) : [],
            $timeout,
            $logFile
        );
        Database::update('runs', [
            'status'      => $result['status'],
            'exit_code'   => $result['exit_code'],
            'bytes'       => $result['bytes'],
            'finished_at' => time(),
        ], 'id = ?', [$runId]);
        Security::audit('run', $command . ' -> ' . $result['status']);
        Telegram::notify('run', '▶ Запуск #' . $runId . ' (' . $result['status'] . '): <code>'
            . Security::e(mb_strimwidth($command, 0, 180, '…')) . '</code>');

        Database::update('projects', ['updated_at' => time()], 'id = ?', [$project['id']]);
        self::flash($result['status'] === 'success' ? 'success' : 'error', 'Запуск #' . $runId . ': ' . $result['status']);
        self::redirect('/projects/' . $project['id'] . '?run=' . $runId . '#log');
    }

    private static function settingsPost(): void
    {
        Security::requireCsrf();

        if (!empty($_POST['db_config'])) {
            try {
                $cfg = self::dbConfigFromPost();
                $res = Database::testConnection($cfg);
                if (!$res['ok']) {
                    self::flash('error', 'Проверка подключения не прошла: ' . $res['message']);
                    self::redirect('/settings');
                }
                if (!empty($_POST['db_save'])) {
                    Database::saveConnection($cfg);
                    $detail = $cfg['driver'] === 'mysql'
                        ? 'mysql: ' . $cfg['host'] . '/' . $cfg['name']
                        : 'sqlite: ' . Database::connectionForForm()['path'];
                    Security::audit('db_config_updated', $detail);
                    self::flash(
                        'success',
                        'Настройки БД сохранены. Новое подключение начнёт работать со следующего запроса. '
                        . 'При смене драйвера данные не переносятся — создайте администратора заново.'
                    );
                } else {
                    self::flash('success', $res['message']);
                }
            } catch (Throwable $e) {
                self::flash('error', 'Ошибка настроек БД: ' . $e->getMessage());
            }
            self::redirect('/settings');
        }

        if (!empty($_POST['twofa_action'])) {
            $action = (string) $_POST['twofa_action'];
            try {
                if ($action === 'begin') {
                    $data = Auth::beginTwoFactor((string) ($_POST['twofa_password'] ?? ''));
                    $_SESSION['twofa_setup_secret'] = $data['secret'];
                    self::flash('success', 'Секрет сгенерирован. Добавьте его в аутентификатор и введите код ниже.');
                } elseif ($action === 'confirm') {
                    $res = Auth::confirmTwoFactor((string) ($_POST['twofa_code'] ?? ''));
                    if ($res['ok']) unset($_SESSION['twofa_setup_secret']);
                    self::flash($res['ok'] ? 'success' : 'error', $res['message']);
                } elseif ($action === 'disable') {
                    $res = Auth::disableTwoFactor(
                        (string) ($_POST['twofa_password'] ?? ''),
                        (string) ($_POST['twofa_code'] ?? '')
                    );
                    if ($res['ok']) unset($_SESSION['twofa_setup_secret']);
                    self::flash($res['ok'] ? 'success' : 'error', $res['message']);
                }
            } catch (Throwable $e) {
                self::flash('error', $e->getMessage());
            }
            self::redirect('/settings');
        }

        if (!empty($_POST['change_password'])) {
            $res = Auth::changePassword((string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''));
            self::flash($res['ok'] ? 'success' : 'error', $res['message']);
            self::redirect('/settings');
        }

        $bools = [
            'security.cookie_secure'     => isset($_POST['cookie_secure']),
            'security.execution_enabled' => isset($_POST['execution_enabled']),
            'security.login_captcha'     => isset($_POST['login_captcha']),
            'security.require_useragent' => isset($_POST['require_useragent']),
            'security.csrf_enabled'      => isset($_POST['csrf_enabled']),
            'security.cookie_prefix'     => isset($_POST['cookie_prefix']),
            'security.session_bind_ua'   => isset($_POST['session_bind_ua']),
            'security.session_bind_ip'   => isset($_POST['session_bind_ip']),
            'security.hide_server_header'=> isset($_POST['hide_server_header']),
            'security.site_enabled'      => isset($_POST['site_enabled']),
            'security.cgi_enabled'       => isset($_POST['cgi_enabled']),
        ];
        foreach ($bools as $key => $value) {
            Database::setSetting($key, $value);
            Config::setOverride($key, $value);
        }

        $ints = [
            'security.run_timeout'        => [5, 3600],
            'security.max_output'         => [65536, 52428800],
            'security.login_max_attempts' => [3, 20],
            'security.global_rpm'         => [10, 1000],
            'security.run_rpm'            => [1, 200],
            'security.remember_days'      => [1, 90],
            'security.session_idle'       => [300, 86400],
            'security.session_rotate'     => [300, 86400],
            'security.login_lockout'      => [60, 86400],
            'security.csrf_ttl'           => [60, 86400],
            'security.cgi_rpm'            => [1, 600],
        ];
        foreach ($ints as $key => [$min, $max]) {
            $value = (int) ($_POST[str_replace('.', '_', $key)] ?? Config::get($key));
            $value = max($min, min($max, $value));
            Database::setSetting($key, $value);
            Config::setOverride($key, $value);
        }

        $loginPath = strtolower(trim((string) ($_POST['login_path'] ?? ''), '/'));
        $reserved = ['', 'install', 'logout', 'projects', 'runs', 'settings', 'security', 'runtime', 'assets', 'health'];
        if (preg_match('/^[a-z0-9_-]{1,40}$/', $loginPath) && !in_array($loginPath, $reserved, true)) {
            Database::setSetting('security.login_path', $loginPath);
            Config::setOverride('security.login_path', $loginPath);
        } else {
            self::flash('error', 'Неверный адрес входа: используйте латиницу, цифры, - или _ (не «install», «logout» и т.п.).');
        }

        $samesite = (string) ($_POST['cookie_samesite'] ?? 'Lax');
        $samesite = in_array($samesite, ['Lax', 'Strict'], true) ? $samesite : 'Lax';
        Database::setSetting('security.cookie_samesite', $samesite);
        Config::setOverride('security.cookie_samesite', $samesite);

        $rotation = (string) ($_POST['csrf_rotation'] ?? 'session');
        $rotation = in_array($rotation, ['session', 'request'], true) ? $rotation : 'session';
        Database::setSetting('security.csrf_rotation', $rotation);
        Config::setOverride('security.csrf_rotation', $rotation);
        Security::rotateCsrf();

        $blocked = [];
        foreach (array_map('trim', explode(',', (string) ($_POST['blocked_useragents'] ?? ''))) as $needle) {
            if ($needle !== '' && strlen($needle) <= 60) $blocked[] = $needle;
            if (count($blocked) >= 10) break;
        }
        $blockedUa = implode(', ', $blocked);
        Database::setSetting('security.blocked_useragents', $blockedUa);
        Config::setOverride('security.blocked_useragents', $blockedUa);

        $tgEnabled = isset($_POST['tg_enabled']);
        $tgToken   = trim((string) ($_POST['tg_token'] ?? ''));
        $tgChat    = trim((string) ($_POST['tg_chat'] ?? ''));
        if ($tgToken === '') $tgToken = (string) Config::get('telegram.bot_token', '');
        if ($tgChat === '')  $tgChat  = (string) Config::get('telegram.chat_id', '');
        $tgDoh     = in_array((string) ($_POST['tg_doh'] ?? 'cloudflare'), ['cloudflare', 'google', 'direct'], true)
            ? (string) $_POST['tg_doh'] : 'cloudflare';
        $eventsInput = $_POST['tg_events'] ?? '';
        if (is_array($eventsInput)) $eventsInput = implode(',', $eventsInput);
        $tgEvents  = array_values(array_intersect(
            array_map('trim', explode(',', (string) $eventsInput)),
            ['login', 'failed_login', 'twofa', 'run', 'project', 'venv', 'runtime', 'security']
        ));
        foreach ([
            'telegram.enabled' => $tgEnabled,
            'telegram.bot_token' => $tgToken,
            'telegram.chat_id' => $tgChat,
            'telegram.doh' => $tgDoh,
            'telegram.events' => implode(',', $tgEvents),
        ] as $key => $value) {
            Database::setSetting($key, $value);
            Config::setOverride($key, $value);
        }
        if (!empty($_POST['tg_test'])) {
            $res = Telegram::test();
            self::flash($res['ok'] ? 'success' : 'error', 'Тест Telegram: ' . $res['message']);
        }

        $appName = trim(mb_substr((string) ($_POST['app_name'] ?? ''), 0, 60));
        if ($appName !== '') {
            Database::setSetting('app_name', $appName);
            Config::setOverride('app_name', $appName);
        }
        $bin = trim(mb_substr((string) ($_POST['python_binary'] ?? ''), 0, 500));
        if (preg_match('/[;&|`$<>]/', $bin)) $bin = '';
        Database::setSetting('security.python_binary', $bin);
        Config::setOverride('security.python_binary', $bin);

        // Origins the browser runtime may call; validated again in Security::cspAllowCdn().
        $connectHosts = trim(mb_substr((string) ($_POST['pyodide_connect_hosts'] ?? ''), 0, 500));
        Database::setSetting('security.pyodide_connect_hosts', $connectHosts);
        Config::setOverride('security.pyodide_connect_hosts', $connectHosts);

        $whitelist = trim((string) ($_POST['ip_whitelist'] ?? ''));
        $parts = [];
        foreach (array_map('trim', explode(',', $whitelist)) as $entry) {
            if ($entry === '') continue;
            if ($entry === '*' || filter_var($entry, FILTER_VALIDATE_IP)) {
                $parts[] = $entry;
            } elseif (preg_match('#^(\d{1,3}\.){3}\d{1,3}/\d{1,2}$#', $entry)) {
                $parts[] = $entry;
            }
        }
        $whitelist = implode(', ', $parts);
        Database::setSetting('security.ip_whitelist', $whitelist);
        Config::setOverride('security.ip_whitelist', $whitelist);

        Security::audit('settings_updated');
        self::flash('success', 'Настройки сохранены.');
        self::redirect('/settings');
    }

    /* ---------- Utilities ---------- */

    private static function dbConfigFromPost(): array
    {
        $current = Database::connection();
        $driver = (string) ($_POST['db_driver'] ?? $current['driver']);
        $driver = in_array($driver, ['sqlite', 'mysql'], true) ? $driver : 'sqlite';
        $pass = (string) ($_POST['db_pass'] ?? '');
        if ($pass === '') $pass = (string) ($current['pass'] ?? '');
        $charset = (string) ($_POST['db_charset'] ?? $current['charset']);
        return [
            'driver'  => $driver,
            'path'    => trim((string) ($_POST['db_sqlite_path'] ?? $current['path'])),
            'host'    => trim((string) ($_POST['db_host'] ?? $current['host'])),
            'port'    => (int) ($_POST['db_port'] ?? $current['port']),
            'name'    => trim((string) ($_POST['db_name'] ?? $current['name'])),
            'user'    => trim((string) ($_POST['db_user'] ?? $current['user'])),
            'pass'    => $pass,
            'charset' => $charset,
        ];
    }

    private static function requireExecutionAccess(): void
    {
        if (!Config::get('security.execution_enabled', true)) {
            self::flash('error', 'Выполнение кода отключено администратором.');
            self::redirect('/');
        }
    }

    public static function readLog(array $run): string
    {
        $path = $run['output_file'] ?? '';
        $base = realpath((string) Config::get('logs'));
        $real = realpath($path);
        if ($base === false || $real === false || !Security::isInside($base, $real)) {
            return '[log not found]';
        }
        return self::tail($real, (int) Config::get('security.max_output', 8388608));
    }

    private static function tail(string $path, int $maxBytes): string
    {
        if (!is_file($path)) return '';
        $size = filesize($path) ?: 0;
        $fh = fopen($path, 'rb');
        if ($fh === false) return '';
        if ($size > $maxBytes) fseek($fh, $size - $maxBytes);
        $content = stream_get_contents($fh);
        fclose($fh);
        return $content === false ? '' : $content;
    }

    public static function loginPath(): string
    {
        $raw = trim((string) Config::get('security.login_path', 'engine-login'), '/');
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $raw)) {
            $raw = 'engine-login';
        }
        return '/' . $raw;
    }

    private static function path(): string
    {
        return '/' . trim(self::rawPath(), '/');
    }

    /** Same as path() but keeps the trailing slash — project sites need it. */
    private static function rawPath(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($base !== '' && $base !== '.' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        return '/' . ltrim($uri, '/');
    }

    public static function view(string $name, array $vars = []): void
    {
        $vars['flash'] = self::takeFlash();
        $vars['csrf'] = Security::csrfToken();
        $vars['user'] = Auth::user();
        $vars['appName'] = (string) Config::get('app_name', 'PyHost Engine');
        $vars['loginPath'] = self::loginPath();
        extract($vars, EXTR_SKIP);
        $viewFile = dirname(__DIR__) . '/views/' . $name . '.php';
        if (!is_file($viewFile)) throw new RuntimeException('View not found: ' . $name);
        ob_start();
        include $viewFile;
        $content = ob_get_clean();
        include dirname(__DIR__) . '/views/layout.php';
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function takeFlash(): array
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return is_array($flash) ? $flash : [];
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }

    public static function json(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function notFound(): never
    {
        http_response_code(404);
        self::view('error', ['title' => '404', 'message' => 'Страница или проект не найден.']);
        exit;
    }
}
