<?php
declare(strict_types=1);

/** Sessions, password auth, remember-me tokens and brute-force protection. */
final class Auth
{
    private static ?array $user = null;
    private const REMEMBER_COOKIE = 'pyhost_remember';
    private const DUMMY_HASH = '$2y$12$KAjz/whMhMUHzaymxMi9V.efQ7bzHkCLuCGO6yr3/qf4HCnNV7Dci';

    public static function boot(): void
    {
        $secure = self::httpsDetected() || (bool) Config::get('security.cookie_secure', false);
        $prefix = $secure && Config::get('security.cookie_prefix', true) ? '__Host-' : '';
        session_name($prefix . (string) Config::get('security.session_name', 'PYHOSTSESSID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => (string) Config::get('security.cookie_samesite', 'Lax'),
        ]);
        $path = (string) Config::get('sessions');
        if (!is_dir($path)) mkdir($path, 0700, true);
        @chmod($path, 0700);
        session_save_path($path);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) (max(300, (int) Config::get('security.session_idle', 7200)) * 2));
        session_start();

        if (!empty($_SESSION['user_id'])) {
            $idle = max(300, (int) Config::get('security.session_idle', 7200));
            if (time() - (int) ($_SESSION['auth_at'] ?? 0) > $idle) {
                Security::audit('session_expired');
                self::clearSession();
                return;
            }
            if (!self::checkFingerprint()) return;
            $rotate = max(300, (int) Config::get('security.session_rotate', 1800));
            if (time() - (int) ($_SESSION['rotated_at'] ?? ($_SESSION['auth_at'] ?? 0)) > $rotate) {
                session_regenerate_id(true);
                $_SESSION['rotated_at'] = time();
            }
        }

        if (empty($_SESSION['user_id']) && isset($_COOKIE[self::rememberName()])) {
            self::loginFromCookie();
        }
    }

    private static function rememberName(): string
    {
        $secure = self::httpsDetected() || (bool) Config::get('security.cookie_secure', false);
        return ($secure && Config::get('security.cookie_prefix', true) ? '__Host-' : '') . self::REMEMBER_COOKIE;
    }

    private static function httpsDetected(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /** UA (+ optional IP) binding makes a stolen cookie useless from another client. */
    private static function fingerprint(): string
    {
        $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $ip = Config::get('security.session_bind_ip', false) ? self::ipPrefix(Security::clientIp()) : '';
        return hash('sha256', $ua . '|' . $ip . '|' . (string) Config::get('security.session_name', 'PYHOSTSESSID'));
    }

    private static function ipPrefix(string $ip): string
    {
        if (str_contains($ip, ':')) return substr($ip, 0, 12);
        $parts = explode('.', $ip);
        return implode('.', array_slice($parts, 0, 3));
    }

    private static function uaHash(): string
    {
        return hash('sha256', mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
    }

    private static function checkFingerprint(): bool
    {
        if (!Config::get('security.session_bind_ua', true) && !Config::get('security.session_bind_ip', false)) return true;
        $fp = self::fingerprint();
        if (empty($_SESSION['_fp'])) {
            $_SESSION['_fp'] = $fp;
            return true;
        }
        if (!hash_equals((string) $_SESSION['_fp'], $fp)) {
            Security::audit('session_hijack_suspected');
            Telegram::notify('security', '🚫 Обнаружена сессия с чужим отпечатком (UA/IP) — доступ прерван.');
            self::clearSession();
            return false;
        }
        return true;
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        self::$user = Database::one('SELECT * FROM users WHERE id = ?', [$_SESSION['user_id']]);
        if (self::$user === null) {
            self::clearSession();
        }
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireUser(): void
    {
        if (!self::check()) {
            App::flash('error', 'Требуется авторизация.');
            App::redirect(App::loginPath());
        }
    }

    /** @return array{ok: bool, message: string} */
    public static function attempt(string $username, string $password, bool $remember = false): array
    {
        $username = mb_substr(trim($username), 0, 100);
        $key = 'login:' . Security::clientIp() . ':' . mb_strtolower($username);
        $max = max(1, (int) Config::get('security.login_max_attempts', 5));

        if (!Security::rateLimit($key, $max * 3, 900)) {
            return ['ok' => false, 'message' => 'Слишком много попыток. Попробуйте позже.'];
        }

        $user = Database::one('SELECT * FROM users WHERE username = ?', [$username]);
        if ($user === null) {
            password_verify($password, self::DUMMY_HASH); // equalise timing
            return ['ok' => false, 'message' => 'Неверный логин или пароль.'];
        }

        if ((int) $user['locked_until'] > time()) {
            $left = (int) $user['locked_until'] - time();
            return ['ok' => false, 'message' => "Аккаунт заблокирован. Повторите через {$left} с."];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return ['ok' => false, 'message' => self::noteFailedAttempt($username)];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            Database::update('users', [
                'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            ], 'id = ?', [$user['id']]);
        }

        if (!empty($user['totp_enabled'])) {
            $_SESSION['pending_2fa'] = (int) $user['id'];
            $_SESSION['pending_2fa_at'] = time();
            $_SESSION['pending_2fa_remember'] = $remember;
            Security::audit('twofa_challenge', $username);
            return ['ok' => false, 'twofa' => true, 'message' => ''];
        }

        self::completeLogin($user, $remember);
        return ['ok' => true, 'message' => 'Добро пожаловать!'];
    }

    /** Second step of login when TOTP is enabled. */
    public static function verifyTwoFactor(string $code): array
    {
        $id = (int) ($_SESSION['pending_2fa'] ?? 0);
        if ($id === 0 || time() - (int) ($_SESSION['pending_2fa_at'] ?? 0) > 300) {
            unset($_SESSION['pending_2fa'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_remember']);
            return ['ok' => false, 'message' => 'Сессия подтверждения истекла. Войдите снова.'];
        }
        if (!Security::rateLimit('2fa:' . Security::clientIp(), 5, 300)) {
            return ['ok' => false, 'message' => 'Слишком много попыток. Подождите 5 минут.'];
        }
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($user === null || empty($user['totp_enabled']) || !TOTP::verify((string) $user['totp_secret'], $code)) {
            Security::audit('twofa_failed');
            return ['ok' => false, 'message' => 'Неверный код подтверждения.'];
        }
        $remember = (bool) ($_SESSION['pending_2fa_remember'] ?? false);
        unset($_SESSION['pending_2fa'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_remember']);
        self::completeLogin($user, $remember);
        Security::audit('twofa_success');
        Telegram::notify('twofa', '🔐 Подтверждён вход с 2FA: <b>' . Security::e($user['username']) . '</b> · IP ' . Security::clientIp());
        return ['ok' => true, 'message' => 'Добро пожаловать!'];
    }

    public static function twoFactorPending(): bool
    {
        return !empty($_SESSION['pending_2fa']) && !empty($_SESSION['pending_2fa_at']);
    }

    /** @return array{secret: string, uri: string} */
    public static function beginTwoFactor(string $password): array
    {
        $user = self::user();
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            throw new InvalidArgumentException('Текущий пароль неверен.');
        }
        $secret = TOTP::generateSecret();
        Database::update('users', ['totp_secret' => $secret, 'totp_enabled' => 0], 'id = ?', [$user['id']]);
        self::$user = null;
        Security::audit('twofa_setup_started');
        return [
            'secret' => $secret,
            'uri'    => TOTP::provisioningUri($secret, 'PyHostEngine:' . $user['username']),
        ];
    }

    public static function confirmTwoFactor(string $code): array
    {
        $user = self::user();
        if ($user === null || empty($user['totp_secret']) || !TOTP::verify((string) $user['totp_secret'], $code)) {
            return ['ok' => false, 'message' => 'Код не подошёл. Проверьте время на телефоне.'];
        }
        Database::update('users', ['totp_enabled' => 1], 'id = ?', [$user['id']]);
        self::$user = null;
        Security::audit('twofa_enabled');
        return ['ok' => true, 'message' => 'Двухфакторная аутентификация включена.'];
    }

    public static function disableTwoFactor(string $password, string $code): array
    {
        $user = self::user();
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            return ['ok' => false, 'message' => 'Пароль неверен.'];
        }
        if (empty($user['totp_enabled']) || !TOTP::verify((string) $user['totp_secret'], $code)) {
            return ['ok' => false, 'message' => 'Код подтверждения неверен.'];
        }
        Database::update('users', ['totp_enabled' => 0, 'totp_secret' => null], 'id = ?', [$user['id']]);
        self::$user = null;
        Security::audit('twofa_disabled');
        return ['ok' => true, 'message' => 'Двухфакторная аутентификация отключена.'];
    }

    private static function completeLogin(array $user, bool $remember): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['auth_at'] = time();
        $_SESSION['rotated_at'] = time();
        $_SESSION['_fp'] = self::fingerprint();
        Database::update('users', [
            'failed_attempts' => 0,
            'locked_until'    => 0,
            'last_login_at'   => time(),
        ], 'id = ?', [$user['id']]);
        Security::audit('login', $user['username']);
        Telegram::notify('login', '🔓 Вход в админку: <b>' . Security::e($user['username']) . '</b> · IP ' . Security::clientIp());
        self::$user = null;
        if ($remember) {
            self::issueRememberCookie((int) $user['id'], $user['salt']);
        }
    }

    /** Counts a bad password or a bad captcha toward the same 5-attempt lockout. */
    public static function noteFailedAttempt(string $username): string
    {
        $username = mb_substr(trim($username), 0, 100);
        $max = max(1, (int) Config::get('security.login_max_attempts', 5));
        $user = Database::one('SELECT id, failed_attempts, locked_until FROM users WHERE username = ?', [$username]);
        if ($user === null) {
            Security::audit('login_failed', $username ?: '(captcha)');
            return 'Неверный логин или пароль.';
        }
        $fails = (int) $user['failed_attempts'] + 1;
        $lockSeconds = max(60, (int) Config::get('security.login_lockout', 900));
        $lock = $fails >= $max ? time() + $lockSeconds : 0;
        Database::update('users', [
            'failed_attempts' => $lock ? 0 : $fails,
            'locked_until'    => $lock,
        ], 'id = ?', [$user['id']]);
        Security::audit('login_failed', $username);
        if ($lock) {
            Telegram::notify('security', '🔒 Аккаунт <b>' . Security::e($username) . '</b> заблокирован на '
                . round($lockSeconds / 60) . ' мин после ' . $max . ' неудачных попыток (IP ' . Security::clientIp() . ')');
            return 'Аккаунт заблокирован на ' . round($lockSeconds / 60) . ' минут. Попробуйте позже.';
        }
        return "Неверный логин или пароль. Осталось попыток: " . ($max - $fails);
    }

    public static function createUser(string $username, string $password): int
    {
        $username = trim($username);
        if (mb_strlen($username) < 3 || mb_strlen($username) > 64) {
            throw new InvalidArgumentException('Логин должен быть от 3 до 64 символов.');
        }
        if (mb_strlen($password) < 10) {
            throw new InvalidArgumentException('Пароль должен быть не короче 10 символов.');
        }
        if (Database::scalar('SELECT COUNT(*) FROM users WHERE username = ?', [$username])) {
            throw new InvalidArgumentException('Такой логин уже существует.');
        }
        return Database::insert('users', [
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'salt'          => Security::randomToken(32),
            'created_at'    => time(),
        ]);
    }

    public static function changePassword(string $current, string $new): array
    {
        $user = self::user();
        if ($user === null || !password_verify($current, $user['password_hash'])) {
            return ['ok' => false, 'message' => 'Текущий пароль неверен.'];
        }
        if (mb_strlen($new) < 10) {
            return ['ok' => false, 'message' => 'Новый пароль должен быть не короче 10 символов.'];
        }
        Database::update('users', [
            'password_hash' => password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]),
            'salt'          => Security::randomToken(32),
            'remember_hash' => null,
            'remember_ua'   => null,
        ], 'id = ?', [$user['id']]);
        setcookie(self::rememberName(), '', [
            'expires' => time() - 3600, 'path' => '/', 'secure' => self::httpsDetected() || (bool) Config::get('security.cookie_secure', false),
            'httponly' => true, 'samesite' => (string) Config::get('security.cookie_samesite', 'Lax'),
        ]);
        Security::audit('password_changed');
        self::$user = null;
        return ['ok' => true, 'message' => 'Пароль обновлён.'];
    }

    public static function logout(): void
    {
        if (self::check()) {
            Database::update('users', ['remember_hash' => null, 'remember_ua' => null], 'id = ?', [self::user()['id']]);
        }
        self::clearSession();
        setcookie(self::rememberName(), '', [
            'expires' => time() - 3600, 'path' => '/', 'secure' => self::httpsDetected() || (bool) Config::get('security.cookie_secure', false),
            'httponly' => true, 'samesite' => (string) Config::get('security.cookie_samesite', 'Lax'),
        ]);
        Security::audit('logout');
    }

    private static function issueRememberCookie(int $userId, string $salt): void
    {
        $token = Security::randomToken(32);
        Database::update('users', [
            'remember_hash' => Security::saltedHash($token, $salt),
            'remember_ua'   => self::uaHash(),
        ], 'id = ?', [$userId]);
        setcookie(self::rememberName(), $userId . '.' . $token, [
            'expires' => time() + (int) Config::get('security.remember_days', 14) * 86400,
            'path' => '/',
            'domain' => '',
            'secure' => self::httpsDetected() || (bool) Config::get('security.cookie_secure', false),
            'httponly' => true,
            'samesite' => (string) Config::get('security.cookie_samesite', 'Lax'),
        ]);
    }

    private static function loginFromCookie(): void
    {
        $raw = (string) $_COOKIE[self::rememberName()];
        if (!preg_match('/^(\d+)\.([A-Za-z0-9_-]{43})$/', $raw, $m)) {
            return;
        }
        $user = Database::one('SELECT * FROM users WHERE id = ?', [(int) $m[1]]);
        if ($user === null || empty($user['remember_hash']) || !empty($user['totp_enabled'])) {
            // Remember-me never bypasses 2FA.
            if ($user !== null) Database::update('users', ['remember_hash' => null, 'remember_ua' => null], 'id = ?', [$user['id']]);
            return;
        }
        $hash = Security::saltedHash($m[2], $user['salt']);
        if (!hash_equals((string) $user['remember_hash'], $hash)) {
            Security::audit('remember_rejected');
            return;
        }
        // A stolen remember-cookie must not work from another browser.
        if (!empty($user['remember_ua']) && !hash_equals((string) $user['remember_ua'], self::uaHash())) {
            Database::update('users', ['remember_hash' => null, 'remember_ua' => null], 'id = ?', [$user['id']]);
            Security::audit('remember_ua_mismatch');
            Telegram::notify('security', '🚫 Remember-cookie отклонена: другой User-Agent (IP ' . Security::clientIp() . ')');
            return;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['auth_at'] = time();
        $_SESSION['rotated_at'] = time();
        $_SESSION['_fp'] = self::fingerprint();
        self::issueRememberCookie((int) $user['id'], $user['salt']); // token rotation
        self::$user = null;
    }

    private static function clearSession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$user = null;
    }
}
