<div class="row" style="justify-content:space-between;margin-bottom:20px">
  <div><h1>Обзор</h1><div class="muted">Состояние движка и последние запуски</div></div>
  <a class="btn primary" href="/projects">+ Новый проект</a>
</div>

<div class="grid cols-4">
  <div class="stat"><div class="num"><?= (int) $stats['projects'] ?></div><div class="label">Проектов</div></div>
  <div class="stat"><div class="num"><?= (int) $stats['runs'] ?></div><div class="label">Запусков</div></div>
  <div class="stat"><div class="num" style="color:var(--green)"><?= (int) $stats['ok'] ?></div><div class="label">Успешных</div></div>
  <div class="stat"><div class="num" style="color:var(--accent)"><?= (int) $stats['venvs'] ?></div><div class="label">Готовых venv</div></div>
</div>

<div class="grid cols-2" style="margin-top:20px">
  <section class="card">
    <h2>Среда выполнения</h2>
    <?php $py = Env::python(); ?>
    <?php if ($py): ?>
      <p><span class="badge ok">Python обнаружен</span></p>
      <table>
        <tr><td class="muted">Версия</td><td class="mono"><?= e($py['version']) ?></td></tr>
        <tr><td class="muted">Исполняемый</td><td class="mono"><?= e($py['exe']) ?></td></tr>
        <tr><td class="muted">Команда</td><td class="mono"><?= e($py['bin']) ?></td></tr>
      </table>
    <?php else: ?>
      <div class="flash error">Python не найден. Укажите абсолютный путь в <a href="/settings">настройках</a> или откройте <a href="/security">диагностику</a>.</div>
    <?php endif; ?>
    <p style="margin-top:14px"><a class="btn sm" href="/runtime">Управление средой выполнения →</a></p>
    <p class="muted" style="font-size:12px;margin-top:10px">Выполнение кода: <?= Config::get('security.execution_enabled', true) ? '<span class="badge ok">включено</span>' : '<span class="badge fail">отключено</span>' ?></p>
  </section>

  <section class="card">
    <h2>Как это работает</h2>
    <ol class="muted" style="padding-left:20px;margin:0">
      <li>Загрузите папку, ZIP или отдельные <code class="inline">.py</code>-файлы.</li>
      <li>Создайте venv и установите зависимости из <code class="inline">requirements.txt</code>.</li>
      <li>Укажите команду, например <code class="inline">python main.py --mode test</code>.</li>
      <li>Получите полный вывод <code class="inline">print</code> и ошибок в консоли ниже.</li>
    </ol>
    <p class="hint" style="margin-top:16px">Команда разбирается на аргументы без участия shell: <code class="inline">; &amp; | $ ` &gt; &lt;</code> запрещены, подстановки и glob-маски не работают.</p>
  </section>
</div>

<section class="card">
  <div class="card head"><h2>Последние запуски</h2><a href="/runs">Вся история →</a></div>
  <?php if (!$recent): ?>
    <p class="muted">Пока нет запусков. Создайте проект и нажмите «Запустить».</p>
  <?php else: ?>
    <table>
      <thead><tr><th>#</th><th>Проект</th><th>Команда</th><th>Статус</th><th>Время</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td class="mono"><a href="/runs/<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
          <td><?= e($r['project_name'] ?? '—') ?></td>
          <td class="mono muted"><?= e(mb_strimwidth((string) $r['command'], 0, 60, '…')) ?></td>
          <td><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
          <td class="muted"><?= e(date('d.m H:i', (int) $r['started_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
