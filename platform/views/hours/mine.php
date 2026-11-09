<?php use App\Core\Csrf; $badge = ['approved' => 'ok', 'rejected' => 'err', 'revision_requested' => 'warn', 'voided' => 'err', 'pending' => '']; ?>
<h1>My hours</h1>
<div class="card"><h2 class="section-title">Log hours</h2>
<?php if (!$opt['periods']): ?><p class="empty">You need an active project assignment before you can log hours.</p><?php else: ?>
<div class="grid grid-2">
<form method="post" action="<?= e(url('/my/hours')) ?>"><?= Csrf::field() ?><input type="hidden" name="kind" value="activity"><?php if ($resubmit): ?><input type="hidden" name="resubmission_of_id" value="<?= (int) $resubmit ?>"><?php endif; ?>
<h3>For an activity I attended</h3><?php if (!$opt['activities']): ?><p class="muted">No past activities waiting for hours.</p><?php else: ?>
<label for="act">Activity</label><select id="act" name="activity_id"><?php foreach ($opt['activities'] as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e(fmt_date($a['activity_date'])) ?> · <?= e($a['title']) ?> (<?= e($a['project_name']) ?>)</option><?php endforeach; ?></select>
<label for="h1">Hours</label><input id="h1" name="hours" type="number" step="0.25" min="0.25" max="24" required>
<label for="d1">What did you do?</label><textarea id="d1" name="description" maxlength="500" required></textarea><div class="actions"><button class="btn">Submit</button></div><?php endif; ?></form>
<form method="post" action="<?= e(url('/my/hours')) ?>"><?= Csrf::field() ?><?php if ($resubmit): ?><input type="hidden" name="resubmission_of_id" value="<?= (int) $resubmit ?>"><?php endif; ?>
<h3>For a task or general project work</h3>
<label for="kind">Type</label><select id="kind" name="kind"><option value="general">General project work</option><?php if ($opt['tasks']): ?><option value="task">A task</option><?php endif; ?></select>
<label for="pr">Project (for general work)</label><select id="pr" name="project_id"><?php foreach ($opt['periods'] as $p): ?><option value="<?= (int) $p['project_id'] ?>"><?= e($p['project_name']) ?></option><?php endforeach; ?></select>
<?php if ($opt['tasks']): ?><label for="tk">Task (for task work)</label><select id="tk" name="task_id"><?php foreach ($opt['tasks'] as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['title']) ?></option><?php endforeach; ?></select><?php endif; ?>
<label for="wd">Date</label><input id="wd" name="work_date" type="date" max="<?= e(today_local()) ?>" required>
<label for="h2">Hours</label><input id="h2" name="hours" type="number" step="0.25" min="0.25" max="24" required>
<label for="d2">What did you do?</label><textarea id="d2" name="description" maxlength="500" required></textarea><div class="actions"><button class="btn">Submit</button></div></form>
</div><?php endif; ?></div>
<div class="card table-wrap"><h2 class="section-title">My submissions</h2><?php if (!$logs): ?><p class="empty">You have not logged any hours yet.</p><?php else: ?>
<table><thead><tr><th>Date</th><th>Project</th><th>For</th><th>Hours</th><th>Status</th><th>Comment</th><th></th></tr></thead><tbody>
<?php foreach ($logs as $l): ?><tr><td><?= e(fmt_date($l['work_date'])) ?></td><td><?= e($l['project_name']) ?></td><td><?= e($l['activity_title'] ?? $l['task_title'] ?? 'General work') ?></td>
<td><?= e(minutes_to_hours($l['minutes'])) ?></td><td><span class="badge <?= $badge[$l['status']] ?? '' ?>"><?= e(label($l['status'])) ?></span></td><td><?= e($l['reviewer_comment'] ?? $l['void_reason'] ?? '') ?></td>
<td><?php if (in_array($l['status'], ['pending', 'revision_requested'], true)): ?><details><summary>Edit</summary><form method="post" action="<?= e(url('/my/hours/' . $l['id'])) ?>"><?= Csrf::field() ?>
<input name="hours" type="number" step="0.25" min="0.25" max="24" value="<?= e(minutes_to_hours($l['minutes'])) ?>" aria-label="Hours"><textarea name="description" maxlength="500" aria-label="Description"><?= e($l['description']) ?></textarea><button class="btn small">Save</button></form></details>
<?php elseif (in_array($l['status'], ['rejected', 'voided'], true)): ?><a href="<?= e(url('/my/hours', ['resubmit' => $l['id']])) ?>">Resubmit</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
