/*
 * Browser-side Python runner.
 *
 * Pyodide is CPython compiled to WebAssembly, so the project runs inside the
 * visitor's browser and the host needs no process execution at all. The virtual
 * environment mirrors the server-side flow: an isolated site-packages directory
 * that is first on sys.path, a manifest of installed distributions, and
 * installation from requirements.txt.
 */
(() => {
  'use strict';

  const root = document.getElementById('pyb');
  if (!root) return;

  const indexUrl = root.dataset.indexUrl;
  const sourceUrl = root.dataset.source;
  const projectId = root.dataset.project || '0';
  const entryFromServer = root.dataset.entry || '';
  const storeKey = 'pyhost.venv.' + projectId;

  const codeEl = document.getElementById('pyb-code');
  const runBtn = document.getElementById('pyb-run');
  const statusEl = document.getElementById('pyb-status');
  const outEl = document.getElementById('pyb-out');

  const venvStatusEl = document.getElementById('pyb-venv-status');
  const venvInfoEl = document.getElementById('pyb-venv-info');
  const venvCreateBtn = document.getElementById('pyb-venv-create');
  const venvRemoveBtn = document.getElementById('pyb-venv-remove');

  const reqFileEl = document.getElementById('pyb-req-file');
  const reqInputEl = document.getElementById('pyb-req-input');
  const reqBadgeEl = document.getElementById('pyb-req-badge');
  const reqInstallBtn = document.getElementById('pyb-req-install');
  const reqSaveBtn = document.getElementById('pyb-req-save');
  const requirementsUrl = root.dataset.requirements || '';

  let pyodide = null;
  let booting = null;
  let bundle = null;
  let venvPath = '';
  const installedThisSession = new Set();

  /* ---------- venv manifest (persisted in localStorage) ---------- */

  const state = { created: false, packages: [] };

  function loadState() {
    try {
      const raw = localStorage.getItem(storeKey);
      if (!raw) return;
      const data = JSON.parse(raw);
      state.created = !!data.created;
      state.packages = Array.isArray(data.packages) ? data.packages : [];
    } catch (err) {
      /* storage unavailable: the venv just will not survive a reload */
    }
  }

  function saveState() {
    try {
      localStorage.setItem(storeKey, JSON.stringify(state));
    } catch (err) {
      /* ignore quota / private mode */
    }
  }

  function renderVenv() {
    if (!venvStatusEl) return;
    venvStatusEl.textContent = state.created ? 'готово' : 'не создано';
    venvStatusEl.className = 'badge ' + (state.created ? 'ok' : 'warn');
    if (venvInfoEl) {
      venvInfoEl.textContent = state.created
        ? (state.packages.length ? state.packages.length + ' пакет(ов) в манифесте' : 'пакетов пока нет')
        : 'окружение ещё не создано';
    }
    if (venvRemoveBtn) venvRemoveBtn.disabled = !state.created;
  }

  /* ---------- helpers ---------- */

  function setStatus(text, cls) {
    statusEl.textContent = text;
    statusEl.className = 'badge' + (cls ? ' ' + cls : '');
  }

  function write(text, cls) {
    const line = document.createElement('span');
    if (cls) line.className = cls;
    line.textContent = String(text).replace(/\n$/, '') + '\n';
    outEl.appendChild(line);
    outEl.scrollTop = outEl.scrollHeight;
  }

  function clearOut() {
    outEl.textContent = '';
  }

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const el = document.createElement('script');
      el.src = src;
      el.async = true;
      el.onload = resolve;
      el.onerror = () => reject(new Error('Не удалось загрузить pyodide.js с CDN. Проверьте доступ к ' + src));
      document.head.appendChild(el);
    });
  }

  async function boot() {
    if (pyodide) return pyodide;
    if (booting) return booting;
    booting = (async () => {
      setStatus('загрузка интерпретатора…', 'warn');
      await loadScript(indexUrl + 'pyodide.js');
      if (typeof window.loadPyodide !== 'function') throw new Error('loadPyodide не найден в pyodide.js');
      const py = await window.loadPyodide({ indexURL: indexUrl });
      py.setStdout({ batched: (t) => write(t) });
      py.setStderr({ batched: (t) => write(t, 'stderr') });
      pyodide = py;
      const version = py.runPython('import sys; sys.version.split()[0]');
      venvPath = py.runPython("import sys; '/venv/lib/python%d.%d/site-packages' % sys.version_info[:2]");
      setStatus('Python ' + version + ' готов', 'ok');
      return py;
    })();
    try {
      return await booting;
    } catch (err) {
      booting = null;
      setStatus('ошибка загрузки', 'fail');
      throw err;
    }
  }

  async function loadBundle() {
    if (bundle) return bundle;
    const res = await fetch(sourceUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!res.ok) throw new Error('Не удалось получить файлы проекта (HTTP ' + res.status + ')');
    bundle = await res.json();
    return bundle;
  }

  function bundleFile(name) {
    if (!bundle) return null;
    const want = String(name).replace(/^\.\//, '').replace(/^\/+/, '');
    const hit = (bundle.files || []).find((f) => f.path === want);
    return hit ? hit.content : null;
  }

  async function mountProject(py) {
    const data = await loadBundle();
    py.FS.mkdirTree('/project');
    for (const file of data.files || []) {
      const parts = String(file.path).split('/').filter(Boolean);
      let dir = '/project';
      for (let i = 0; i < parts.length - 1; i += 1) {
        dir += '/' + parts[i];
        if (!py.FS.analyzePath(dir).exists) py.FS.mkdir(dir);
      }
      py.FS.writeFile('/project/' + file.path, file.content);
    }
    return data;
  }

  /* ---------- requirements parsing ---------- */

  const SKIP_PREFIXES = ['-e', '--editable', '--index-url', '-i', '--extra-index-url', '--find-links', '-f', '--trusted-host', '--no-index', '--pre'];

  function parseRequirements(text, depth) {
    const out = [];
    const skipped = [];
    const level = depth || 0;
    if (level > 2) return { list: out, skipped: ['вложенность -r больше 2 уровней'] };

    for (const rawLine of String(text).split(/\r?\n/)) {
      const line = rawLine.replace(/\s+#.*$/, '').trim();
      if (line === '' || line.startsWith('#')) continue;

      const lower = line.toLowerCase();
      if (lower.startsWith('-r ') || lower.startsWith('--requirement ')) {
        const ref = line.split(/\s+/)[1];
        const nested = ref ? bundleFile(ref) : null;
        if (nested === null) {
          skipped.push(ref + ' (файл не найден в проекте)');
        } else {
          const sub = parseRequirements(nested, level + 1);
          out.push(...sub.list);
          skipped.push(...sub.skipped);
        }
        continue;
      }
      if (SKIP_PREFIXES.some((p) => lower === p || lower.startsWith(p + ' '))) {
        skipped.push(line + ' (опция pip не поддерживается в браузере)');
        continue;
      }
      if (/^(git\+|hg\+|svn\+|bzr\+|https?:\/\/|file:|\/|\.)/i.test(line)) {
        skipped.push(line + ' (нужен доступ к внешнему источнику — в браузере недоступно)');
        continue;
      }
      out.push(line);
    }
    return { list: out, skipped };
  }

  function baseName(spec) {
    const m = String(spec).match(/^([A-Za-z0-9][A-Za-z0-9._-]*)/);
    return m ? m[1] : '';
  }

  function renderRequirements() {
    if (!reqInputEl) return;
    const content = bundleFile('requirements.txt');
    if (content === null) {
      reqFileEl.textContent = 'requirements.txt в проекте нет — впишите пакеты вручную';
      if (reqBadgeEl) {
        reqBadgeEl.textContent = 'файла нет';
        reqBadgeEl.className = 'badge warn';
      }
      reqSaveBtn.disabled = false;
      reqInstallBtn.disabled = false;
      return;
    }
    const parsed = parseRequirements(content, 0);
    reqInputEl.value = content.trim();
    reqFileEl.textContent = 'requirements.txt · ' + parsed.list.length + ' зависимост(ей)';
    if (reqBadgeEl) {
      reqBadgeEl.textContent = parsed.list.length ? 'из файла' : 'пусто';
      reqBadgeEl.className = 'badge ' + (parsed.list.length ? 'ok' : 'warn');
    }
    reqInstallBtn.disabled = false;
    reqSaveBtn.disabled = false;
  }

  /* ---------- venv ---------- */

  async function ensureVenv(py, silent) {
    if (!state.created) {
      if (!silent) throw new Error('Сначала создайте виртуальное окружение.');
      return;
    }
    py.FS.mkdirTree(venvPath);
    const cfg = '/venv/pyvenv.cfg';
    if (!py.FS.analyzePath(cfg).exists) {
      const version = py.runPython('import sys; "%d.%d.%d" % sys.version_info[:3]');
      py.FS.writeFile(cfg, 'home = /usr/local/bin\ninclude-system-site-packages = true\nversion = ' + version + '\n');
    }
    py.FS.writeFile('/venv/manifest.json', JSON.stringify(state, null, 2));
  }

  async function createVenv() {
    venvCreateBtn.disabled = true;
    try {
      const py = await boot();
      await loadBundle();
      state.created = true;
      await ensureVenv(py);
      renderVenv();
      write('venv: создан ' + venvPath, 'muted');
      setStatus('venv готов', 'ok');
    } catch (err) {
      write(err && err.message ? err.message : String(err), 'stderr');
      setStatus('ошибка', 'fail');
    } finally {
      venvCreateBtn.disabled = false;
    }
  }

  async function removeVenv() {
    venvRemoveBtn.disabled = true;
    try {
      state.created = false;
      state.packages = [];
      installedThisSession.clear();
      saveState();
      if (pyodide) {
        try {
          pyodide.runPython("import shutil\nshutil.rmtree('/venv', ignore_errors=True)");
        } catch (err) {
          /* the directory is not critical: a new venv recreates it */
        }
      }
      write('venv: удалён (пакеты останутся в памяти вкладки до перезагрузки)', 'muted');
      setStatus('venv удалён', 'warn');
    } catch (err) {
      write(err && err.message ? err.message : String(err), 'stderr');
    } finally {
      renderVenv();
      venvRemoveBtn.disabled = !state.created;
    }
  }

  /* ---------- installation ---------- */

  async function installSpecs(specs, label) {
    const py = await boot();
    if (!state.created) {
      state.created = true;
      await ensureVenv(py);
      renderVenv();
    }
    await py.loadPackage('micropip');
    const micropip = py.pyimport('micropip');

    const ok = [];
    for (const spec of specs) {
      const name = baseName(spec);
      const key = name ? name.toLowerCase() : '';

      // Skip what the interpreter already provides (an earlier install may have
      // pulled it in as a dependency). Honour an exact pin: only skip on a match.
      if (name) {
        const pin = String(spec).match(/^[A-Za-z0-9][A-Za-z0-9._-]*(?:\[[^\]]*\])?\s*===?\s*([^\s,;]+)/);
        const current = await resolveVersions(py, [name]);
        if (current[name] && (!pin || current[name] === pin[1])) {
          installedThisSession.add(key);
          write('уже установлено: ' + spec + ' (' + current[name] + ')', 'muted');
          ok.push(name);
          continue;
        }
      }

      write((label || 'micropip') + ' install ' + spec);
      try {
        await micropip.install(spec, true);
        ok.push(name || spec);
        if (key) installedThisSession.add(key);
      } catch (err) {
        write('не удалось: ' + spec + ' — ' + (err && err.message ? err.message : String(err)), 'stderr');
      }
    }

    if (ok.length) {
      const versions = await resolveVersions(py, ok);
      for (const name of ok) {
        const version = versions[name] || '';
        const entry = version ? name + '==' + version : name;
        if (!state.packages.includes(entry)) state.packages.push(entry);
      }
      state.packages.sort();
      saveState();
      await ensureVenv(py);
      renderVenv();
    }
    return ok.length;
  }

  async function resolveVersions(py, names) {
    const clean = names.map((n) => String(n).replace(/["\\]/g, '')).filter(Boolean);
    if (!clean.length) return {};
    py.globals.set('_pyhost_names', JSON.stringify(clean));
    const json = py.runPython(
      'import json, importlib.metadata as md\n' +
      '_names = json.loads(_pyhost_names)\n' +
      '_out = {}\n' +
      'for _n in _names:\n' +
      '    try:\n' +
      '        _out[_n] = md.version(_n)\n' +
      '    except Exception:\n' +
      '        _out[_n] = ""\n' +
      'json.dumps(_out)\n'
    );
    return JSON.parse(json);
  }

  async function installRequirements() {
    reqInstallBtn.disabled = true;
    clearOut();
    try {
      await boot();
      await loadBundle();
      const text = reqInputEl.value.trim();
      if (!text) {
        write('Список зависимостей пуст — впишите пакеты в поле выше.', 'muted');
        setStatus('нечего ставить', 'warn');
        return;
      }
      const parsed = parseRequirements(text, 0);
      for (const note of parsed.skipped) write('пропущено: ' + note, 'muted');
      if (!parsed.list.length) {
        write('В списке нет ни одного поддерживаемого пакета.', 'muted');
        setStatus('нечего ставить', 'warn');
        return;
      }
      setStatus('установка зависимостей…', 'warn');
      const count = await installSpecs(parsed.list, 'requirements');
      write('— обработано зависимостей: ' + count + ' из ' + parsed.list.length, 'muted');
      setStatus('зависимости установлены', 'ok');
    } catch (err) {
      write(err && err.message ? err.message : String(err), 'stderr');
      setStatus('ошибка установки', 'fail');
    } finally {
      reqInstallBtn.disabled = false;
    }
  }

  /** Writes the textarea back to requirements.txt in the project (JSON, CSRF-protected). */
  async function saveRequirements() {
    if (!requirementsUrl) return;
    reqSaveBtn.disabled = true;
    try {
      const meta = document.querySelector('meta[name="csrf-token"]');
      const token = meta ? meta.getAttribute('content') : '';
      const body = new FormData();
      body.append('_csrf', token || '');
      body.append('requirements', reqInputEl.value);
      const res = await fetch(requirementsUrl, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) throw new Error('Сервер ответил HTTP ' + res.status);
      const data = await res.json();
      if (!data.ok) throw new Error('Не удалось сохранить файл');
      write('requirements.txt сохранён в проекте (' + data.bytes + ' байт)', 'muted');
      if (bundle) {
        const entry = (bundle.files || []).find((f) => f.path === 'requirements.txt');
        if (entry) entry.content = reqInputEl.value;
        else bundle.files.push({ path: 'requirements.txt', content: reqInputEl.value });
      }
      setStatus('requirements.txt сохранён', 'ok');
    } catch (err) {
      write(err && err.message ? err.message : String(err), 'stderr');
      setStatus('ошибка сохранения', 'fail');
    } finally {
      reqSaveBtn.disabled = false;
    }
  }

  /** Reinstalls the manifest after a page reload so the venv behaves persistently. */
  async function restoreVenv(py) {
    if (!state.created) return;
    await ensureVenv(py);
    const names = state.packages.map(baseName).filter(Boolean);
    if (!names.length) return;
    // Ask the interpreter what is already present: dependencies pulled in by an
    // earlier install are importable even though they are not in the manifest.
    const versions = await resolveVersions(py, names);
    const missing = state.packages.filter((spec) => {
      const name = baseName(spec);
      if (!name) return false;
      const key = name.toLowerCase();
      if (installedThisSession.has(key)) return false;
      if (versions[name]) {
        installedThisSession.add(key);
        return false;
      }
      return true;
    });
    if (!missing.length) return;
    write('восстановление venv: ' + missing.join(', '), 'muted');
    await installSpecs(missing, 'restore');
  }

  /* ---------- run ---------- */

  async function run() {
    runBtn.disabled = true;
    clearOut();
    try {
      const py = await boot();
      const data = await mountProject(py);
      await restoreVenv(py);

      const entry = entryFromServer || data.entry || '';
      if (!entry) throw new Error('У проекта не задана точка входа. Укажите её на странице проекта.');
      py.FS.writeFile('/project/' + entry, codeEl.value);
      if (data.skipped) write('// пропущено файлов: ' + data.skipped, 'muted');

      if (!state.packages.length) {
        const parsed = parseRequirements(reqInputEl.value || '', 0);
        if (parsed.list.length) {
          write('// в зависимостях ' + parsed.list.length + ' пакет(ов) — нажмите «Установить», если импорты не находятся', 'muted');
        }
      }

      setStatus('выполнение…', 'warn');
      const started = performance.now();
      await py.runPythonAsync(
        'import os, sys, runpy\n' +
        '_venv = ' + JSON.stringify(venvPath) + '\n' +
        'if os.path.isdir(_venv) and _venv not in sys.path: sys.path.insert(0, _venv)\n' +
        'if "/project" not in sys.path: sys.path.insert(0, "/project")\n' +
        'os.chdir("/project")\n' +
        'runpy.run_path(' + JSON.stringify(entry) + ', run_name="__main__")\n'
      );
      write('— завершено за ' + Math.round(performance.now() - started) + ' мс', 'muted');
      setStatus('готово', 'ok');
    } catch (err) {
      const message = err && err.message ? err.message : String(err);
      write(message, 'stderr');
      if (/Failed to fetch|ConnectionError|Connection aborted|NetworkError|CORS/i.test(message)) {
        write(
          'Браузерный режим не может обращаться к внешним сайтам напрямую: нужен CORS от целевого сервера, ' +
          'а CSP панели разрешает только свой домен, CDN и хосты из настройки security.pyodide_connect_hosts. ' +
          'Для своего API добавьте его origin в эту настройку и включите на нём CORS; серверный режим ' +
          '(нужен proc_open) таких ограничений не имеет.',
          'muted'
        );
      }
      setStatus('ошибка', 'fail');
    } finally {
      runBtn.disabled = false;
    }
  }

  /* ---------- wiring ---------- */

  loadState();
  renderVenv();

  (async () => {
    try {
      await loadBundle();
      renderRequirements();
    } catch (err) {
      if (reqFileEl) reqFileEl.textContent = 'не удалось прочитать файлы проекта';
    }
  })();

  runBtn.addEventListener('click', run);
  venvCreateBtn.addEventListener('click', createVenv);
  venvRemoveBtn.addEventListener('click', removeVenv);
  reqInstallBtn.addEventListener('click', installRequirements);
  reqSaveBtn.addEventListener('click', saveRequirements);

  codeEl.addEventListener('keydown', (event) => {
    if (event.key === 'Tab') {
      event.preventDefault();
      const start = codeEl.selectionStart;
      codeEl.value = codeEl.value.slice(0, start) + '    ' + codeEl.value.slice(codeEl.selectionEnd);
      codeEl.selectionStart = codeEl.selectionEnd = start + 4;
    }
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
      event.preventDefault();
      run();
    }
  });
})();
