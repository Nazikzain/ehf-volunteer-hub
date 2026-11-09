<?php use App\Core\Csrf; ?>
<h1>Audit log</h1>
<div class="card"><div class="actions">
<form method="post" action="<?= e(url('/admin/audit/verify')) ?>"><?= Csrf::field() ?><button class="btn">Verify audit integrity</button></form>
<?php if ($pending): ?><form method="post" action="<?= e(url('/admin/audit/reconcile')) ?>"><?= Csrf::field() ?><button class="btn danger">Reconcile <?= (int) $pending ?> fallback file(s)</button></form><?php endif; ?></div>
<p class="hint">The audit log is append-only and hash-chained. Verification detects modified, missing, reordered or truncated entries; it cannot prevent someone with full hosting access from destroying the log.
<?php if ($anchor): ?> Latest anchor: <?= e($anchor['date']) ?>, sequence <?= (int) $anchor['seq'] ?>. Keep the daily anchor emails or manifest files off this server.<?php endif; ?></p></div>
<form class="card filters" method="get"><div><label for="fa">Action</label><select id="fa" name="action"><option value="">All</option><?php foreach ($actions as $a): ?><option<?= ($f['action'] ?? '') === $a ? ' selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
<div><label for="fo">Outcome</label><select id="fo" name="outcome"><option value="">All</option><?php foreach (['success','denied','failed'] as $o): ?><option<?= ($f['outcome'] ?? '') === $o ? ' selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></div>
<div><label for="ftt">Target type</label><input id="ftt" name="target_type" value="<?= e($f['target_type'] ?? '') ?>"></div><div><label for="fti">Target id</label><input id="fti" name="target_id" value="<?= e($f['target_id'] ?? '') ?>"></div>
<div><label for="fac">Actor user id</label><input id="fac" name="actor" value="<?= e($f['actor'] ?? '') ?>"></div><div><button class="btn">Filter</button></div></form>
<div class="card table-wrap"><table><thead><tr><th>#</th><th>When (UK)</th><th>Actor</th><th>Action</th><th>Outcome</th><th>Target</th><th>Details</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= (int) $r['seq'] ?><?= $r['source'] === 'fallback' ? ' <span class="badge warn">fallback</span>' : '' ?></td><td><?= e(local_dt(substr($r['occurred_at'], 0, 19), 'd M Y H:i:s')) ?></td>
<td><?= e($r['actor_email'] ?? ($r['actor_user_id'] ? 'user #' . $r['actor_user_id'] : 'anonymous')) ?><br><span class="muted"><?= e($r['actor_role'] ?? '') ?></span></td><td><?= e($r['action']) ?></td>
<td><span class="badge <?= $r['outcome'] === 'success' ? 'ok' : 'err' ?>"><?= e($r['outcome']) ?></span></td><td><?= e($r['target_type'] ? $r['target_type'] . ' #' . $r['target_id'] : '—') ?></td><td><code><?= e($r['details'] ?? '') ?></code></td></tr><?php endforeach; ?></tbody></table></div>
