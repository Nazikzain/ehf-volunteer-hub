<?php use App\Core\Csrf; ?>
<div class="card">
  <h1>Reset your password</h1>
  <p>Enter your email address. If an active account exists, we will send a reset link valid for 60 minutes.</p>
  <form method="post" action="<?= e(url('/forgot-password')) ?>">
    <?= Csrf::field() ?>
    <label for="email">Email address</label>
    <input id="email" name="email" type="email" autocomplete="email" required>
    <div class="actions"><button class="btn" type="submit">Send reset link</button><a href="<?= e(url('/login')) ?>">Back to sign in</a></div>
  </form>
</div>
