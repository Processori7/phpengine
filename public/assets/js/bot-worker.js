/*
 * Bot worker: runs a project as a long-lived process inside a Web Worker.
 * Long polling in a worker never freezes the panel UI, and terminating the
 * worker stops the bot immediately.
 */
'use strict';

let pyodide = null;

function post(type, text) {
  self.postMessage({ type: type, text: text == null ? '' : String(text) });
}

self.onmessage = async (event) => {
  const msg = event.data || {};
  if (msg.type !== 'start') return;

  try {
    self.importScripts(msg.indexUrl + 'pyodide.js');
    const py = await self.loadPyodide({ indexURL: msg.indexUrl });
    py.setStdout({ batched: (t) => post('out', t) });
    py.setStderr({ batched: (t) => post('err', t) });

    py.FS.mkdirTree('/project');
    for (const file of msg.files || []) {
      const parts = String(file.path).split('/').filter(Boolean);
      let dir = '/project';
      for (let i = 0; i < parts.length - 1; i += 1) {
        dir += '/' + parts[i];
        if (!py.FS.analyzePath(dir).exists) py.FS.mkdir(dir);
      }
      py.FS.writeFile('/project/' + file.path, file.content);
    }

    py.FS.writeFile('/project/' + msg.entry, msg.code);
    if (msg.helper) py.FS.writeFile('/project/pyhost_bot.py', msg.helper);

    if (msg.packages && msg.packages.length) {
      await py.loadPackage('micropip');
      const micropip = py.pyimport('micropip');
      for (const spec of msg.packages) {
        post('out', 'pip install ' + spec);
        try {
          await micropip.install(spec, true);
        } catch (err) {
          post('err', 'не удалось поставить ' + spec + ': ' + (err && err.message ? err.message : err));
        }
      }
    }

    post('status', 'бот запущен');
    await py.runPythonAsync(
      'import os, sys, runpy\n' +
      'os.chdir("/project")\n' +
      'if "/project" not in sys.path: sys.path.insert(0, "/project")\n' +
      'runpy.run_path(' + JSON.stringify(msg.entry) + ', run_name="__main__")\n'
    );
    post('done', 'скрипт завершился');
  } catch (err) {
    post('error', err && err.message ? err.message : String(err));
  }
};
