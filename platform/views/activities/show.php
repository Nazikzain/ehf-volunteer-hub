<?php use App\Core\Csrf; $post = url('/activities/' . $a['id']); $att = ['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'excused' => 'Excused']; ?>
<p><a href="<?= e(url('/activities')) ?>">← Activities</a></p>
<h1><?= e($a['title']) ?> <span class="badge"><?= e(label($a['status'])) ?></span></h1>
<div class="card"><dl class="meta"><dt>Project</dt><dd><?= e($a['project_name']) ?></dd><dt>When</dt><dd><?= e(fmt_date($a['activity_date'])) ?>, <?= e(substr($a['start_time'], 0, 5)) ?>–<?= e(substr($a['end_time'], 0, 5)) ?> (UK time)</dd>
<dt>Venue</dt><dd><?= e($a['venue']) ?></dd><dt>Lead supervisor</dt><dd><?= e($a['lead_email'] ?? 'Not assigned') ?></dd></dl><?php if ($a['description']): ?><p><?= nl2br(e($a['description'])) ?></p><?php endif; ?>
<?php if ($a['can_manage']): ?><div class="actions">
<?php if ($a['status'] === 'scheduled'): ?><form method="post" action="<?= e($post) ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="status"><button class="btn secondary" name="status" value="completed">Mark completed</button></form>
<form method="post" action="<?= e($post) ?>" data-confirm="Cancel this activity?"><?= Csrf::field() ?><input type="hidden" name="action" value="status"><button class="btn danger" name="status" value="cancelled">Cancel activity</button></form><?php endif; ?>
<?php if ($a['status'] === 'cancelled'): ?><form method="post" action="<?= e($post) ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="status"><button class="btn secondary" name="status" value="scheduled">Reschedule</button></form><?php endif; ?>
</div><?php endif; ?></div>
<?php if ($a['can_manage'] && $a['status'] === 'scheduled'): ?><details class="card"><summary>Edit activity</summary><form method="post" action="<?= e($post) ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="edit">
<?= App\Core\View::partial('activities/_form', ['a' => $a, 'leads' => $a['leads']]) ?><div class="actions"><button class="btn">Save</button></div></form></details><?php endif; ?>
<div class="card"><h2 class="section-title">Roster and attendance</h2>
<?php if ($a['can_manage'] && $a['status'] === 'scheduled'): ?><form method="post" action="<?= e($post) ?>" class="filters"><?= Csrf::field() ?><input type="hidden" name="action" value="roster_add">
<div><label for="rv">Add volunteer</label><select id="rv" name="volunteer_id"><?php if (!$a['eligible']): ?><option value="">No eligible volunteers</option><?php endif; ?><?php foreach ($a['eligible'] as $e): ?><option value="<?= (int) $e['id'] ?>"><?= e($e['full_name']) ?></option><?php endforeach; ?></select></div>
<div><button class="btn"<?= $a['eligible'] ? '' : ' disabled' ?>>Add to roster</button></div></form><p class="hint">Only volunteers with an active service period on this project covering the activity date can be added.</p><?php endif; ?>
<?php if (!$a['roster']): ?><p class="empty">Nobody is rostered yet.</p><?php else: ?>
<form method="post" action="<?= e($post) ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="attendance">
<table><thead><tr><th>Volunteer</th><th>Attendance</th><?= $a['can_manage'] ? '<th></th>' : '' ?></tr></thead><tbody>
<?php foreach ($a['roster'] as $ro): ?><tr><td><?= e($ro['full_name']) ?></td><td>
<?php if ($a['can_manage'] && $a['status'] !== 'cancelled'): ?><select name="attendance[<?= (int) $ro['volunteer_id'] ?>]" aria-label="Attendance for <?= e($ro['full_name']) ?>"><option value="">Not recorded</option><?php foreach ($att as $k => $l): ?><option value="<?= $k ?>"<?= $ro['attendance'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
<?php else: ?><?= e($ro['attendance'] ? label($ro['attendance']) : 'Not recorded') ?><?php endif; ?></td>
<?php if ($a['can_manage']): ?><td><?php if (!$ro['attendance'] && $a['status'] === 'scheduled'): ?><button class="btn small secondary" form="rm-<?= (int) $ro['volunteer_id'] ?>">Remove</button><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table>
<?php if ($a['can_manage'] && $a['status'] !== 'cancelled'): ?><div class="actions"><button class="btn">Save attendance</button></div><?php endif; ?></form>
<?php foreach ($a['roster'] as $ro): ?><form id="rm-<?= (int) $ro['volunteer_id'] ?>" method="post" action="<?= e($post) ?>" hidden><?= Csrf::field() ?><input type="hidden" name="action" value="roster_remove"><input type="hidden" name="volunteer_id" value="<?= (int) $ro['volunteer_id'] ?>"></form><?php endforeach; ?>
<?php endif; ?></div>
