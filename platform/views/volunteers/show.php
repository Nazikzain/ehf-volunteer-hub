<?php use App\Core\Csrf; use App\Core\View; use App\Security\Auth; use App\Security\Gate;
$edit = Gate::allows('volunteers.edit') && $v['anonymised_at'] === null; $post = url('/volunteers/' . $v['id']); ?>
<p><a href="<?= e(url('/volunteers')) ?>">← Volunteers</a></p>
<h1><?= e($v['full_name']) ?> <span class="badge"><?= e(label($v['status'])) ?></span></h1>
<div class="grid grid-4">
  <div class="kpi"><div class="v"><?= e(minutes_to_hours($v['approved_minutes'])) ?></div><div class="l">Approved hours</div></div>
  <div class="kpi"><div class="v"><?= (int) ($v['attendance']['attended'] ?? 0) ?></div><div class="l">Sessions attended</div></div>
  <div class="kpi"><div class="v"><?= count(array_filter($v['periods'], fn($p) => $p['status'] === 'active')) ?></div><div class="l">Active project periods</div></div>
  <div class="kpi"><div class="v"><?= count(array_filter($v['certificates'], fn($c) => in_array($c['status'], ['issued','delivered'], true))) ?></div><div class="l">Certificates</div></div>
</div>
<div class="grid grid-2">
<div class="card"><h2 class="section-title">Details</h2>
<?php if ($edit): ?>
<form method="post" action="<?= e($post) ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="details">
  <label for="full_name">Full name</label><input id="full_name" name="full_name" value="<?= e($v['full_name']) ?>" required>
  <label for="email">Contact email</label><input id="email" name="email" type="email" value="<?= e($v['email']) ?>" required>
  <label for="phone">Phone</label><input id="phone" name="phone" value="<?= e($v['phone']) ?>">
  <label for="postcode">Postcode</label><input id="postcode" name="postcode" value="<?= e($v['postcode']) ?>">
  <label for="preferred_language">Preferred language</label><input id="preferred_language" name="preferred_language" value="<?= e($v['preferred_language']) ?>">
  <div class="actions"><button class="btn">Save details</button></div></form>
<?php else: ?>
<dl class="meta"><dt>Email</dt><dd><?= e($v['email']) ?></dd><dt>Phone</dt><dd><?= e($v['phone'] ?? '—') ?></dd><dt>Postcode</dt><dd><?= e($v['postcode'] ?? '—') ?></dd>
<dt>Preferred language</dt><dd><?= e($v['preferred_language'] ?? '—') ?></dd><dt>Start date</dt><dd><?= e(fmt_date($v['start_date'])) ?></dd></dl>
<?php endif; ?></div>
<div class="card"><h2 class="section-title">Status</h2>
<?php if ($edit): ?><form method="post" action="<?= e($post) ?>" class="filters"><?= Csrf::field() ?><input type="hidden" name="action" value="status">
  <div><label for="st">New status</label><select id="st" name="status"><?php foreach (App\Services\VolunteerService::STATUSES as $s): ?><option value="<?= $s ?>"<?= $s === $v['status'] ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
  <div class="grow"><label for="reason">Reason</label><input id="reason" name="reason" maxlength="255"></div><div><button class="btn">Change</button></div></form><?php endif; ?>
<table><thead><tr><th>When</th><th>Change</th><th>By</th></tr></thead><tbody><?php foreach ($v['history'] as $h): ?>
<tr><td><?= e(local_dt($h['changed_at'])) ?></td><td><?= e(label($h['from_status'] ?? 'new')) ?> → <?= e(label($h['to_status'])) ?><?= $h['reason'] ? '<br><span class="muted">' . e($h['reason']) . '</span>' : '' ?></td><td><?= e($h['by_email'] ?? 'System') ?></td></tr><?php endforeach; ?></tbody></table></div>
</div>
<div class="card"><h2 class="section-title">Project service periods</h2>
<?php if (!$v['periods']): ?><p class="empty">No project periods yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Project</th><th>Role</th><th>From</th><th>To</th><th>Status</th></tr></thead><tbody>
<?php foreach ($v['periods'] as $p): ?><tr><td><a href="<?= e(url('/projects/' . $p['project_id'])) ?>"><?= e($p['project_name']) ?></a></td><td><?= e($p['role_description'] ?? '') ?></td><td><?= e(fmt_date($p['start_date'])) ?></td><td><?= e(fmt_date($p['end_date'])) ?></td><td><?= e(label($p['status'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>
<div class="grid grid-2">
<div class="card"><h2 class="section-title">Skills</h2>
<?php if ($edit): ?><form method="post" action="<?= e($post) ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="skills"><div class="checks">
<?php $hs = array_column($v['skills'], 'id'); foreach ($skills as $s): ?><label><input type="checkbox" name="skills[]" value="<?= (int) $s['id'] ?>"<?= in_array($s['id'], $hs) ? ' checked' : '' ?>> <?= e($s['name']) ?></label><?php endforeach; ?></div>
<div class="actions"><button class="btn">Save skills</button></div></form>
<?php else: ?><p><?= e(implode(', ', array_column($v['skills'], 'name'))) ?: '—' ?></p><?php endif; ?></div>
<div class="card"><h2 class="section-title">Availability</h2>
<?php if ($edit): ?><form method="post" action="<?= e($post) ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="availability"><?= View::partial('volunteers/_availability', ['availability' => $v['availability']]) ?><div class="actions"><button class="btn">Save availability</button></div></form>
<?php else: ?><?= View::partial('volunteers/_availability', ['availability' => $v['availability'], 'readonly' => true]) ?><?php endif; ?></div>
</div>
<?php if (Gate::allows('notes.view')): ?><div class="card"><h2 class="section-title">Notes</h2>
<p class="hint">Notes are never visible to the volunteer. Supervisors see only project notes for projects they supervise.</p>
<?php if ($v['anonymised_at'] === null): ?><form method="post" action="<?= e($post . '/notes') ?>"><?= Csrf::field() ?>
  <label for="body">Add a note</label><textarea id="body" name="body" maxlength="5000" required></textarea>
  <div class="filters"><?php if (Auth::isAdmin()): ?><div><label for="vis">Visible to</label><select id="vis" name="visibility"><option value="admins">Admins only</option><option value="project">Project supervisors</option></select></div><?php else: ?><input type="hidden" name="visibility" value="project"><?php endif; ?>
  <div><label for="pid">Project</label><select id="pid" name="project_id"><option value="">—</option><?php foreach ($v['periods'] as $p): ?><option value="<?= (int) $p['project_id'] ?>"><?= e($p['project_name']) ?></option><?php endforeach; ?></select></div>
  <div><button class="btn">Add note</button></div></div></form><?php endif; ?>
<?php foreach ($v['notes'] as $n): ?><div class="card"><p><?= nl2br(e($n['body'])) ?></p><p class="muted"><?= e($n['author_email']) ?> · <?= e(local_dt($n['created_at'])) ?> · <?= e($n['visibility'] === 'project' ? 'Project: ' . $n['project_name'] : 'Admins only') ?></p>
<form method="post" action="<?= e(url('/notes/' . $n['id'] . '/delete')) ?>" data-confirm="Remove this note?"><?= Csrf::field() ?><button class="btn small secondary">Remove</button></form></div><?php endforeach; ?>
</div><?php endif; ?>
<?php if (Auth::isAdmin()): ?><div class="card"><h2 class="section-title">Applications</h2><ul><?php foreach ($v['applications'] as $a): ?><li><a href="<?= e(url('/admin/applications/' . $a['id'])) ?>"><?= e($a['reference']) ?></a> · <?= e(label($a['status'])) ?> · <?= e(local_dt($a['submitted_at'])) ?></li><?php endforeach; ?></ul></div>
<div class="card"><h2 class="section-title">Data erasure request</h2>
<?php if ($v['anonymised_at'] !== null): ?><p>This volunteer's personal data was erased on <?= e(local_dt($v['anonymised_at'])) ?>.</p>
<?php elseif (!$v['erasure_request']): ?><p class="hint">Use only for an approved erasure request. An Organisation Admin prepares it and the Primary Admin approves it.</p>
<form method="post" action="<?= e($post . '/erasure') ?>" class="filters"><?= Csrf::field() ?><input type="hidden" name="action" value="prepare"><div class="grow"><label for="rr">Request reference</label><input id="rr" name="request_ref" maxlength="100" required placeholder="e.g. email received 9 Nov 2026"></div><div><button class="btn secondary">Prepare erasure</button></div></form>
<?php else: ?><div class="flash warning">Erasure prepared on <?= e(local_dt($v['erasure_request']['prepared_at'])) ?> (ref: <?= e($v['erasure_request']['request_ref']) ?>). Awaiting Primary Admin approval.</div>
<?php if (Auth::isOwner()): ?><form method="post" action="<?= e($post . '/erasure') ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="approve">
  <p>Applications that will be deleted:</p><ul><?php foreach ($erasureCandidates as $c): ?><li><?php if ($c['linked']): ?><?= e($c['reference']) ?> (linked)<?php else: ?><label class="checks"><input type="checkbox" name="unlinked[]" value="<?= (int) $c['id'] ?>"> <?= e($c['reference']) ?>: same email, not linked. Tick only if it is the same person.</label><?php endif; ?></li><?php endforeach; ?></ul>
  <label class="checks"><input type="checkbox" name="confirm1" value="1"> I have verified the erasure request</label><label class="checks"><input type="checkbox" name="confirm2" value="1"> I understand this cannot be undone</label>
  <div class="actions"><button class="btn danger">Approve and erase</button></div></form><?php endif; ?>
<form method="post" action="<?= e($post . '/erasure') ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="cancel"><button class="btn small secondary">Cancel request</button></form>
<?php endif; ?></div><?php endif; ?>
