<h1>Настройки</h1>
<div class="grid cols-2">
  <section class="card">
    <h2>Безопасность и выполнение</h2>
    <form method="post" action="/settings">
      <?= csrfField() ?>
      <div class="field">
        <label for="app_name">Название панели</label>
        <input id="app_name" name="app_name" type="text" value="<?= e(Config::get('app_name')) ?>" maxlength="60">
      </div>
      <div class="field">
        <label for="login_path">Адрес входа (например engine-login → сайт/engine-login)</label>
        <div class="row"><span class="muted mono">/</span><input id="login_path" name="login_path" type="text" class="mono" value="<?= e(Config::get('security.login_path', 'engine-login')) ?>" maxlength="40" pattern="[a-zA-Z0-9_-]{1,40}" required></div>
      </div>

      <label class="check"><input type="checkbox" name="execution_enabled" value="1" <?= Config::get('security.execution_enabled', true) ? 'checked' : '' ?>> Разрешить выполнение Python-кода</label>
      <label class="check"><input type="checkbox" name="login_captcha" value="1" <?= Config::get('security.login_captcha', true) ? 'checked' : '' ?>> Математическая капча на входе</label>
      <label class="check"><input type="checkbox" name="require_useragent" value="1" <?= Config::get('security.require_useragent', true) ? 'checked' : '' ?>> Отклонять запросы без User-Agent</label>
      <label class="check"><input type="checkbox" name="cookie_secure" value="1" <?= Config::get('security.cookie_secure', false) ? 'checked' : '' ?>> Secure-флаг cookie (включается и сам при HTTPS)</label>
      <label class="check"><input type="checkbox" name="cookie_prefix" value="1" <?= Config::get('security.cookie_prefix', true) ? 'checked' : '' ?>> Префикс <code class="inline">__Host-</code> для cookie на HTTPS</label>
      <label class="check"><input type="checkbox" name="session_bind_ua" value="1" <?= Config::get('security.session_bind_ua', true) ? 'checked' : '' ?>> Привязывать сессию к User-Agent</label>
      <label class="check"><input type="checkbox" name="session_bind_ip" value="1" <?= Config::get('security.session_bind_ip', false) ? 'checked' : '' ?>> Привязывать сессию к IP (осторожно с мобильными)</label>
      <label class="check"><input type="checkbox" name="hide_server_header" value="1" <?= Config::get('security.hide_server_header', true) ? 'checked' : '' ?>> Скрывать X-Powered-By</label>
      <label class="check"><input type="checkbox" name="csrf_enabled" value="1" <?= Config::get('security.csrf_enabled', true) ? 'checked' : '' ?>> CSRF-защита (отключать не рекомендуется)</label>

      <div class="grid cols-2" style="margin-top:14px">
        <div class="field"><label>Ротация CSRF</label>
          <select name="csrf_rotation">
            <option value="session" <?= Config::get('security.csrf_rotation', 'session') === 'session' ? 'selected' : '' ?>>на сессию</option>
            <option value="request" <?= Config::get('security.csrf_rotation', 'session') === 'request' ? 'selected' : '' ?>>на каждый запрос</option>
          </select>
        </div>
        <div class="field"><label>SameSite cookie</label>
          <select name="cookie_samesite">
            <option value="Lax" <?= Config::get('security.cookie_samesite', 'Lax') === 'Lax' ? 'selected' : '' ?>>Lax</option>
            <option value="Strict" <?= Config::get('security.cookie_samesite', 'Lax') === 'Strict' ? 'selected' : '' ?>>Strict</option>
          </select>
        </div>
        <div class="field"><label>Время жизни CSRF, сек</label><input name="security_csrf_ttl" type="number" min="60" max="86400" value="<?= (int) Config::get('security.csrf_ttl', 3600) ?>"></div>
        <div class="field"><label>Таймаут запуска, сек</label><input name="security_run_timeout" type="number" min="5" max="3600" value="<?= (int) Config::get('security.run_timeout', 120) ?>"></div>
        <div class="field"><label>Лимит вывода, байт</label><input name="security_max_output" type="number" min="65536" max="52428800" step="65536" value="<?= (int) Config::get('security.max_output', 8388608) ?>"></div>
        <div class="field"><label>Попыток входа до блокировки</label><input name="security_login_max_attempts" type="number" min="3" max="20" value="<?= (int) Config::get('security.login_max_attempts', 5) ?>"></div>
        <div class="field"><label>Блокировка, сек (900 = 15 мин)</label><input name="security_login_lockout" type="number" min="60" max="86400" value="<?= (int) Config::get('security.login_lockout', 900) ?>"></div>
        <div class="field"><label>Запросов в минуту (IP)</label><input name="security_global_rpm" type="number" min="10" max="1000" value="<?= (int) Config::get('security.global_rpm', 120) ?>"></div>
        <div class="field"><label>Запусков в минуту</label><input name="security_run_rpm" type="number" min="1" max="200" value="<?= (int) Config::get('security.run_rpm', 20) ?>"></div>
        <div class="field"><label>Жизнь remember-cookie, дней</label><input name="security_remember_days" type="number" min="1" max="90" value="<?= (int) Config::get('security.remember_days', 14) ?>"></div>
        <div class="field"><label>Неактивность сессии, сек</label><input name="security_session_idle" type="number" min="300" max="86400" value="<?= (int) Config::get('security.session_idle', 7200) ?>"></div>
        <div class="field"><label>Ротация ID сессии, сек</label><input name="security_session_rotate" type="number" min="300" max="86400" value="<?= (int) Config::get('security.session_rotate', 1800) ?>"></div>
      </div>

      <div class="field">
        <label for="blocked_useragents">Чёрный список User-Agent (подстроки через запятую)</label>
        <input id="blocked_useragents" name="blocked_useragents" type="text" class="mono" placeholder="python-requests, sqlmap, Go-http-client" value="<?= e(Config::get('security.blocked_useragents', '')) ?>">
      </div>
      <div class="field">
        <label for="python_binary">Путь к Python (пусто = авто)</label>
        <input id="python_binary" name="python_binary" type="text" class="mono" placeholder="/usr/bin/python3 или C:\Python313\python.exe" value="<?= e(Config::get('security.python_binary', '')) ?>">
      </div>
      <div class="field">
        <label for="ip_whitelist">Белый список IP (через запятую, пусто = все)</label>
        <input id="ip_whitelist" name="ip_whitelist" type="text" class="mono" placeholder="127.0.0.1, 192.168.1.0/24" value="<?= e(Config::get('security.ip_whitelist', '')) ?>">
      </div>
      <button class="btn primary" type="submit">Сохранить</button>
    </form>
  </section>

  <div>
    <section class="card">
      <h2>Подключение к базе данных</h2>
      <p class="muted" style="font-size:13px;margin-top:0">Сейчас используется: <?= e($db['driver'] === 'mysql' ? 'MySQL · ' . $db['host'] . '/' . $db['name'] : 'SQLite · ' . basename($db['path'])) ?>.</p>
      <form method="post" action="/settings">
        <?= csrfField() ?>
        <input type="hidden" name="db_config" value="1">
        <div class="field">
          <label for="db_driver">Движок базы данных</label>
          <select id="db_driver" name="db_driver">
            <option value="sqlite" <?= $db['driver'] === 'sqlite' ? 'selected' : '' ?>>SQLite (файл)</option>
            <option value="mysql" <?= $db['driver'] === 'mysql' ? 'selected' : '' ?>>MySQL (удалённый сервер)</option>
          </select>
        </div>
        <div class="field">
          <label for="db_sqlite_path">Файл SQLite (используется для SQLite)</label>
          <input id="db_sqlite_path" name="db_sqlite_path" type="text" class="mono" value="<?= e($db['path']) ?>">
        </div>
        <div class="grid cols-2" style="margin-top:14px">
          <div class="field"><label>Хост MySQL</label><input name="db_host" type="text" class="mono" value="<?= e($db['host']) ?>" placeholder="127.0.0.1"></div>
          <div class="field"><label>Порт MySQL</label><input name="db_port" type="number" min="1" max="65535" value="<?= (int) $db['port'] ?>"></div>
          <div class="field"><label>Имя базы</label><input name="db_name" type="text" class="mono" value="<?= e($db['name']) ?>"></div>
          <div class="field"><label>Пользователь</label><input name="db_user" type="text" class="mono" value="<?= e($db['user']) ?>"></div>
          <div class="field"><label>Кодировка</label>
            <select name="db_charset">
              <?php foreach (['utf8mb4', 'utf8', 'latin1'] as $cs): ?>
                <option value="<?= e($cs) ?>" <?= $db['charset'] === $cs ? 'selected' : '' ?>><?= e($cs) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field">
          <label for="db_pass">Пароль MySQL</label>
          <input id="db_pass" name="db_pass" type="password" autocomplete="off" class="mono" placeholder="<?= !empty($db['password_set']) ? 'установлен — оставьте пустым' : 'пароль пользователя БД' ?>">
        </div>
        <div class="row">
          <button class="btn primary" type="submit" name="db_save" value="1">Сохранить</button>
          <button class="btn" type="submit" name="db_test" value="1">Проверить подключение</button>
        </div>
      </form>
      <p class="muted" style="font-size:12px;margin:12px 0 0">Пароль хранится в зашифрованном виде. Перед сохранением подключение проверяется. При смене драйвера данные не переносятся: после переключения на пустую базу потребуется заново выполнить установку администратора.</p>
    </section>

    <section class="card">
      <h2>Смена пароля</h2>
      <form method="post" action="/settings">
        <?= csrfField() ?>
        <input type="hidden" name="change_password" value="1">
        <div class="field"><label>Текущий пароль</label><input name="current_password" type="password" required autocomplete="current-password"></div>
        <div class="field"><label>Новый пароль (от 10 символов)</label><input name="new_password" type="password" required minlength="10" autocomplete="new-password"></div>
        <button class="btn" type="submit">Обновить пароль</button>
      </form>
    </section>

    <section class="card">
      <h2>Двухфакторная аутентификация (TOTP)</h2>
      <p class="muted" style="font-size:13px;margin-top:0">Статус: <?= $twofaEnabled ? '<span class="badge ok">включена</span>' : '<span class="badge warn">отключена</span>' ?></p>
      <?php if ($twofaSecret !== ''): ?>
        <div class="hint" style="margin-bottom:14px">
          Секрет: <code class="inline" style="font-size:14px;user-select:all"><?= e($twofaSecret) ?></code><br>
          <span class="muted" style="font-size:11px">Ссылка: <code class="inline" style="word-break:break-all"><?= e($twofaUri) ?></code></span>
        </div>
        <form method="post" action="/settings" class="row">
          <?= csrfField() ?><input type="hidden" name="twofa_action" value="confirm">
          <input name="twofa_code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="Код из приложения" style="max-width:220px" class="mono" required>
          <button class="btn primary sm" type="submit">Подтвердить и включить</button>
        </form>
      <?php else: ?>
        <form method="post" action="/settings" class="row">
          <?= csrfField() ?><input type="hidden" name="twofa_action" value="begin">
          <input name="twofa_password" type="password" placeholder="Текущий пароль" style="max-width:220px" required>
          <button class="btn sm" type="submit"><?= $twofaEnabled ? 'Сменить секрет' : 'Включить 2FA' ?></button>
        </form>
      <?php endif; ?>
      <?php if ($twofaEnabled && $twofaSecret === ''): ?>
        <form method="post" action="/settings" class="row" style="margin-top:12px">
          <?= csrfField() ?><input type="hidden" name="twofa_action" value="disable">
          <input name="twofa_password" type="password" placeholder="Пароль" style="max-width:160px" required>
          <input name="twofa_code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="Код" style="max-width:120px" class="mono" required>
          <button class="btn danger sm" type="submit">Отключить 2FA</button>
        </form>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Telegram-уведомления (API через DoH)</h2>
      <?php $tgOn = (bool) Config::get('telegram.enabled', false); ?>
      <p class="muted" style="font-size:13px;margin-top:0">Статус: <?= $tgOn ? '<span class="badge ok">включены</span>' : '<span class="badge warn">отключены</span>' ?></p>
      <form method="post" action="/settings">
        <?= csrfField() ?>
        <label class="check"><input type="checkbox" name="tg_enabled" value="1" <?= $tgOn ? 'checked' : '' ?>> Включить уведомления</label>
        <div class="field"><label>Токен бота (@BotFather)</label><input name="tg_token" type="password" autocomplete="off" class="mono" placeholder="<?= Config::get('telegram.bot_token') ? 'установлен — оставьте пустым' : '123456789:AA...' ?>"></div>
        <div class="field"><label>Chat ID</label><input name="tg_chat" type="text" class="mono" value="<?= e(Config::get('telegram.chat_id', '')) ?>" placeholder="-1001234567890"></div>
        <div class="field"><label>Резолвинг api.telegram.org</label>
          <select name="tg_doh">
            <option value="cloudflare" <?= Config::get('telegram.doh', 'cloudflare') === 'cloudflare' ? 'selected' : '' ?>>Cloudflare DoH</option>
            <option value="google" <?= Config::get('telegram.doh', 'cloudflare') === 'google' ? 'selected' : '' ?>>Google DoH</option>
            <option value="direct" <?= Config::get('telegram.doh', 'cloudflare') === 'direct' ? 'selected' : '' ?>>Прямой DNS (без DoH)</option>
          </select>
        </div>
        <div class="field"><label>События</label>
          <?php $tgEvents = explode(',', (string) Config::get('telegram.events', '')); ?>
          <div class="row" style="gap:14px">
            <?php foreach (['login' => 'вход', 'failed_login' => 'неудачный вход', 'twofa' => '2FA', 'run' => 'запуск', 'project' => 'проекты', 'venv' => 'venv', 'runtime' => 'среда', 'security' => 'угрозы'] as $key => $label): ?>
              <label class="check" style="margin:0"><input type="checkbox" name="tg_events[]" value="<?= e($key) ?>" <?= in_array($key, $tgEvents, true) ? 'checked' : '' ?>> <?= e($label) ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="row">
          <button class="btn primary" type="submit">Сохранить</button>
          <button class="btn" type="submit" name="tg_test" value="1">Тестировать отправку</button>
        </div>
      </form>
      <p class="muted" style="font-size:12px;margin:12px 0 0">IP-адрес api.telegram.org получается через DNS-over-HTTPS и закрепляется через CURLOPT_RESOLVE — системный DNS не используется. Кэш 1 час: <code class="inline">storage/cache/telegram_doh.json</code>.</p>
    </section>

    <section class="card">
      <h2>Защита, которая уже включена</h2>
      <ul class="muted" style="padding-left:20px;margin:0;font-size:13px">
        <li>CSRF: токен в формах и заголовке, настраиваемые ротация и TTL</li>
        <li>Сессия: HttpOnly, SameSite, __Host- на HTTPS, привязка к UA/IP, ротация ID и таймаут неактивности</li>
        <li>Необязательная TOTP 2FA (RFC 6238, окно ±30 с)</li>
        <li>bcrypt(12), блокировка после 5 ошибок на 15 минут</li>
        <li>Rate limit: общий, на вход, на 2FA и на запуски</li>
        <li>Экранирование вывода + CSP, HSTS, COOP/CORP, nosniff, X-Frame-Options</li>
        <li>Фильтр User-Agent и белый список IP</li>
        <li>Только prepared PDO-запросы, запуск без shell и белый список команд</li>
        <li>Защита от path traversal, ZIP-slip и symlink в архивах</li>
      </ul>
      <a class="btn sm" href="/security" style="margin-top:14px">Открыть диагностику →</a>
    </section>
  </div>
</div>
