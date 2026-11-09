<?php use App\Core\Csrf; ?>
<h1>Users</h1>
<div class="card"><h2 class="section-title">Invite a staff member</h2>
<form method="post" action="<?= e(url('/admin/users')) ?>" class="filters"><?= Csrf::field() ?>
  <div class="grow"><label for="email">Email</label><input id="email" name="email" type="email" required></div>
  <div><label for="role">Role</label><select id="role" name="role"><option value="organisation_admin">Organisation Admin</option><option value="project_supervisor">Project Supervisor</option></select></div>
  <div><button class="btn">Send invitation</button></div>
</form><p class="hint">Volunteers receive accounts when their application is approved.</p></div>
<form class="card filters" method="get">
  <div><label for="q">Email</label><input id="q" name="q" value="<?= e($f['q']) ?>"></div>
  <div><label for="frole">Role</label><select id="frole" name="role"><option value="">All</option><?php foreach (['organisation_admin','project_supervisor','volunteer'] as $x): ?><option value="<?= $x ?>"<?= $f['role'] === $x ? ' selected' : '' ?>><?= e(label($x)) ?></option><?php endforeach; ?></select></div>
  <div><label for="fst">Status</label><select id="fst" name="status"><option value="">All</option><?php foreach (['invited','active','deactivated'] as $x): ?><option value="<?= $x ?>"<?= $f['status'] === $x ? ' selected' : '' ?>><?= e(label($x)) ?></option><?php endforeach; ?></select></div>
  <div><button class="btn">Filter</button></div>
</form>
<div class="card table-wrap"><?php if (!$users): ?><p class="empty">No users found.</p><?php else: ?>
<table><thead><tr><th>Email</th><th>Roles</th><th>Status</th><th>Last sign-in</th></tr></thead><tbody>
<?php foreach ($users as $u): ?><tr><td><a href="<?= e(url('/admin/users/' . $u['id'])) ?>"><?= e($u['email']) ?></a><?= $u['is_owner'] ? ' <span class="badge warn">Primary Admin</span>' : '' ?></td>
<td><?= e(implode(', ', array_map('label', array_filter(explode(',', (string) $u['roles']))))) ?></td><td><?= e(label($u['status'])) ?></td><td><?= e(local_dt($u['last_login_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?></div>
