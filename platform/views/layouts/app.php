<?php
use App\Core\Csrf; use App\Core\Session; use App\Security\Auth; use App\Security\Gate;
$path = $request->path ?? '/';
$nav = [];
$isVol = Auth::volunteerId() !== null;
$add = function (string $group, string $label, string $href, string $perm, bool $volunteerOnly = false) use (&$nav, $isVol) {
    if (Gate::allows($perm) && (!$volunteerOnly || $isVol)) { $nav[$group][] = [$label, $href]; }
};
$add('Overview', 'Dashboard', '/dashboard', 'notifications.view');
$add('Overview', 'Notifications', '/notifications', 'notifications.view');
$add('Recruitment', 'Applications', '/admin/applications', 'applications.view');
$add('People', 'Volunteers', '/volunteers', 'volunteers.view');
$add('People', 'My profile', '/me', 'volunteers.self', true);
$add('People', 'My reviews', '/my/reviews', 'volunteers.self', true);
$add('Programme', 'Projects', '/projects', 'projects.view');
$add('Programme', 'Activities', '/activities', 'activities.view');
$add('Programme', 'Tasks', '/tasks', 'tasks.view');
$add('Hours', 'My hours', '/my/hours', 'timelogs.submit', true);
$add('Hours', 'Hours approval', '/hours/review', 'timelogs.review');
$add('Development', 'Training types', '/admin/training-types', 'training_types.manage');
$add('Development', 'Certificates', '/certificates', 'certificates.view');
$add('Communication', 'Announcements', '/announcements', 'announcements.view');
$add('Reports', 'Reports', '/reports', 'reports.view');
$add('Administration', 'Users', '/admin/users', 'users.manage');
$add('Administration', 'Audit log', '/admin/audit', 'audit.view');
$add('Administration', 'Integrity report', '/admin/integrity', 'audit.view');
$add('Administration', 'Email outbox', '/admin/outbox', 'settings.manage');
$add('Administration', 'Settings', '/admin/settings', 'settings.manage');
$unread = Auth::check() ? (int) App\Core\Database::get()->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [Auth::id()]) : 0;
?><!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? 'Dashboard') . ' | EHF Volunteer Hub') ?></title>
<link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
<script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<?php if (env('APP_ENV') !== 'production'): ?><div class="demo">Test environment: <?= e(env('APP_ENV')) ?>. Use synthetic data only.</div><?php endif; ?>
<header class="topbar">
  <img src="<?= e(url('/assets/img/ehf-logo-white.png')) ?>" alt="Ethnic Health Forum">
  <span class="brand">Volunteer Hub</span>
  <span class="spacer"></span>
  <a href="<?= e(url('/notifications')) ?>">Notifications<?= $unread ? ' (' . $unread . ')' : '' ?></a>
  <span class="who"><?= e(Auth::user()['email'] ?? '') ?> · <?= e(label(Auth::effectiveRole() ?? '')) ?></span>
  <form method="post" action="<?= e(url('/logout')) ?>" class="inline"><?= Csrf::field() ?><button class="btn small secondary" type="submit">Sign out</button></form>
</header>
<div class="layout">
  <nav class="sidebar" aria-label="Main">
    <?php foreach ($nav as $group => $items): ?>
      <div class="group"><?= e($group) ?></div>
      <?php foreach ($items as [$l, $h]): ?>
        <a href="<?= e(url($h)) ?>"<?= str_starts_with($path, $h) ? ' class="active" aria-current="page"' : '' ?>><?= e($l) ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <main id="main">
    <?php foreach (Session::takeFlash() as $f): ?><div class="flash <?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div><?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
</body>
</html>
