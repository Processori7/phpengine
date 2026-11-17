<h1>История запусков</h1>
<section class="card">
  <?php if (!$runs): ?>
    <p class="muted">Запусков пока нет.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>#</th><th>Проект</th><th>Команда</th><th>Статус</th><th>Код</th><th>Вывод</th><th>Дата</th></tr></thead>
      <tbody>
      <?php foreach ($runs as $r): ?>
        <tr>
          <td class="mono"><a href="/runs/<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
          <td><?= e($r['project_name'] ?? '—') ?></td>
          <td class="mono muted"><?= e(mb_strimwidth((string) $r['command'], 0, 70, '…')) ?></td>
          <td><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
          <td class="mono"><?= e($r['exit_code'] ?? '—') ?></td>
          <td class="mono muted"><?= e(Runner::humanBytes((int) $r['bytes'])) ?></td>
          <td class="muted"><?= e(date('d.m.Y H:i:s', (int) $r['started_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
