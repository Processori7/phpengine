<div class="row" style="justify-content:space-between;margin-bottom:20px">
  <div>
    <h1><?= e($project['name']) ?></h1>
    <div class="muted mono" style="font-size:12px">запуск в браузере · точка входа: <?= e($entry !== '' ? $entry : 'не задана') ?></div>
  </div>
  <div class="row">
    <a class="btn" href="/projects/<?= (int) $project['id'] ?>">← К проекту</a>
  </div>
</div>

<div class="flash info" style="margin-bottom:16px">
  Код выполняется <b>в вашем браузере</b>: CPython собран в WebAssembly (Pyodide), поэтому хостингу не нужны
  <code class="inline">proc_open</code>/<code class="inline">exec</code>. Порядок как на сервере: venv → зависимости → запуск.
  Файлы проекта попадают только в виртуальную файловую систему браузера.
</div>

<div
  id="pyb"
  data-project="<?= (int) $project['id'] ?>"
  data-source="/projects/<?= (int) $project['id'] ?>/source"
  data-requirements="/projects/<?= (int) $project['id'] ?>/requirements"
  data-index-url="<?= e($indexUrl) ?>"
  data-entry="<?= e($entry) ?>"
>
  <div class="grid cols-2">
    <section class="card">
      <div class="card head">
        <h2>Окружение (venv)</h2>
        <span class="badge warn" id="pyb-venv-status">не создано</span>
      </div>
      <p class="muted" style="font-size:13px;margin-top:0">
        Изолированный <code class="inline">site-packages</code> в виртуальной ФС, первым в <code class="inline">sys.path</code>.
        Список пакетов запоминается в браузере и восстанавливается при следующем открытии страницы.
      </p>
      <div class="muted mono" style="font-size:12px;margin-bottom:12px" id="pyb-venv-info">окружение ещё не создано</div>
      <div class="row">
        <button class="btn primary sm" id="pyb-venv-create" type="button">Создать venv</button>
        <button class="btn danger sm" id="pyb-venv-remove" type="button" disabled>Удалить</button>
      </div>
    </section>

    <section class="card">
      <div class="card head">
        <h2>Зависимости</h2>
        <span class="badge" id="pyb-req-badge">—</span>
      </div>
      <p class="muted" style="font-size:13px;margin-top:0" id="pyb-req-file">читаю файлы проекта…</p>
      <textarea id="pyb-req-input" class="mono" spellcheck="false" placeholder="requests&#10;python-dateutil==2.9.0" style="width:100%;min-height:120px;resize:vertical;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:10px;font-size:12.5px;line-height:1.5"></textarea>
      <div class="row" style="margin-top:12px">
        <button class="btn primary sm" id="pyb-req-install" type="button">Установить</button>
        <button class="btn sm" id="pyb-req-save" type="button">Сохранить в requirements.txt</button>
      </div>
      <p class="muted" style="font-size:12px;margin:10px 0 0">
        По одному пакету в строке, как в requirements.txt. Опции pip (<code class="inline">-e</code>,
        <code class="inline">git+…</code>, <code class="inline">--index-url</code>) в браузере не поддерживаются.
      </p>
    </section>
  </div>

  <section class="card">
    <div class="card head">
      <h2>Код</h2>
      <span class="badge" id="pyb-status">не запущено</span>
    </div>
    <textarea id="pyb-code" class="mono" spellcheck="false" style="width:100%;min-height:320px;resize:vertical;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:12px;font-size:13px;line-height:1.5"><?= e($code) ?></textarea>
    <div class="row" style="margin-top:14px">
      <button class="btn primary" id="pyb-run" type="button">Запустить в браузере</button>
      <span class="muted" style="font-size:12px">Первый запуск скачивает интерпретатор (~10 МБ) с CDN jsDelivr.</span>
    </div>
  </section>

  <section class="card">
    <div class="card head"><h2>Вывод</h2></div>
    <pre id="pyb-out" class="mono" style="min-height:180px;max-height:460px;overflow:auto;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px;font-size:12.5px;line-height:1.55;margin:0"></pre>
  </section>
</div>
