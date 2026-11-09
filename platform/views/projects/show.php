<?php use App\Core\Csrf; use App\Security\Gate; $post = url('/projects/' . $p['id']); ?>
<p><a href="<?= e(url('/projects')) ?>">← Projects</a></p>
<h1><?= e($p['name']) ?> <span class="badge"><?= e(label($p['status'])) ?></span></h1>
<div class="grid grid-3">
  <div class="kpi"><div class="v"><?= count(array_filter($p['periods'], fn($x) => $x['status'] === 'active')) ?></div><div class="l">Active volunteers</div></div>
  <div class="kpi"><div class="v"><?= count($p['activities']) ?></div><div class="l">Activities (latest 50)</div></div>
  <div class="kpi"><div class="v"><?= e(minutes_to_hours($p['approved_minutes'])) ?></div><div class="l">Approved hours</div></div>
</div>
<div class="card"><?php if (Gate::allows('projects.manage')): ?><form method="post" action="<?= e($post) ?>"><?= Csrf::field() ?><?= App\Core\View::partial('projects/_form', ['p' => $p]) ?><div class="actions"><button class="btn">Save project</button></div></form>
<?php else: ?><p><?= nl2br(e($p['description'] ?? '')) ?></p><p class="muted"><?= e(fmt_date($p['start_date'])) ?>–<?= e(fmt_date($p['end_date'])) ?></p><?php endif; ?></div>
<?php if ($p['can_manage']): ?><div class="card"><h2 class="section-title">Supervisors</h2>
<table><thead><tr><th>Supervisor</th><th>Since</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($p['supervisors'] as $s): ?>
<tr><td><?= e($s['email']) ?></td><td><?= e(local_dt($s['assigned_at'])) ?></td><td><?= $s['ended_at'] ? 'Ended ' . e(local_dt($s['ended_at'])) : 'Active' ?></td>
<td><?php if (Gate::allows('supervisors.manage')): ?><form method="post" action="<?= e($post . '/supervisors') ?>"><?= Csrf::field() ?><input type="hidden" name="user_id" value="<?= (int) $s['user_id'] ?>">
<?php if ($s['ended_at']): ?><button class="btn small secondary" name="action" value="assign">Reappoint</button><?php else: ?><button class="btn small secondary" name="action" value="end">End supervision</button><?php endif; ?></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
<?php if (Gate::allows('supervisors.manage')): ?><form method="post" action="<?= e($post . '/supervisors') ?>" class="filters"><?= Csrf::field() ?><input type="hidden" name="action" value="assign">
<div><label for="sup">Add supervisor</label><select id="sup" name="user_id"><?php foreach ($candidates as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['email']) ?></option><?php endforeach; ?></select></div><div><button class="btn">Assign</button></div></form><?php endif; ?>
</div><?php endif; ?>
<div class="card"><h2 class="section-title">Volunteer service periods</h2>
<?php if ($p['can_manage']): ?><form method="post" action="<?= e($post . '/periods') ?>" class="filters"><?= Csrf::field() ?><input type="hidden" name="action" value="create">
<div><label for="vid">Volunteer</label><select id="vid" name="volunteer_id"><?php foreach ($volunteers as $vv): ?><option value="<?= (int) $vv['id'] ?>"><?= e($vv['full_name']) ?></option><?php endforeach; ?></select></div>
<div><label for="psd">Start date</label><input id="psd" name="start_date" type="date" value="<?= e(today_local()) ?>" required></div>
<div><label for="role">Role</label><input id="role" name="role_description" maxlength="255"></div><div><button class="btn">Add period</button></div></form><?php endif; ?>
<?php if (!$p['periods']): ?><p class="empty">No service periods.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Volunteer</th><th>Role</th><th>From</th><th>To</th><th>Status</th><?= $p['can_manage'] ? '<th>Manage</th>' : '' ?></tr></thead><tbody>
<?php foreach ($p['periods'] as $pa): ?><tr><td><?php if (Gate::allows('volunteers.view')): ?><a href="<?= e(url('/volunteers/' . $pa['volunteer_id'])) ?>"><?= e($pa['full_name']) ?></a><?php else: ?><?= e($pa['full_name']) ?><?php endif; ?></td>
<td><?= e($pa['role_description'] ?? '') ?></td><td><?= e(fmt_date($pa['start_date'])) ?></td><td><?= e(fmt_date($pa['end_date'])) ?></td><td><?= e(label($pa['status'])) ?></td>
<?php if ($p['can_manage']): ?><td><?php if ($pa['status'] === 'active'): ?><form method="post" action="<?= e($post . '/periods') ?>" class="filters"><?= Csrf::field() ?><input type="hidden" name="action" value="end"><input type="hidden" name="period_id" value="<?= (int) $pa['id'] ?>">
<input type="date" name="end_date" value="<?= e(today_local()) ?>" aria-label="End date"><select name="status" aria-label="End reason"><option value="completed">Completed</option><option value="withdrawn">Withdrawn</option></select><button class="btn small">End period</button></form><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>
<div class="card"><h2 class="section-title">Activities</h2><?php if (!$p['activities']): ?><p class="empty">No activities yet.</p><?php else: ?><table><thead><tr><th>Date</th><th>Activity</th><th>Venue</th><th>Status</th></tr></thead><tbody>
<?php foreach ($p['activities'] as $a): ?><tr><td><?= e(fmt_date($a['activity_date'])) ?> <?= e(substr($a['start_time'], 0, 5)) ?></td><td><a href="<?= e(url('/activities/' . $a['id'])) ?>"><?= e($a['title']) ?></a></td><td><?= e($a['venue']) ?></td><td><?= e(label($a['status'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
