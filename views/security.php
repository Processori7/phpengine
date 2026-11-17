<h1>Диагностика и безопасность</h1>
<section class="card">
  <h2>Проверки окружения</h2>
  <?php foreach ($checks as $c): ?>
    <div class="row" style="padding:10px 0;border-bottom:1px solid var(--border)">
      <span class="badge <?= $c['status'] === 'ok' ? 'ok' : 'fail' ?>"><?= $c['status'] === 'ok' ? 'OK' : 'FAIL' ?></span>
      <strong style="min-width:140px"><?= e($c['name']) ?></strong>
      <span class="mono muted" style="font-size:12px;flex:1"><?= e($c['detail']) ?></span>
    </div>
    <?php if ($c['status'] !== 'ok'): ?><div class="muted" style="font-size:12px;padding:4px 0 10px 66px"><?= e($c['hint']) ?></div><?php endif; ?>
  <?php endforeach; ?>
</section>

<section class="card">
  <h2>Журнал событий</h2>
  <?php if (!$audit): ?><p class="muted">Событий пока нет.</p><?php else: ?>
  <table>
    <thead><tr><th>Время</th><th>Действие</th><th>Детали</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($audit as $a): ?>
      <tr>
        <td class="muted"><?= e(date('d.m.Y H:i:s', (int) $a['created_at'])) ?></td>
        <td class="mono"><?= e($a['action']) ?></td>
        <td class="mono muted"><?= e(mb_strimwidth((string) $a['detail'], 0, 80, '…')) ?></td>
        <td class="mono muted"><?= e($a['ip']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
