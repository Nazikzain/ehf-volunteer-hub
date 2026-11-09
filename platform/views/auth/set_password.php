<?php use App\Core\Csrf; ?>
<div class="card">
  <h1><?= e($heading) ?></h1>
  <form method="post" action="<?= e(url($action)) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <label for="password">New password</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12" aria-describedby="pw-hint">
    <p class="hint" id="pw-hint">At least 12 characters. Avoid common passwords and your email name.</p>
    <label for="password_confirm">Confirm new password</label>
    <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>
    <div class="actions"><button class="btn" type="submit">Save password</button></div>
  </form>
</div>
