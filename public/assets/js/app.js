(() => {
  'use strict';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  // Destructive actions need an explicit confirmation.
  $$('form[data-confirm]').forEach(f => f.addEventListener('submit', e => {
    if (!confirm(f.dataset.confirm || 'Вы уверены?')) e.preventDefault();
  }));

  // Buttons with a loading state.
  $$('form[data-loading]').forEach(f => f.addEventListener('submit', () => {
    const btn = $('button[type=submit]', f);
    if (btn) { btn.disabled = true; btn.dataset.original = btn.innerHTML; btn.innerHTML = '<span class="spinner"></span> Работаю…'; }
  }));

  // ---------- Upload: drag & drop, including folders ----------
  $('#pick-folder')?.addEventListener('click', () => {
    const input = $('#files-input');
    if (!input) return;
    input.webkitdirectory = true;
    input.multiple = true;
    input.click();
  });
  $('#pick-files')?.addEventListener('click', () => {
    const input = $('#files-input');
    if (!input) return;
    input.webkitdirectory = false;
    input.multiple = true;
    input.click();
  });

  const zone = $('#dropzone');
  const input = $('#files-input');
  const pathsField = $('#file_paths');
  const list = $('#selected-files');
  if (zone && input) {
    const store = [];
    const render = () => {
      list.innerHTML = '';
      store.forEach((f, i) => {
        const li = document.createElement('div');
        li.className = 'mono muted';
        li.style.fontSize = '12px';
        li.textContent = (i + 1) + '. ' + (f.webkitRelativePath || f.name) + ' — ' + Math.ceil(f.size / 1024) + ' KB';
        list.appendChild(li);
      });
      if (pathsField) pathsField.value = JSON.stringify(store.map(f => f.webkitRelativePath || f.name));
      // Rebuild the FileList so the browser submits everything we collected.
      const dt = new DataTransfer();
      store.forEach(f => dt.items.add(f));
      input.files = dt.files;
    };
    const add = files => {
      for (const f of files) {
        if (!store.some(x => (x.webkitRelativePath || x.name) === (f.webkitRelativePath || f.name))) store.push(f);
      }
      render();
    };
    input.addEventListener('change', () => add(Array.from(input.files)));

    ['dragenter', 'dragover'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.remove('drag'); }));
    zone.addEventListener('drop', e => {
      const items = Array.from(e.dataTransfer.items || []);
      if (!items.length) { add(Array.from(e.dataTransfer.files)); return; }
      const queue = [];
      items.forEach(item => {
        const entry = item.webkitGetAsEntry?.();
        if (entry) queue.push(entry);
      });
      if (!queue.length) { add(Array.from(e.dataTransfer.files)); return; }
      const found = [];
      const readDir = (reader, prefix) => new Promise(resolve => {
        const batch = [];
        const step = () => {
          reader.readEntries(entries => {
            if (!entries.length) return resolve(batch);
            entries.forEach(en => {
              if (en.isFile) en.file(f => { f.webkitRelativePath = prefix + en.name; batch.push(f); });
              else if (en.isDirectory) queue.push({ isDirectory: true, name: prefix + en.name, createReader: () => en.createReader() });
            });
            step();
          });
        };
        step();
      });
      const walk = async () => {
        while (queue.length) {
          const entry = queue.shift();
          if (entry.isFile) {
            await new Promise(res => entry.file(f => { f.webkitRelativePath = entry.fullPath.replace(/^\//, ''); found.push(f); res(); }));
          } else {
            found.push(...await readDir(entry.createReader(), entry.name + '/'));
          }
        }
        add(found);
      };
      walk();
    });
  }

  // ---------- File editor tabs ----------
  const editor = $('#code-editor');
  const pathInput = $('#code-path');
  $$('.tab').forEach(tab => tab.addEventListener('click', () => {
    if (editor && pathInput) {
      if (editor.dataset.current && editor.dataset.current !== tab.dataset.path) {
        const slot = document.getElementById('buf-' + btoa(unescape(encodeURIComponent(editor.dataset.current))).replace(/[^a-zA-Z0-9]/g, ''));
        if (slot) slot.value = editor.value;
      }
      editor.value = tab.dataset.content || '';
      editor.dataset.current = tab.dataset.path;
      pathInput.value = tab.dataset.path;
    }
    const del = $('#delete-path');
    if (del) del.value = tab.dataset.path;
    $$('.tab').forEach(t => t.classList.toggle('active', t === tab));
  }));
  if (editor && pathInput && !editor.dataset.current) {
    editor.addEventListener('input', () => {
      const id = 'buf-' + btoa(unescape(encodeURIComponent(editor.dataset.current || ''))).replace(/[^a-zA-Z0-9]/g, '');
      let slot = document.getElementById(id);
      if (!slot) { slot = document.createElement('textarea'); slot.id = id; slot.hidden = true; document.body.appendChild(slot); }
      slot.value = editor.value;
    });
  }

  // ---------- Console helpers ----------
  const consoleEl = $('#console');
  if (consoleEl) consoleEl.scrollTop = consoleEl.scrollHeight;
  const copyBtn = $('#copy-log');
  copyBtn?.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(consoleEl?.innerText || ''); copyBtn.textContent = 'Скопировано'; }
    catch { copyBtn.textContent = 'Не удалось'; }
    setTimeout(() => copyBtn.textContent = 'Копировать', 1500);
  });

  // Keyboard shortcut: Ctrl+S inside the editor saves the file.
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's' && document.activeElement === editor) {
      e.preventDefault();
      $('#save-file-form')?.requestSubmit();
    }
  });

  // Command presets
  $$('[data-command]').forEach(el => el.addEventListener('click', () => {
    const cmd = $('#command-input');
    if (cmd) { cmd.value = el.dataset.command; cmd.focus(); }
  }));
})();
