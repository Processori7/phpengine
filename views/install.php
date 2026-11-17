<?php $standalone = true; ?>
<div class="brand">PyHost Engine</div>
<p class="sub">Первый запуск — создайте аккаунт администратора</p>
<form method="post" action="/install">
  <?= csrfField() ?>
  <div class="field">
    <label for="username">Логин</label>
    <input id="username" name="username" type="text" required minlength="3" maxlength="64" autocomplete="username" autofocus>
  </div>
  <div class="field">
    <label for="password">Пароль (минимум 10 символов)</label>
    <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
  </div>
  <div class="field">
    <label for="confirm">Повторите пароль</label>
    <input id="confirm" name="confirm" type="password" required minlength="10" autocomplete="new-password">
  </div>
  <button class="btn primary" type="submit" style="width:100%">Создать администратора</button>
</form>
<p class="muted" style="font-size:12px;margin:18px 0 0">Пароль хранится только как bcrypt-хэш. Сессия защищена CSRF-токеном и безопасными cookie.</p>
