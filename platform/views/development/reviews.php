<?php use App\Core\Csrf; use App\Security\Gate; ?>
<h1>Performance reviews: <?= e($v['full_name']) ?></h1>
<?php if ($canWrite && (App\Security\Auth::isAdmin() || $projects)): ?>
<details class="card"><summary>Add a review</summary><form method="post" action="<?= e(url('/volunteers/' . $v['id'] . '/reviews')) ?>"><?= Csrf::field() ?>
<div class="grid grid-2"><div><label for="rd">Review date</label><input id="rd" type="date" name="review_date" max="<?= e(today_local()) ?>" value="<?= e(today_local()) ?>" required></div>
<div><label for="rp">Project</label><select id="rp" name="project_id"><?php if (App\Security\Auth::isAdmin()): ?><option value="">Organisation-wide</option><?php endif; ?><?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div></div>
<div class="grid grid-4"><?php foreach (['reliability','teamwork','communication','quality'] as $k): ?><div><label for="s-<?= $k ?>"><?= e(label($k)) ?> (1–5)</label><select id="s-<?= $k ?>" name="<?= $k ?>"><?php for ($i = 1; $i <= 5; $i++): ?><option<?= $i === 3 ? ' selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div><?php endforeach; ?></div>
<label for="ach">Achievements</label><textarea id="ach" name="achievements"></textarea><label for="imp">Areas to develop</label><textarea id="imp" name="improvement_areas"></textarea><label for="nt">Notes</label><textarea id="nt" name="notes"></textarea>
<label class="checks"><input type="checkbox" name="shared" value="1"> Share this review with the volunteer</label><div class="actions"><button class="btn">Save review</button></div></form></details><?php endif; ?>
<?php if (!$reviews): ?><div class="card"><p class="empty">No reviews to show.</p></div><?php endif; ?>
<?php foreach ($reviews as $r): ?><div class="card"><h2><?= e(fmt_date($r['review_date'])) ?><?= $r['project_name'] ? ' · ' . e($r['project_name']) : '' ?></h2>
<p>Reliability <?= (int) $r['reliability'] ?> · Teamwork <?= (int) $r['teamwork'] ?> · Communication <?= (int) $r['communication'] ?> · Quality <?= (int) $r['quality'] ?></p>
<?php foreach (['achievements' => 'Achievements', 'improvement_areas' => 'Areas to develop', 'notes' => 'Notes'] as $k => $l): if ($r[$k]): ?><p><strong><?= $l ?>:</strong> <?= nl2br(e($r[$k])) ?></p><?php endif; endforeach; ?>
<p class="muted">By <?= e($r['reviewer_email']) ?><?= $r['shared_with_volunteer'] ? ' · shared with volunteer' : '' ?></p>
<?php if ($canWrite): ?><form method="post" action="<?= e(url('/reviews/' . $r['id'] . '/delete')) ?>" data-confirm="Remove this review?"><?= Csrf::field() ?><button class="btn small secondary">Remove</button></form><?php endif; ?></div><?php endforeach; ?>
