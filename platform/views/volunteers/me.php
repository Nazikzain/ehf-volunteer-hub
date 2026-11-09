<?php use App\Core\Csrf; use App\Core\View; ?>
<h1>My profile</h1>
<div class="grid grid-2">
<div class="card"><h2 class="section-title">My details</h2>
<dl class="meta"><dt>Name</dt><dd><?= e($v['full_name']) ?></dd><dt>Email</dt><dd><?= e($v['email']) ?></dd><dt>Status</dt><dd><?= e(label($v['status'])) ?></dd></dl>
<p class="hint">To change your name or email, please contact EHF.</p>
<form method="post" action="<?= e(url('/me')) ?>"><?= Csrf::field() ?>
  <label for="phone">Phone</label><input id="phone" name="phone" type="tel" value="<?= e($v['phone']) ?>">
  <label for="postcode">Postcode</label><input id="postcode" name="postcode" value="<?= e($v['postcode']) ?>">
  <label for="preferred_language">Preferred language</label><input id="preferred_language" name="preferred_language" value="<?= e($v['preferred_language']) ?>">
  <div class="actions"><button class="btn">Save</button></div></form></div>
<div class="card"><h2 class="section-title">My availability</h2>
<form method="post" action="<?= e(url('/me')) ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="availability">
<?= View::partial('volunteers/_availability', ['availability' => $v['availability']]) ?><div class="actions"><button class="btn">Save availability</button></div></form></div>
</div>
<div class="card"><h2 class="section-title">My projects</h2><?php if (!$v['periods']): ?><p class="empty">You have not been assigned to a project yet.</p><?php else: ?>
<ul><?php foreach ($v['periods'] as $p): ?><li><a href="<?= e(url('/projects/' . $p['project_id'])) ?>"><?= e($p['project_name']) ?></a> · <?= e(fmt_date($p['start_date'])) ?>–<?= e(fmt_date($p['end_date'])) ?> · <?= e(label($p['status'])) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
<div class="card"><h2 class="section-title">My training</h2><?php if (!$v['training']): ?><p class="empty">No training recorded.</p><?php else: ?>
<ul><?php foreach ($v['training'] as $t): ?><li><?= e($t['type_name']) ?> · <?= e(label($t['status'])) ?><?= $t['expires_on'] ? ' · expires ' . e(fmt_date($t['expires_on'])) : '' ?></li><?php endforeach; ?></ul><?php endif; ?></div>
