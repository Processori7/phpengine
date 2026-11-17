<div class="row" style="justify-content:space-between;margin-bottom:20px">
  <div><h1>Проекты</h1><div class="muted">Загрузка, зависимости и запуск Python-кода</div></div>
</div>

<?php if (Config::get('security.execution_enabled', true)): ?>
<section class="card">
  <h2>Добавить проект</h2>
  <form method="post" action="/projects/upload" enctype="multipart/form-data" data-loading>
    <?= csrfField() ?>
    <div class="grid cols-2">
      <div>
        <div class="field">
          <label for="name">Название *</label>
          <input id="name" name="name" type="text" required placeholder="Мой скрипт" maxlength="100">
        </div>
        <div class="field">
          <label for="entrypoint">Точка входа (необязательно)</label>
          <input id="entrypoint" name="entrypoint" type="text" placeholder="main.py — определится автоматически">
        </div>
        <div class="field">
          <label for="single_file">Один .py-файл</label>
          <input id="single_file" name="single_file" type="file" accept=".py,.txt,.json,.yaml,.yml,.toml,.csv,.md">
        </div>
        <div class="field">
          <label for="archive">Или ZIP-архив проекта</label>
          <input id="archive" name="archive" type="file" accept=".zip">
        </div>
      </div>
      <div>
        <label>Или перетащите файлы / папку сюда</label>
        <div class="dropzone" id="dropzone">
          <div class="big">⇪</div>
          <div>Файлы и целые папки</div>
          <div class="muted" style="font-size:12px">до <?= e(Runner::humanBytes((int) Config::get('security.max_upload', 26214400))) ?> · минимум 1 файл</div>
          <input id="files-input" name="files[]" type="file" multiple webkitdirectory style="display:none">
        </div>
        <div id="selected-files" style="margin-top:10px;max-height:100px;overflow:auto"></div>
        <input type="hidden" name="file_paths" id="file_paths" value="">
        <div class="row" style="margin-top:12px">
          <button class="btn sm" type="button" id="pick-folder">Выбрать папку</button>
          <button class="btn sm" type="button" id="pick-files">Выбрать файлы</button>
        </div>
      </div>
    </div>
    <div class="field">
      <label for="code_name">Или создайте файл прямо в браузере</label>
      <div class="row">
        <input id="code_name" name="code_name" type="text" placeholder="main.py" style="max-width:220px">
        <input name="code_body" type="hidden" value="print('Hello from PyHost!')">
        <span class="muted" style="font-size:12px">Предзаполнено: <code class="inline">print('Hello from PyHost!')</code></span>
      </div>
    </div>
    <button class="btn primary" type="submit">Загрузить проект</button>
  </form>
</section>
<?php else: ?>
  <div class="flash error">Загрузка отключена: выполнение кода запрещено в настройках.</div>
<?php endif; ?>

<section class="card">
  <h2>Ваши проекты (<?= count($projects) ?>)</h2>
  <?php if (!$projects): ?>
    <p class="muted">Проектов пока нет. Загрузите первый выше.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Название</th><th>Точка входа</th><th>Файлов</th><th>Обновлён</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($projects as $p): ?>
        <tr>
          <td><a href="/projects/<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a><div class="muted mono" style="font-size:11px"><?= e($p['slug']) ?></div></td>
          <td class="mono muted"><?= e($p['entrypoint']) ?></td>
          <td><?= count(ProjectManager::files($p)) ?></td>
          <td class="muted"><?= e(date('d.m.Y H:i', (int) $p['updated_at'])) ?></td>
          <td><a class="btn sm" href="/projects/<?= (int) $p['id'] ?>">Открыть</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
