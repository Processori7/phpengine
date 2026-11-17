<?php
  $logHtml = '';
  foreach (preg_split('/\R/u', (string) $log) ?: [] as $line) {
      $cls = str_starts_with($line, '[stderr]') ? ' class="stderr"' : '';
      $logHtml .= '<span' . $cls . '>' . e($line) . "</span>\n";
  }
?>
<div class="row" style="justify-content:space-between;margin-bottom:20px">
  <div>
    <h1><?= e($project['name']) ?></h1>
    <div class="muted mono" style="font-size:12px"><?= e($project['dir']) ?> · точка входа: <?= e($project['entrypoint']) ?></div>
  </div>
  <div class="row">
    <a class="btn" href="/projects/<?= (int) $project['id'] ?>/browser">⚡ Запустить в браузере</a>
    <a class="btn" href="/projects">← Все проекты</a>
  </div>
</div>

<div class="grid cols-2">
  <section class="card">
    <div class="card head"><h2>Окружение (venv)</h2>
      <?php if ($venvReady): ?><span class="badge ok">готово</span><?php else: ?><span class="badge warn">не создано</span><?php endif; ?>
    </div>
    <p class="muted" style="font-size:13px;margin-top:0">Каталог: <code class="inline"><?= e(VenvManager::path($project)) ?></code></p>
    <div class="row">
      <form method="post" action="/projects/<?= (int) $project['id'] ?>/venv-create" data-loading><?= csrfField() ?>
        <button class="btn primary sm" type="submit" <?= Config::get('security.execution_enabled', true) ? '' : 'disabled' ?>>Создать venv</button>
      </form>
      <form method="post" action="/projects/<?= (int) $project['id'] ?>/venv-install" data-loading><?= csrfField() ?>
        <button class="btn sm" type="submit" <?= $venvReady ? '' : 'disabled' ?>>pip install -r requirements.txt</button>
      </form>
      <?php if ($venvReady): ?>
      <form method="post" action="/projects/<?= (int) $project['id'] ?>/venv-remove" data-confirm="Удалить виртуальное окружение?">
        <?= csrfField() ?><button class="btn danger sm" type="submit">Удалить</button>
      </form>
      <?php endif; ?>
    </div>
    <form method="post" action="/projects/<?= (int) $project['id'] ?>/venv-package" class="row" style="margin-top:14px" data-loading>
      <?= csrfField() ?>
      <input name="package" type="text" placeholder="requests==2.31.0" style="flex:1">
      <button class="btn sm" type="submit" <?= $venvReady ? '' : 'disabled' ?>>Установить пакет</button>
    </form>

    <?php if ($venvLog !== ''): ?>
      <details style="margin-top:14px"><summary class="muted" style="cursor:pointer;font-size:13px">Лог создания venv</summary>
        <pre class="console" style="max-height:180px;margin-top:8px"><?= e($venvLog) ?></pre></details>
    <?php endif; ?>
    <?php if ($pipLog !== ''): ?>
      <details style="margin-top:10px"><summary class="muted" style="cursor:pointer;font-size:13px">Лог pip</summary>
        <pre class="console" style="max-height:180px;margin-top:8px"><?= e($pipLog) ?></pre></details>
    <?php endif; ?>
    <?php if ($packages !== ''): ?>
      <details style="margin-top:10px"><summary class="muted" style="cursor:pointer;font-size:13px">Установленные пакеты</summary>
        <pre class="console" style="max-height:180px;margin-top:8px"><?= e($packages) ?></pre></details>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card head"><h2>Запуск</h2>
      <?php if ($run): ?><a href="/runs/<?= (int) $run['id'] ?>">Лог #<?= (int) $run['id'] ?> →</a><?php endif; ?>
    </div>
    <form method="post" action="/projects/<?= (int) $project['id'] ?>/run" data-loading>
      <?= csrfField() ?>
      <div class="field">
        <label for="command-input">Команда (python, pip, pytest, uv)</label>
        <input id="command-input" name="command" type="text" value="<?= e('python ' . $project['entrypoint'] . ' ' . $project['args']) ?>" spellcheck="false" class="mono">
      </div>
      <div class="row" style="margin-bottom:12px">
        <button type="button" class="btn sm" data-command="python <?= e($project['entrypoint']) ?>">основной скрипт</button>
        <button type="button" class="btn sm" data-command="python -m pytest">pytest</button>
        <button type="button" class="btn sm" data-command="pip list">pip list</button>
        <button type="button" class="btn sm" data-command="python -c &quot;print(2+2)&quot;">python -c</button>
      </div>
      <button class="btn primary" type="submit" <?= Config::get('security.execution_enabled', true) ? '' : 'disabled' ?>>▶ Запустить</button>
      <div class="muted" style="font-size:12px;margin-top:10px">
        Таймаут: <?= (int) Config::get('security.run_timeout', 120) ?> с · лимит вывода: <?= e(Runner::humanBytes((int) Config::get('security.max_output', 8388608))) ?> ·
        интерпретатор: <?= $venvReady ? 'venv проекта' : 'системный Python' ?>
      </div>
    </form>
    <form method="post" action="/projects/<?= (int) $project['id'] ?>/set-entry" class="row" style="margin-top:16px">
      <?= csrfField() ?>
      <select name="entrypoint" style="max-width:260px">
        <?php foreach ($files as $f): ?>
          <?php if (pathinfo($f['path'], PATHINFO_EXTENSION) !== 'py') continue; ?>
          <option value="<?= e($f['path']) ?>" <?= $f['path'] === $project['entrypoint'] ? 'selected' : '' ?>><?= e($f['path']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn sm" type="submit">Точка входа</button>
    </form>
  </section>
</div>

<section class="card">
  <div class="card head"><h2>Файлы проекта</h2><span class="muted">доступно 500 файлов</span></div>
  <?php if (!$files): ?><p class="muted">Файлов нет.</p><?php else: ?>
  <div class="filebar">
    <?php foreach ($files as $i => $f): ?>
      <button class="tab <?= $i === 0 ? 'active' : '' ?>" type="button"
              data-path="<?= e($f['path']) ?>"
              data-content="<?= e(ProjectManager::read($project, $f['path']) ?? '') ?>"><?= e($f['path']) ?></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <form method="post" action="/projects/<?= (int) $project['id'] ?>/file" id="save-file-form">
    <?= csrfField() ?>
    <input type="hidden" name="path" id="code-path" value="<?= e($files[0]['path'] ?? '') ?>">
    <textarea id="code-editor" class="editor" spellcheck="false" data-current="<?= e($files[0]['path'] ?? '') ?>"><?= e($files ? (ProjectManager::read($project, $files[0]['path']) ?? '') : '') ?></textarea>
    <div class="row" style="margin-top:10px">
      <button class="btn primary sm" type="submit">Сохранить файл (Ctrl+S)</button>
    </div>
  </form>
  <?php if ($files): ?>
  <form method="post" action="/projects/<?= (int) $project['id'] ?>/file-delete" data-confirm="Удалить выбранный файл?" class="row" style="margin-top:8px">
    <?= csrfField() ?><input type="hidden" name="path" id="delete-path" value="">
    <button class="btn danger sm" type="submit">Удалить текущий файл</button>
    <span class="muted" style="font-size:12px">перед удалением выберите файл вкладкой</span>
  </form>
  <?php endif; ?>
</section>

<section class="card" id="log">
  <div class="card head">
    <h2>Лог выполнения <?= $run ? '· запуск #' . (int) $run['id'] : '' ?></h2>
    <div class="row">
      <?php if ($run): ?><span class="badge <?= e($run['status']) ?>"><?= e($run['status']) ?></span><?php endif; ?>
      <button class="btn sm" type="button" id="copy-log">Копировать</button>
    </div>
  </div>
  <pre class="console" id="console"><?= $logHtml ?></pre>
</section>

<?php if ($runs): ?>
<section class="card">
  <h2>История проекта</h2>
  <table>
    <thead><tr><th>#</th><th>Команда</th><th>Статус</th><th>Начало</th><th>Вывод</th></tr></thead>
    <tbody>
    <?php foreach ($runs as $r): ?>
      <tr>
        <td class="mono"><a href="/projects/<?= (int) $project['id'] ?>?run=<?= (int) $r['id'] ?>#log">#<?= (int) $r['id'] ?></a></td>
        <td class="mono muted"><?= e(mb_strimwidth((string) $r['command'], 0, 55, '…')) ?></td>
        <td><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
        <td class="muted"><?= e(date('d.m H:i:s', (int) $r['started_at'])) ?></td>
        <td class="mono muted"><?= e(Runner::humanBytes((int) $r['bytes'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<section class="card danger-zone">
  <h2>Опасная зона</h2>
  <p class="muted" style="font-size:13px">Удаление проекта также удалит его venv и все логи запусков.</p>
  <form method="post" action="/projects/<?= (int) $project['id'] ?>/delete" data-confirm="Удалить проект «<?= e($project['name']) ?>» без возможности восстановления?">
    <?= csrfField() ?><button class="btn danger" type="submit">Удалить проект</button>
  </form>
</section>
