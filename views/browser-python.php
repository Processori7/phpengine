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
  <code class="inline">proc_open</code>/<code class="inline">exec</code>. Окружение работает как на сервере:
  сначала venv, затем <code class="inline">requirements.txt</code>, затем запуск. Файлы проекта попадают только
  в виртуальную файловую систему браузера. Ограничения: пакеты с C-расширениями работают лишь при наличии
  сборки WebAssembly, а долгие задачи ограничены памятью вкладки.
</div>

<div
  id="pyb"
  data-project="<?= (int) $project['id'] ?>"
  data-source="/projects/<?= (int) $project['id'] ?>/source"
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
        Список установленных пакетов запоминается в браузере и восстанавливается при следующем открытии страницы.
      </p>
      <div class="muted mono" style="font-size:12px;margin-bottom:12px" id="pyb-venv-info">окружение ещё не создано</div>
      <div class="row">
        <button class="btn primary sm" id="pyb-venv-create" type="button">Создать venv</button>
        <button class="btn danger sm" id="pyb-venv-remove" type="button" disabled>Удалить</button>
      </div>
    </section>

    <section class="card">
      <div class="card head"><h2>Зависимости</h2></div>
      <p class="muted" style="font-size:13px;margin-top:0" id="pyb-req-file">читаю файлы проекта…</p>
      <pre class="mono" id="pyb-req-list" style="max-height:150px;overflow:auto;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px;font-size:12px;margin:0 0 12px"></pre>
      <div class="row">
        <button class="btn primary sm" id="pyb-req-install" type="button" disabled>Установить из requirements.txt</button>
      </div>
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

  <section class="card">
    <h2>Пакеты вручную</h2>
    <p class="muted" style="font-size:13px;margin-top:0">Ставится в текущее venv через micropip. Пакеты с C-расширениями работают, только если для них есть сборка WebAssembly.</p>
    <div class="row">
      <input id="pyb-pip" type="text" class="mono" placeholder="requests, rich, python-dateutil" style="max-width:420px">
      <button class="btn" id="pyb-pip-install" type="button">Установить</button>
    </div>
  </section>
</div>
