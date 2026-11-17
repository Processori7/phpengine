# PyHost Engine

[Русский](#russian) · [English](#english)

<a id="russian"></a>

## Возможности

- Первичная установка администратора через `/install`, вход по логину и паролю, смена пароля, TOTP 2FA.
- Загрузка проектов: одиночный `.py`-файл, папка с файлами или ZIP-архив. Встроенный редактор файлов.
- Создание виртуального окружения, установка зависимостей из `requirements.txt`, установка отдельных пакетов.
- Запуск команд `python`, `pip`, `pytest`, `uv` с аргументами. Полный лог stdout/stderr, код возврата и история запусков.
- Установка переносного Python 3.12 из `astral-sh/python-build-standalone` с обязательной проверкой SHA256 из официального `SHA256SUMS`. Если хост не умеет скачивать, архив можно загрузить вручную.
- Настройки безопасности, лимитов, пути входа, пути к Python, белого списка IP и Telegram-уведомлений.
- Диагностика окружения и журнал аудита на странице `/security`.
- Публичный health-check `/health` отдаёт только `{"ok":true}` — без версий и путей.

## Требования

- PHP 8.0 или новее.
- Расширения: `pdo_sqlite`, `mbstring`.
- `zip` нужен только для загрузки ZIP-архивов; вместо него можно использовать `unzip` в CLI.
- Хотя бы одна функция запуска процессов: `proc_open`, `exec`, `shell_exec`, `popen`, `system` или `passthru`. Если все отключены, админка продолжает работать, но запуск кода и установка переносного Python будут недоступны.
- Доступ на запись к каталогу `storage/`.
- Системный Python не обязателен: его можно заменить встроенным через раздел «Среда выполнения».

Рекомендуется указывать `DocumentRoot` на каталог `public/`. Если хост указывает корень проекта, Apache-правила в корневом `.htaccess` закрывают `app/`, `storage/`, `views/`, `runtime/` и `config.local.php` и перенаправляют запросы в `public/index.php`.

## Установка

1. Скопируйте файлы проекта на хостинг.
2. Откройте сайт — при отсутствии администратора произойдёт переход на `/install`.
3. Задайте логин и пароль не короче 10 символов. После этого `/install` закрывается.
4. Войдите через `/engine-login`. Адрес входа можно сменить в настройках.
5. Откройте «Среда выполнения» и либо установите переносной Python, либо укажите путь к уже установленному интерпретатору в настройках.
6. Создайте проект, при необходимости создайте venv, установите зависимости и запустите код.

Локальный запуск через встроенный сервер PHP:

```bash
php -S 127.0.0.1:8765 -t public
```

## Структура

```text
public/          front controller, CSS/JS, единственный публичный каталог
app/             классы: роутинг, авторизация, БД, процессы, рантайм, безопасность
views/           шаблоны интерфейса
storage/         БД, сессии, проекты, venv, логи и кэш — не должен быть доступен извне
runtime/         переносной Python, устанавливается из админки
config.local.php локальные переопределения конфигурации (создаётся при необходимости)
```

## Настройки

Большинство параметров меняется в разделе «Настройки» и сохраняется в таблице `settings` базы SQLite. Значения из этой таблицы имеют приоритет над файлами конфигурации.

Доступно через интерфейс:

- название панели и адрес входа;
- разрешение на выполнение Python-кода и путь к бинарнику Python;
- математическая капча на входе, фильтр и чёрный список User-Agent;
- CSRF: включение, ротация на сессию/запрос, TTL;
- cookie: `Secure`, префикс `__Host-`, `SameSite`;
- привязка сессии к User-Agent и IP, скрытие `X-Powered-By`;
- попытки входа до блокировки и длительность блокировки;
- общий лимит запросов, лимит запусков, таймаут запуска и лимит вывода;
- срок жизни remember-cookie, таймаут неактивности и ротация ID сессии;
- белый список IP;
- смена пароля и управление TOTP 2FA;
- Telegram-уведомления: токен, Chat ID, способ резолвинга через DoH, набор событий и тест отправки.

### Подключение к базе данных

Подключение к БД **не настраивается в админке**. Движок использует только SQLite, файл задаётся ключом `db_file` в `Config.php`. Чтобы изменить путь, создайте `config.local.php` в корне проекта:

```php
<?php

return [
    'db_file' => __DIR__ . '/storage/db/engine.sqlite',
];
```

Переход на MySQL или PostgreSQL потребует изменения `app/Database.php`: DSN, учётные данные и SQL-миграции сейчас заточены под SQLite.

Через `config.local.php` также можно переопределить пути `storage`, `projects`, `venvs`, `logs`, `sessions`, `timezone`, `session_name`, `max_upload` и `max_files`. Для этого достаточно вернуть ассоциативный массив с нужными ключами; он накладывается на конфиг по правилу `array_replace_recursive`.

## Безопасность

- CSRF на все POST-запросы, настраиваемые TTL и ротация токена.
- Сессии HttpOnly/SameSite, `__Host-` и `Secure` по HTTPS, привязка к отпечатку UA/IP, ротация ID и idle-таймаут.
- bcrypt с cost 12, блокировка после превышения лимита неудачных попыток, rate limit на вход, 2FA и запуски.
- Remember-me токены привязаны к User-Agent, ротируются при использовании и не обходят 2FA.
- Токен Telegram-бота шифруется в БД через AES-256-GCM; ключ лежит в `storage/db/app.key`.
- Запуск процессов без shell, с белым списком команд и экранированием аргументов; при отсутствии `proc_open` используются резервные функции.
- Защита от path traversal, ZIP-slip и symlink в архивах, ограничение размера загрузки и вывода.
- Вывод ошибок в браузер отключён. Детали пишутся в приватные логи `storage/logs/`.
- Удалённое включение отладки через `debug` или `phpinfo` невозможно.
- Заголовки CSP, HSTS по HTTPS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, COOP/CORP и Permissions-Policy.

## Диагностика

Страница `/security` показывает состояние PHP, PDO SQLite, ZIP, каталогов storage, активного Python, модуля `venv`, флагов сессий и HTTPS, а также последние события аудита.

На хостинге с Windows-файловой системой диагностика может показывать `FAIL` для прав каталога сессий: `chmod 0700` там не работает. Это ожидаемо. Проверка HTTPS будет показывать `FAIL` до тех пор, пока сайт не будет работать по HTTPS; после перехода включите `Secure`-флаг cookie и HSTS.

## Ограничения

- Один администратор; multi-user и роли не предусмотрены.
- Только SQLite и только указанный в конфиге файл БД.
- Для запуска Python нужна разрешённая функция запуска процессов и достаточно места в `storage/`.
- Запуск кода происходит от имени пользователя веб-сервера с его правами файловой системы.
- Максимальный размер загрузки по умолчанию 25 MB, файлов — 300; лимит вывода — 8 MB.

---

<a id="english"></a>

## English

PyHost Engine is a compact PHP admin panel for running Python projects on hosts where system Python is not available. It is built on plain PHP without Composer: data lives in SQLite, and a portable CPython can be installed directly from the admin UI.

### Features

- First-run administrator setup at `/install`, login/password authentication, password change and TOTP 2FA.
- Project uploads: a single `.py` file, a folder of files, or a ZIP archive. Built-in file editor.
- Virtual environment creation, dependency installation from `requirements.txt`, and individual package installation.
- Running `python`, `pip`, `pytest` and `uv` commands with arguments. Full stdout/stderr log, exit code and run history.
- Installation of portable Python 3.12 from `astral-sh/python-build-standalone` with mandatory SHA256 verification against the official `SHA256SUMS`. If the host cannot download files, the archive can be uploaded manually.
- Settings for security, limits, login path, Python binary path, IP allowlist and Telegram notifications.
- Environment diagnostics and an audit log at `/security`.
- A public health check at `/health` that returns only `{"ok":true}` — no versions or paths.

### Requirements

- PHP 8.0 or newer.
- Extensions: `pdo_sqlite`, `mbstring`.
- `zip` is only needed for ZIP uploads; `unzip` on the CLI can be used instead.
- At least one process execution function: `proc_open`, `exec`, `shell_exec`, `popen`, `system` or `passthru`. If all are disabled, the admin panel still works, but code execution and portable Python installation are unavailable.
- Write access to the `storage/` directory.
- System Python is optional: it can be replaced by the bundled runtime from the "Runtime" page.

It is recommended to point `DocumentRoot` at `public/`. If the host points it at the project root, the root `.htaccess` rules deny access to `app/`, `storage/`, `views/`, `runtime/` and `config.local.php`, and route requests to `public/index.php`.

### Installation

1. Copy the project files to the host.
2. Open the site — if no administrator exists, you will be redirected to `/install`.
3. Choose a username and a password of at least 10 characters. After that `/install` is closed.
4. Sign in at `/engine-login`. The login path can be changed in settings.
5. Open "Runtime" and either install portable Python or set the path to an existing interpreter in settings.
6. Create a project, create a venv if needed, install dependencies and run the code.

Local development server:

```bash
php -S 127.0.0.1:8765 -t public
```

### Layout

```text
public/          front controller, CSS/JS, the only public directory
app/             classes: routing, auth, database, processes, runtime, security
views/           UI templates
storage/         database, sessions, projects, venvs, logs and cache — must not be public
runtime/         portable Python installed from the admin UI
config.local.php local config overrides (create when needed)
```

### Settings

Most parameters are changed on the "Settings" page and stored in the SQLite `settings` table. Values from that table take precedence over configuration files.

Available in the UI:

- panel name and login path;
- permission to run Python code and the Python binary path;
- math CAPTCHA on login, User-Agent filter and blocklist;
- CSRF: enable/disable, session/request rotation, TTL;
- cookies: `Secure`, `__Host-` prefix, `SameSite`;
- session binding to User-Agent and IP, hiding `X-Powered-By`;
- failed login attempts before lockout and lockout duration;
- global request rate limit, run rate limit, run timeout and output limit;
- remember-cookie lifetime, session idle timeout and session ID rotation;
- IP allowlist;
- password change and TOTP 2FA management;
- Telegram notifications: token, chat ID, DoH resolver, event set and test send.

#### Database connection

The database connection **cannot be configured in the admin UI**. The engine uses SQLite only; the file is defined by the `db_file` key in `Config.php`. To change the path, create `config.local.php` in the project root:

```php
<?php

return [
    'db_file' => __DIR__ . '/storage/db/engine.sqlite',
];
```

Switching to MySQL or PostgreSQL requires changes in `app/Database.php`: the DSN, credentials and SQL migrations are currently SQLite-specific.

`config.local.php` can also override `storage`, `projects`, `venvs`, `logs`, `sessions`, `timezone`, `session_name`, `max_upload` and `max_files`. Return an associative array with the needed keys; it is merged into the configuration with `array_replace_recursive`.

### Security

- CSRF protection on all POST requests, with configurable TTL and token rotation.
- HttpOnly/SameSite sessions, `__Host-` and `Secure` over HTTPS, UA/IP fingerprint binding, session ID rotation and idle timeout.
- bcrypt with cost 12, lockout after too many failed attempts, rate limits on login, 2FA and runs.
- Remember-me tokens are bound to User-Agent, rotated on use and never bypass 2FA.
- Telegram bot token is encrypted in the database with AES-256-GCM; the key is stored in `storage/db/app.key`.
- Processes are started without a shell, with an allowlist of commands and escaped arguments; fallback functions are used when `proc_open` is unavailable.
- Protection against path traversal, ZIP-slip and symlinks in archives, plus upload and output size limits.
- Browser error output is disabled. Details are written to private logs in `storage/logs/`.
- Remote debugging via `debug` or `phpinfo` parameters is impossible.
- Security headers: CSP, HSTS over HTTPS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, COOP/CORP and Permissions-Policy.

### Diagnostics

The `/security` page shows the state of PHP, PDO SQLite, ZIP, storage directories, the active Python, the `venv` module, session flags and HTTPS, along with recent audit events.

On Windows filesystems, diagnostics may report `FAIL` for session directory permissions because `chmod 0700` has no effect there. This is expected. The HTTPS check will report `FAIL` until the site is served over HTTPS; after switching, enable the `Secure` cookie flag and HSTS.

### Limitations

- Single administrator; no multi-user support or roles.
- SQLite only, using the database file defined in the configuration.
- Running Python requires an available process execution function and enough free space in `storage/`.
- Code runs with the permissions of the web server user.
- Default upload limits: 25 MB and 300 files; default output limit: 8 MB.
