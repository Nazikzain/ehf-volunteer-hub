<?php use App\Core\Csrf; use App\Security\Auth; $self = (int) $u['id'] === Auth::id(); $post = url('/admin/users/' . $u['id']); ?>
<p><a href="<?= e(url('/admin/users')) ?>">← Users</a></p>
<h1><?= e($u['email']) ?> <?= $u['is_owner'] ? '<span class="badge warn">Primary Admin</span>' : '' ?></h1>
<div class="grid grid-2">
<div class="card"><dl class="meta"><dt>Status</dt><dd><?= e(label($u['status'])) ?></dd><dt>Roles</dt><dd><?= e(implode(', ', array_map('label', $u['roles']))) ?: '—' ?></dd>
<dt>Email verified</dt><dd><?= e(local_dt($u['email_verified_at'])) ?></dd><dt>Last sign-in</dt><dd><?= e(local_dt($u['last_login_at'])) ?></dd>
<?php if ($u['volunteer']): ?><dt>Volunteer</dt><dd><a href="<?= e(url('/volunteers/' . $u['volunteer']['id'])) ?>"><?= e($u['volunteer']['full_name']) ?></a></dd><?php endif; ?></dl>
<?php if ($u['supervises']): ?><h3>Supervision</h3><ul><?php foreach ($u['supervises'] as $s): ?><li><a href="<?= e(url('/projects/' . $s['id'])) ?>"><?= e($s['name']) ?></a><?= $s['ended_at'] ? ' (ended)' : '' ?></li><?php endforeach; ?></ul><?php endif; ?></div>
<div class="card"><h2 class="section-title">Actions</h2>
<?php if ($u['is_owner'] && !$self): ?><p>The Primary Admin account is protected. Only the Primary Admin can transfer ownership.</p>
<?php elseif (!$self && $u['anonymised_at'] === null): ?>
  <?php foreach (['organisation_admin' => 'Organisation Admin', 'project_supervisor' => 'Project Supervisor'] as $code => $lbl): $has = in_array($code, $u['roles'], true); ?>
  <form method="post" action="<?= e($post) ?>" class="inline"><?= Csrf::field() ?><input type="hidden" name="role" value="<?= $code ?>">
    <button class="btn small secondary" name="action" value="<?= $has ? 'revoke' : 'grant' ?>"><?= $has ? 'Remove' : 'Grant' ?> <?= $lbl ?></button></form>
  <?php endforeach; ?>
  <div class="actions">
  <?php if ($u['status'] === 'active'): ?><form method="post" action="<?= e($post) ?>" data-confirm="Deactivate this account? Their sessions will end immediately."><?= Csrf::field() ?><button class="btn danger" name="action" value="deactivate">Deactivate</button></form><?php endif; ?>
  <?php if ($u['status'] === 'deactivated'): ?><form method="post" action="<?= e($post) ?>"><?= Csrf::field() ?><button class="btn secondary" name="action" value="reactivate">Reactivate</button></form><?php endif; ?>
  <?php if ($u['status'] === 'invited'): ?><form method="post" action="<?= e($post) ?>"><?= Csrf::field() ?><button class="btn secondary" name="action" value="reissue">Send new activation link</button></form><?php endif; ?>
  </div>
  <details><summary>Anonymise or delete</summary>
  <form method="post" action="<?= e($post) ?>"><?= Csrf::field() ?>
    <label class="checks"><input type="checkbox" name="confirm1" value="1"> I understand this cannot be undone</label>
    <label class="checks"><input type="checkbox" name="confirm2" value="1"> I have checked this is the correct account</label>
    <div class="actions"><button class="btn danger" name="action" value="anonymise">Anonymise account</button>
    <?php if ($u['status'] === 'invited'): ?><button class="btn danger" name="action" value="delete">Delete invitation</button><?php endif; ?></div>
  </form></details>
<?php elseif ($self && $u['is_owner']): ?>
  <h3>Transfer Primary Admin ownership</h3>
  <form method="post" action="<?= e(url('/admin/ownership')) ?>"><?= Csrf::field() ?>
    <label for="target_id">New Primary Admin (active Organisation Admin)</label>
    <select id="target_id" name="target_id"><?php foreach ($admins as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['email']) ?></option><?php endforeach; ?></select>
    <label for="password">Your password</label><input id="password" name="password" type="password" autocomplete="current-password" required>
    <label class="checks"><input type="checkbox" name="confirm1" value="1"> I want to transfer ownership</label>
    <label class="checks"><input type="checkbox" name="confirm2" value="1"> I understand I will no longer be the Primary Admin</label>
    <div class="actions"><button class="btn danger">Transfer ownership</button></div></form>
<?php else: ?><p>You cannot change your own account here.</p><?php endif; ?>
</div></div>
