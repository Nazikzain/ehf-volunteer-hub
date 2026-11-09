<?php use App\Core\Csrf; ?>
<h1>Settings</h1>
<div class="flash info">Items marked [D-…] are EHF decisions. The values here are conservative defaults until EHF and its Data Protection Lead confirm them.</div>
<form class="card" method="post" action="<?= e(url('/admin/settings')) ?>"><?= Csrf::field() ?>
<?php foreach ($labels as $k => $l): $f = str_replace('.', '__', $k); ?><label for="<?= e($f) ?>"><?= e($l) ?></label>
<?php if ($k === 'consent.text'): ?><textarea id="<?= e($f) ?>" name="<?= e($f) ?>"><?= e($vals[$k]) ?></textarea><?php else: ?><input id="<?= e($f) ?>" name="<?= e($f) ?>" value="<?= e($vals[$k]) ?>"><?php endif; ?><?php endforeach; ?>
<div class="actions"><button class="btn">Save settings</button></div></form>
<div class="card"><h2 class="section-title">System</h2><p>Schema migrations applied: <code><?= e($schema) ?></code></p><p>Secrets (database, SMTP, audit keys) are configured in the server's .env file, never here.</p></div>
