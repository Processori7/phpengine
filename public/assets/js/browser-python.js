/*
 * Runs project code with Pyodide (CPython in WebAssembly) entirely inside the
 * browser. No server process is involved, so this works even when the host
 * disables proc_open/exec/shell_exec/popen/system/passthru.
 */
(() => {
  'use strict';

  const root = document.getElementById('pyb');
  if (!root) return;

  const indexUrl = root.dataset.indexUrl;
  const sourceUrl = root.dataset.source;
  const entryFromServer = root.dataset.entry || '';

  const codeEl = document.getElementById('pyb-code');
  const runBtn = document.getElementById('pyb-run');
  const statusEl = document.getElementById('pyb-status');
  const outEl = document.getElementById('pyb-out');
  const pipEl = document.getElementById('pyb-pip');
  const pipBtn = document.getElementById('pyb-pip-install');

  let pyodide = null;
  let booting = null;

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

  async function mountProject(py) {
    const res = await fetch(sourceUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!res.ok) throw new Error('Не удалось получить файлы проекта (HTTP ' + res.status + ')');
    const data = await res.json();

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

  async function run() {
    runBtn.disabled = true;
    clearOut();
    try {
      const py = await boot();
      const data = await mountProject(py);
      const entry = entryFromServer || data.entry || '';
      if (!entry) throw new Error('У проекта не задана точка входа. Укажите её на странице проекта.');
      py.FS.writeFile('/project/' + entry, codeEl.value);

      if (data.skipped) write('// пропущено файлов: ' + data.skipped, 'muted');

      setStatus('выполнение…', 'warn');
      const started = performance.now();
      await py.runPythonAsync(
        'import os, sys, runpy\n' +
        'os.chdir("/project")\n' +
        'if "/project" not in sys.path: sys.path.insert(0, "/project")\n' +
        'runpy.run_path(' + JSON.stringify(entry) + ', run_name="__main__")\n'
      );
      write('— завершено за ' + Math.round(performance.now() - started) + ' мс', 'muted');
      setStatus('готово', 'ok');
    } catch (err) {
      const message = err && err.message ? err.message : String(err);
      write(message, 'stderr');
      setStatus('ошибка', 'fail');
    } finally {
      runBtn.disabled = false;
    }
  }

  async function installPackages() {
    const names = pipEl.value.split(/[\s,]+/).filter(Boolean);
    if (names.length === 0) return;
    pipBtn.disabled = true;
    try {
      const py = await boot();
      await py.loadPackage('micropip');
      const micropip = py.pyimport('micropip');
      for (const name of names) {
        write('micropip install ' + name);
        await micropip.install(name);
      }
      write('— установлено: ' + names.join(', '), 'muted');
      setStatus('пакеты установлены', 'ok');
    } catch (err) {
      write(err && err.message ? err.message : String(err), 'stderr');
      setStatus('ошибка установки', 'fail');
    } finally {
      pipBtn.disabled = false;
    }
  }

  runBtn.addEventListener('click', run);
  pipBtn.addEventListener('click', installPackages);
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
