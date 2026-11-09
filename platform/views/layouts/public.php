<?php use App\Core\Session; ?><!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? 'Volunteer with us') . ' | Ethnic Health Forum') ?></title>
<link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
<script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</head>
<body>
<?php if (env('APP_ENV') !== 'production'): ?><div class="demo">Test environment. Do not enter real personal information.</div><?php endif; ?>
<header class="public-head"><img src="<?= e(url('/assets/img/ehf-logo.jpg')) ?>" alt="Ethnic Health Forum"><strong>Ethnic Health Forum · Volunteer Hub</strong></header>
<div class="public-wrap" id="main">
  <?php foreach (Session::takeFlash() as $f): ?><div class="flash <?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div><?php endforeach; ?>
  <?= $content ?>
</div>
<footer class="site">Ethnic Health Forum · Registered with the Charity Commission for England and Wales, no. 1123223 · <a href="<?= e(App\Services\Settings::get('privacy.notice_url', '#')) ?>">Privacy notice</a></footer>
</body>
</html>
