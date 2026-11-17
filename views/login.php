<?php $standalone = true; ?>
<div class="brand">PyHost Engine</div>
<p class="sub"><?= !empty($twofa) ? 'Подтвердите вход кодом из приложения' : 'Вход в панель управления' ?></p>
<?php foreach ($flash as $f): ?>
  <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<?php if (!empty($twofa)): ?>
  <form method="post" action="<?= e($loginPath) ?>">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="twofa">
    <div class="field">
      <label for="code">Код из аутентификатора (6 цифр)</label>
      <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus class="mono" style="font-size:20px;letter-spacing:.3em">
    </div>
    <button class="btn primary" type="submit" style="width:100%">Подтвердить</button>
  </form>
  <p class="muted" style="font-size:12px;margin:18px 0 0">Код меняется каждые 30 секунд. Неверные коды ограничиваются.</p>
<?php else: ?>
  <form method="post" action="<?= e($loginPath) ?>">
    <?= csrfField() ?>
    <div class="field">
      <label for="username">Логин</label>
      <input id="username" name="username" type="text" required autocomplete="username" autofocus>
    </div>
    <div class="field">
      <label for="password">Пароль</label>
      <input id="password" name="password" type="password" required autocomplete="current-password">
    </div>
    <?php if (!empty($captcha)): ?>
      <div class="field">
        <label for="captcha">Подтвердите: <b class="mono" style="color:var(--text);font-size:15px"><?= e($captcha['question']) ?></b></label>
        <input id="captcha" name="captcha" type="text" inputmode="numeric" autocomplete="off" required pattern="-?[0-9]+" class="mono">
      </div>
    <?php endif; ?>
    <label class="check"><input type="checkbox" name="remember" value="1"> Запомнить меня (14 дней)</label>
    <button class="btn primary" type="submit" style="width:100%;margin-top:14px">Войти</button>
  </form>
<?php endif; ?>
