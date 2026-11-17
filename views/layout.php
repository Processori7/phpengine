<?php $standalone = $standalone ?? false; ?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e($csrf) ?>">
  <title><?= e($title ?? 'Панель') ?> · <?= e($appName) ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php if ($standalone): ?>
  <div class="auth-wrap"><div class="auth-card"><?= $content ?></div></div>
<?php else: ?>
  <?php $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'; ?>
  <div class="shell">
    <aside class="sidebar">
      <div class="brand"><span class="brand-mark">Py</span><span><?= e($appName) ?></span></div>
      <div class="nav-label">Управление</div>
      <nav class="nav">
        <a href="/" class="<?= $uri === '/' ? 'active' : '' ?>"><span class="ico">◈</span> Обзор</a>
        <a href="/projects" class="<?= str_starts_with($uri, '/projects') ? 'active' : '' ?>"><span class="ico">▤</span> Проекты</a>
        <a href="/runs" class="<?= str_starts_with($uri, '/runs') ? 'active' : '' ?>"><span class="ico">▷</span> Запуски</a>
      </nav>
      <div class="nav-label">Система</div>
      <nav class="nav">
        <a href="/runtime" class="<?= $uri === '/runtime' ? 'active' : '' ?>"><span class="ico">⬡</span> Среда выполнения</a>
        <a href="/security" class="<?= $uri === '/security' ? 'active' : '' ?>"><span class="ico">◇</span> Диагностика</a>
        <a href="/settings" class="<?= $uri === '/settings' ? 'active' : '' ?>"><span class="ico">⚙</span> Настройки</a>
      </nav>
      <div class="sidebar-foot">
        <?= e(PHP_VERSION) ?> · <?= e(Config::get('app_name') === 'PyHost Engine' ? 'stable' : 'custom') ?>
      </div>
    </aside>
    <div class="main">
      <header class="topbar">
        <div class="muted mono">Python execution engine</div>
        <div class="user">
          <span class="avatar"><?= e(mb_strtoupper(mb_substr((string) ($user['username'] ?? '?'), 0, 1))) ?></span>
          <span><?= e($user['username'] ?? '') ?></span>
          <form method="post" action="/logout"><?= csrfField() ?><button class="btn sm" type="submit">Выйти</button></form>
        </div>
      </header>
      <main class="content">
        <?php foreach ($flash as $f): ?>
          <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
      </main>
    </div>
  </div>
<?php endif; ?>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
