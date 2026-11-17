# PyHost Engine

[Русский](#russian) · [English](#english)

<a id="russian"></a>

## Возможности

- Первичная установка администратора через `/install`, вход по логину и паролю, смена пароля, TOTP 2FA.
- Загрузка проектов: одиночный `.py`-файл, папка с файлами или ZIP-архив. Встроенный редактор файлов.
- Создание виртуального окружения, установка зависимостей из `requirements.txt`, установка отдельных пакетов.
- Запуск команд `python`, `pip`, `pytest`, `uv` с аргументами. Полный лог stdout/stderr, код возврата и история запусков.
- **Запуск в браузере (Pyodide)**: CPython в WebAssembly выполняет код проекта прямо в браузере посетителя. Не требует `proc_open` и работает даже на бесплатном хостинге, где процессы запрещены.
- Установка переносного Python 3.12 из `astral-sh/python-build-standalone` с обязательной проверкой SHA256 из официального `SHA256SUMS`. Если хост не умеет скачивать, архив можно загрузить вручную.
- Выбор драйвера базы данных (SQLite или MySQL) и его настроек прямо в админке, с проверкой подключения перед сохранением.
- Настройки безопасности, лимитов, пути входа, пути к Python, белого списка IP и Telegram-уведомлений.
- Диагностика окружения и журнал аудита на странице `/security`.
- Публичный health-check `/health` отдаёт только `{"ok":true}` — без версий и путей.

## Требования

- PHP 8.0 или новее.
- Расширения: `pdo_sqlite`, `mbstring`. Для MySQL дополнительно требуется `pdo_mysql`.
- `zip` нужен только для загрузки ZIP-архивов; вместо него можно использовать `unzip` в CLI.
- Хотя бы одна функция запуска процессов: `proc_open`, `exec`, `shell_exec`, `popen`, `system` или `passthru`. Если все отключены, админка продолжает работать, но запуск кода и установка переносного Python будут недоступны.
- Доступ на запись к каталогу `storage/`.
- Системный Python не обязателен: его можно заменить встроенным через раздел «Среда выполнения».

Рекомендуется указывать `DocumentRoot` на каталог `public/`. Если хост указывает корень проекта, используется корневой `index.php` как точка входа: он делегирует запросы каноническому контроллеру `public/index.php`. Apache-правила в корневом `.htaccess` закрывают `app/`, `storage/`, `views/`, `runtime/` и `config.local.php` и направляют все нестатические запросы в корневой `index.php`.

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

### Хостинг с DocumentRoot в корне проекта

Если изменить DocumentRoot нельзя (типично для бесплатных хостингов), корневой `.htaccess` сам закрывает служебные каталоги и проксирует запросы в `index.php`:

- `app/`, `storage/`, `views/`, `config.local.php` — `403` для любых файлов;
- содержимое `runtime/` — `403`, но сам адрес `/runtime` остаётся маршрутом панели (реальный каталог с таким именем иначе перехватывал бы запрос и Apache отдавал бы `403`);
- `DirectorySlash Off`, чтобы Apache не делал `301 /runtime → /runtime/`;
- статика из `public/` (например `/assets/css/app.css`) отдаётся напрямую, всё остальное уходит во front controller.

Проверить после загрузки: `/runtime` должен открывать страницу среды, а `/storage/` — отдавать `403`.

## Структура

```text
index.php        корневая точка входа для хостинга с DocumentRoot в корне проекта
public/          front controller, CSS/JS, единственный публичный каталог
app/             классы: роутинг, авторизация, БД, процессы, рантайм, безопасность
views/           шаблоны интерфейса
storage/         БД, сессии, проекты, venv, логи, переносной Python — не должен быть доступен извне
runtime/         устаревшее место переносного Python (переносится в storage/runtime автоматически)
config.local.php локальные переопределения конфигурации (создаётся при необходимости)
```

## Настройки

Большинство параметров меняется в разделе «Настройки» и сохраняется в таблице `settings` базы SQLite. Значения из этой таблицы имеют приоритет над файлами конфигурации.

Доступно через интерфейс:

- название панели и адрес входа;
- подключение к БД: SQLite или MySQL, адрес, порт, имя базы, пользователь и пароль;
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

Драйвер и параметры подключения выбираются в разделе «Настройки» → «Подключение к базе данных». Поддерживаются SQLite и MySQL. Кнопка «Проверить подключение» пробует соединиться до сохранения, поэтому опечатка в адресе или пароле не приведёт к потере доступа.

- Для SQLite задаётся путь к файлу БД, по умолчанию `storage/db/engine.sqlite`.
- Для MySQL задаются хост, порт, имя базы, пользователь, пароль и кодировка (`utf8mb4` по умолчанию). База должна существовать заранее.
- Параметры хранятся в `storage/db/connection.json` с правами 0600 на Linux. Пароль шифруется ключом из `storage/db/app.key`.
- Схема таблиц создаётся и обновляется автоматически для выбранного драйвера.
- Если `connection.json` скопирован с Windows, путь вида `C:\...` на Linux игнорируется и заменяется на `storage/db/engine.sqlite` — иначе на хостинге создалась бы пустая база с именем `C:`.

**Важно:** переключение драйвера не переносит данные. Если перейти с SQLite на пустую MySQL-базу, администратора и проекты нужно создать заново — движок покажет `/install`.

### Запуск в браузере (без процессов)

Кнопка «Запустить в браузере» на странице проекта открывает `/projects/{id}/browser`. Код исполняется в браузере посетителя через Pyodide — CPython, скомпилированный в WebAssembly. Серверные `proc_open`/`exec` при этом не используются вообще, поэтому режим работает даже на хостингах, где запуск процессов запрещён (infinityfree и подобные).

Как это устроено:

- файлы проекта (только текст, до 512 КБ на файл) отдаёт JSON-эндпоинт `/projects/{id}/source`;
- они монтируются в виртуальную файловую систему Pyodide в `/project`, точка входа запускается через `runpy`;
- `stdout`/`stderr` перехватываются и печатаются в консоль панели.

Виртуальное окружение повторяет серверный порядок работы:

1. **Создать venv** — в виртуальной ФС появляется `/venv` с `pyvenv.cfg` и каталогом `site-packages`, который добавляется в `sys.path`. Список установленных пакетов хранится в `localStorage` браузера и в `/venv/manifest.json`.
2. **Установить из requirements.txt** — файл разбирается целиком: комментарии, пустые строки, вложенные `-r other.txt`, маркеры окружения и пины версий. Ставится через `micropip`, зависимости подтягиваются автоматически, уже присутствующие пакеты не переустанавливаются.
3. **Запустить** — перед запуском окружение восстанавливается из манифеста (после перезагрузки страницы пакеты ставятся заново), затем выполняется точка входа.

Строки, которые браузер выполнить не может (`-e .`, `git+https://…`, `--index-url`), не роняют установку — они показываются в консоли как пропущенные. Пакеты с C-расширениями ставятся только при наличии сборки WebAssembly; остальные `micropip` честно отвергнет с сообщением.

Ограничения режима: нужен доступ браузера к CDN jsDelivr, файлы на сервер не записываются, переменные окружения и секреты хостинга недоступны, а окружение живёт в памяти вкладки (манифест — в браузере). Версия Pyodide задаётся в конфигурации (`security.pyodide_version`), включение режима — `security.browser_python`.

Старый способ задать SQLite-файл через `config.local.php` тоже работает как значение по умолчанию, но сохранённые в админке настройки имеют приоритет. Через `config.local.php` по-прежнему можно переопределить пути `storage`, `projects`, `venvs`, `logs`, `sessions`, `timezone`, `session_name`, `max_upload` и `max_files`.

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

Страница `/security` показывает состояние PHP, PDO SQLite/MySQL, ZIP, каталогов storage, активного Python, модуля `venv`, флагов сессий и HTTPS, а также последние события аудита. Причины неудачного входа (капча, блокировка, неверный пароль) попадают в журнал аудита; отладочное логирование входа отключено и файлы не создаёт.

На хостинге с Windows-файловой системой диагностика может показывать `FAIL` для прав каталога сессий: `chmod 0700` там не работает. Это ожидаемо. Проверка HTTPS будет показывать `FAIL` до тех пор, пока сайт не будет работать по HTTPS; после перехода включите `Secure`-флаг cookie и HSTS.

## Ограничения

- Один администратор; multi-user и роли не предусмотрены.
- SQLite или MySQL; автоматического переноса данных между драйверами нет.
- Для запуска Python нужна разрешённая функция запуска процессов и достаточно места в `storage/`.
- Бесплатные хостинги (infinityfree и подобные) полностью отключают `proc_open`/`exec`/`shell_exec`/`popen`/`system`/`passthru`. На них панель работает, но запустить Python невозможно — ни системный, ни переносной: PHP не может создать процесс. Нужен VPS или хостинг, где эти функции разрешены.
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
- **Browser execution (Pyodide)**: CPython compiled to WebAssembly runs the project code inside the visitor's browser. No `proc_open` needed, so it works even on free hosts that forbid processes.
- Installation of portable Python 3.12 from `astral-sh/python-build-standalone` with mandatory SHA256 verification against the official `SHA256SUMS`. If the host cannot download files, the archive can be uploaded manually.
- Choosing the database driver (SQLite or MySQL) and its settings in the admin UI, with a connection check before saving.
- Settings for security, limits, login path, Python binary path, IP allowlist and Telegram notifications.
- Environment diagnostics and an audit log at `/security`.
- A public health check at `/health` that returns only `{"ok":true}` — no versions or paths.

### Requirements

- PHP 8.0 or newer.
- Extensions: `pdo_sqlite`, `mbstring`. `pdo_mysql` is additionally required for MySQL.
- `zip` is only needed for ZIP uploads; `unzip` on the CLI can be used instead.
- At least one process execution function: `proc_open`, `exec`, `shell_exec`, `popen`, `system` or `passthru`. If all are disabled, the admin panel still works, but code execution and portable Python installation are unavailable.
- Write access to the `storage/` directory.
- System Python is optional: it can be replaced by the bundled runtime from the "Runtime" page.

It is recommended to point `DocumentRoot` at `public/`. If the host points it at the project root, the root `index.php` acts as the entry point and delegates to the canonical `public/index.php` controller.

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

#### Hosts with DocumentRoot at the project root

When `DocumentRoot` cannot be changed (typical for free hosting), the root `.htaccess` protects the internals and proxies requests to `index.php`:

- `app/`, `storage/`, `views/`, `config.local.php` answer `403` for every file;
- `runtime/` contents answer `403`, but `/runtime` itself stays a panel route — otherwise the real directory with that name swallows the request and Apache answers `403`;
- `DirectorySlash Off` stops Apache from answering `301 /runtime → /runtime/`;
- static files under `public/` (for example `/assets/css/app.css`) are served directly, everything else goes to the front controller.

After uploading, verify that `/runtime` opens the runtime page and `/storage/` answers `403`.

### Layout

```text
index.php        root entry point for hosts with DocumentRoot at the project root
public/          front controller, CSS/JS, the only public directory
app/             classes: routing, auth, database, processes, runtime, security
views/           UI templates
storage/         database, sessions, projects, venvs, logs and the portable Python — must not be public
runtime/         legacy location of the portable Python (migrated to storage/runtime automatically)
config.local.php local config overrides (create when needed)
```

### Settings

Most parameters are changed on the "Settings" page and stored in the SQLite `settings` table. Values from that table take precedence over configuration files.

Available in the UI:

- panel name and login path;
- database connection: SQLite or MySQL, host, port, database name, user and password;
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

The driver and connection parameters are selected on the "Settings" page under "Database connection". SQLite and MySQL are supported. The "Test connection" button tries to connect before saving, so a typo in the host or password will not lock you out.

- For SQLite, set the database file path; the default is `storage/db/engine.sqlite`.
- For MySQL, set host, port, database name, user, password and charset (`utf8mb4` by default). The database must already exist.
- Settings are stored in `storage/db/connection.json` with 0600 permissions on Linux. The password is encrypted with the key from `storage/db/app.key`.
- Table schemas are created and upgraded automatically for the selected driver.
- If `connection.json` was copied from Windows, a `C:\...` path is ignored on Linux and replaced with `storage/db/engine.sqlite` — otherwise an empty database named `C:` would be created on the host.

**Important:** switching drivers does not migrate data. Moving from SQLite to an empty MySQL database means creating the administrator and projects again — the engine will show `/install`.

#### Browser execution (no processes)

The "Run in browser" button on a project page opens `/projects/{id}/browser`. Code runs inside the visitor's browser through Pyodide — CPython compiled to WebAssembly. Server-side `proc_open`/`exec` are not used at all, so this mode works even where process execution is forbidden (infinityfree and similar).

How it works:

- the `/projects/{id}/source` JSON endpoint serves the project files (text only, 512 KB per file);
- they are mounted into the Pyodide virtual filesystem at `/project` and the entry point runs through `runpy`;
- `stdout`/`stderr` are captured and printed into the panel console.

The virtual environment mirrors the server-side flow:

1. **Create venv** — `/venv` appears in the virtual filesystem with `pyvenv.cfg` and a `site-packages` directory that is added to `sys.path`. The package manifest is stored in the browser's `localStorage` and in `/venv/manifest.json`.
2. **Install from requirements.txt** — the file is parsed completely: comments, blank lines, nested `-r other.txt`, environment markers and version pins. Installation goes through `micropip`, dependencies are resolved automatically, and packages that are already present are not reinstalled.
3. **Run** — the environment is restored from the manifest first (packages are reinstalled after a page reload), then the entry point executes.

Lines the browser cannot honour (`-e .`, `git+https://…`, `--index-url`) do not abort the install — they are reported in the console as skipped. Packages with C extensions install only when a WebAssembly build exists; `micropip` rejects the rest with a clear message.

Limits: the browser needs access to the jsDelivr CDN, nothing is written back to the server, host environment variables/secrets are unavailable, and the environment lives in the tab's memory (the manifest lives in the browser). The Pyodide version is set by `security.pyodide_version`; the mode is toggled by `security.browser_python`.

Defining the SQLite file through `config.local.php` still works as a default, but settings saved in the admin UI take precedence. `config.local.php` can still override `storage`, `projects`, `venvs`, `logs`, `sessions`, `timezone`, `session_name`, `max_upload` and `max_files`.

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

The `/security` page shows the state of PHP, PDO SQLite/MySQL, ZIP, storage directories, the active Python, the `venv` module, session flags and HTTPS, along with recent audit events. Failed-login reasons (CAPTCHA, lockout, bad password) are recorded in the audit log; the verbose login debug log is disabled and creates no files.

On Windows filesystems, diagnostics may report `FAIL` for session directory permissions because `chmod 0700` has no effect there. This is expected. The HTTPS check will report `FAIL` until the site is served over HTTPS; after switching, enable the `Secure` cookie flag and HSTS.

### Limitations

- Single administrator; no multi-user support or roles.
- SQLite or MySQL; there is no automatic data migration between drivers.
- Running Python requires an available process execution function and enough free space in `storage/`.
- Free hosts (infinityfree and similar) disable `proc_open`/`exec`/`shell_exec`/`popen`/`system`/`passthru` entirely. The panel still runs there, but Python cannot be started at all — neither system nor portable, because PHP cannot spawn a process. Use a VPS or a host that allows these functions.
- Code runs with the permissions of the web server user.
- Default upload limits: 25 MB and 300 files; default output limit: 8 MB.
