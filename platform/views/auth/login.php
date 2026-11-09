<?php use App\Core\Csrf; ?>
<div class="card">
  <h1>Sign in</h1>
  <form method="post" action="<?= e(url('/login')) ?>" novalidate>
    <?= Csrf::field() ?>
    <label for="email">Email address</label>
    <input id="email" name="email" type="email" autocomplete="username" required>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <div class="actions"><button class="btn" type="submit">Sign in</button><a href="<?= e(url('/forgot-password')) ?>">Forgotten your password?</a></div>
  </form>
</div>
<p class="muted">Want to volunteer? <a href="<?= e(url('/apply')) ?>">Apply here</a>.</p>
