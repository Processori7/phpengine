<?php
  $logHtml = '';
  foreach (preg_split('/\R/u', (string) $log) ?: [] as $line) {
      $cls = str_starts_with($line, '[stderr]') ? ' class="stderr"' : '';
      $logHtml .= '<span' . $cls . '>' . e($line) . "</span>\n";
  }
?>
<div class="row" style="justify-content:space-between;margin-bottom:20px">
  <div><h1>Запуск #<?= (int) $run['id'] ?></h1><div class="muted"><?= e($run['project_name'] ?? '') ?></div></div>
  <a class="btn" href="/runs">← История</a>
</div>
<section class="card">
  <table>
    <tr><td class="muted">Команда</td><td class="mono"><?= e($run['command']) ?></td></tr>
    <tr><td class="muted">Рабочая папка</td><td class="mono"><?= e($run['cwd']) ?></td></tr>
    <tr><td class="muted">Статус</td><td><span class="badge <?= e($run['status']) ?>"><?= e($run['status']) ?></span></td></tr>
    <tr><td class="muted">Код выхода</td><td class="mono"><?= e($run['exit_code'] ?? '—') ?></td></tr>
    <tr><td class="muted">Начало</td><td><?= e(date('d.m.Y H:i:s', (int) $run['started_at'])) ?></td></tr>
    <tr><td class="muted">Завершение</td><td><?= $run['finished_at'] ? e(date('d.m.Y H:i:s', (int) $run['finished_at'])) : '—' ?></td></tr>
  </table>
</section>
<section class="card">
  <div class="card head"><h2>Лог</h2><button class="btn sm" type="button" id="copy-log">Копировать</button></div>
  <pre class="console" id="console"><?= $logHtml ?></pre>
</section>
