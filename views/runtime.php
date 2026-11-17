<h1>Среда выполнения</h1>
<p class="muted">Переносной Python лежит внутри приложения и не требует установки Python на хостинге.</p>

<section class="card">
  <div class="card head"><h2>Встроенный интерпретатор</h2>
    <?= $status['installed'] ? '<span class="badge ok">установлен</span>' : '<span class="badge warn">не установлен</span>' ?>
  </div>
  <table>
    <tr><td class="muted">Платформа</td><td class="mono"><?= e($status['platform']) ?></td></tr>
    <tr><td class="muted">Официальная сборка</td><td class="mono"><?= e($status['asset']) ?></td></tr>
    <tr><td class="muted">Версия</td><td class="mono"><?= e($status['version'] ?: '—') ?></td></tr>
    <tr><td class="muted">Путь</td><td class="mono"><?= e($status['binary']) ?></td></tr>
    <tr><td class="muted">Размер</td><td class="mono"><?= $status['size'] ? e(Runner::humanBytes((int) $status['size'])) : '—' ?></td></tr>
    <tr><td class="muted">SHA256</td><td class="mono muted" style="font-size:11px"><?= e($status['sha256'] ?: '—') ?></td></tr>
    <tr><td class="muted">Установлен</td><td><?= $status['installed_at'] ? e(date('d.m.Y H:i', (int) $status['installed_at'])) : '—' ?></td></tr>
    <tr><td class="muted">Режим запуска процессов</td><td class="mono"><?= e($status['exec_mode']) ?></td></tr>
    <tr><td class="muted">Свободно на диске</td><td class="mono"><?= $status['free_space'] ? e(Runner::humanBytes((int) $status['free_space'])) : '—' ?></td></tr>
  </table>

  <div class="row" style="margin-top:18px">
    <?php if (!$status['installed'] && $status['supported'] && $status['can_exec'] && Config::get('security.execution_enabled', true)): ?>
      <form method="post" action="/runtime" data-loading><?= csrfField() ?>
        <input type="hidden" name="action" value="install">
        <button class="btn primary" type="submit">Скачать и установить (~33 МБ)</button>
      </form>
    <?php endif; ?>
    <?php if ($status['installed']): ?>
      <form method="post" action="/runtime" data-confirm="Удалить встроенный Python?">
        <?= csrfField() ?><input type="hidden" name="action" value="remove">
        <button class="btn danger" type="submit">Удалить</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (!$status['supported']): ?>
    <div class="flash error" style="margin-top:14px">Для архитектуры <b><?= e($status['platform']) ?></b> нет готовой сборки. Укажите путь к уже установленному Python на хостинге в <a href="/settings">настройках</a>.</div>
  <?php endif; ?>
  <?php if (!$status['can_exec']): ?>
    <div class="flash error" style="margin-top:14px">
      Хостинг запрещает запуск процессов (<code class="inline">proc_open</code>, <code class="inline">exec</code>,
      <code class="inline">shell_exec</code>, <code class="inline">popen</code>, <code class="inline">system</code>,
      <code class="inline">passthru</code> отключены), поэтому ни системный, ни переносной Python здесь не запустится.
      Используйте запуск в браузере: откройте проект и нажмите <b>«Запустить в браузере»</b> — там работает
      CPython в WebAssembly и серверные процессы не нужны.
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Ручная установка (если хостинг не умеет скачивать)</h2>
  <p class="muted" style="font-size:13px">Скачайте файл <?= e($status['asset']) ?> с <a href="https://github.com/astral-sh/python-build-standalone/releases/tag/20261110" target="_blank" rel="noopener">официального релиза</a> и загрузите его сюда. Перед распаковкой проверяется SHA256 из официального файла SHA256SUMS.</p>
  <form method="post" action="/runtime" enctype="multipart/form-data" data-loading>
    <?= csrfField() ?>
    <input type="hidden" name="action" value="upload">
    <div class="row">
      <input type="file" name="runtime_archive" accept=".tar.gz,.gz" required style="max-width:360px">
      <button class="btn" type="submit">Загрузить и проверить</button>
    </div>
  </form>
</section>

<section class="card">
  <h2>Активный Python</h2>
  <?php if ($python): ?>
    <table>
      <tr><td class="muted">Источник</td><td><span class="badge info"><?= e($python['source']) ?></span></td></tr>
      <tr><td class="muted">Версия</td><td class="mono"><?= e($python['version']) ?></td></tr>
      <tr><td class="muted">Команда</td><td class="mono"><?= e($python['bin']) ?></td></tr>
      <tr><td class="muted">Исполняемый</td><td class="mono"><?= e($python['exe']) ?></td></tr>
    </table>
  <?php else: ?>
    <div class="flash error">Python не найден. Установите встроенный выше или задайте путь в настройках.</div>
  <?php endif; ?>
  <p class="hint" style="margin-top:14px">Приоритет: встроенный Python → путь из настроек → системный <code class="inline">python3/python/py -3</code>.</p>
</section>
