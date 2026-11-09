<?php use App\Core\Csrf; use App\Services\ApplicationService as A; $open = in_array($a['status'], ['pending','under_review'], true); ?>
<p><a href="<?= e(url('/admin/applications')) ?>">← Applications</a></p>
<h1>Application <?= e($a['reference']) ?> <span class="badge"><?= e(label($a['status'])) ?></span></h1>
<div class="grid grid-2">
<div class="card"><h2 class="section-title">Applicant</h2><dl class="meta">
  <dt>Name</dt><dd><?= e($a['full_name']) ?></dd><dt>Email</dt><dd><?= e($a['email']) ?></dd>
  <dt>Phone</dt><dd><?= e($a['phone'] ?? '—') ?></dd><dt>Postcode</dt><dd><?= e($a['postcode'] ?? '—') ?></dd>
  <dt>Skills</dt><dd><?= e(implode(', ', $a['skills'])) ?></dd>
  <dt>Availability</dt><dd><?= e(label($a['availability_type'])) ?><?= $a['days'] ? ': ' . e(implode(', ', array_map(fn($d) => A::DAYS[$d], $a['days']))) : '' ?></dd>
  <dt>Heard via</dt><dd><?= e($a['referral_source'] ?? '—') ?></dd><dt>Submitted</dt><dd><?= e(local_dt($a['submitted_at'])) ?></dd>
  <dt>Consent</dt><dd>Version <?= e($a['consent_version']) ?>, <?= e(local_dt($a['consent_at'])) ?></dd>
  <?php if ($a['volunteer_id']): ?><dt>Volunteer</dt><dd><a href="<?= e(url('/volunteers/' . $a['volunteer_id'])) ?>">Open volunteer record</a></dd><?php endif; ?>
</dl><h3>Motivation</h3><p><?= nl2br(e($a['motivation'] ?? '—')) ?></p></div>
<div class="card"><h2 class="section-title">Review</h2>
<?php if ($open): ?>
<form method="post" action="<?= e(url('/admin/applications/' . $a['id'] . '/status')) ?>">
  <?= Csrf::field() ?>
  <label for="note">Review note (optional)</label><textarea id="note" name="note" maxlength="500"></textarea>
  <?php if ($a['existing_volunteer']): ?>
    <div class="flash warning">A volunteer record with this email already exists: <a href="<?= e(url('/volunteers/' . $a['existing_volunteer']['id'])) ?>"><?= e($a['existing_volunteer']['full_name']) ?></a>.</div>
    <label class="checks"><input type="checkbox" name="confirm_link" value="1"> This is the same person: link the application to that record</label>
  <?php endif; ?>
  <div class="actions">
    <button class="btn" name="action" value="approve">Approve</button>
    <?php if ($a['status'] === 'pending'): ?><button class="btn secondary" name="action" value="under_review">Mark under review</button><?php endif; ?>
    <button class="btn danger" name="action" value="rejected">Reject</button>
    <button class="btn secondary" name="action" value="withdrawn">Withdrawn by applicant</button>
  </div>
</form>
<?php else: ?><p>This application is closed.</p><?php if ($a['review_notes']): ?><p><strong>Note:</strong> <?= e($a['review_notes']) ?></p><?php endif; ?><?php endif; ?>
<h3>History</h3><table><thead><tr><th>When</th><th>Change</th><th>By</th><th>Note</th></tr></thead><tbody>
<?php foreach ($a['history'] as $h): ?><tr><td><?= e(local_dt($h['changed_at'])) ?></td><td><?= e(label($h['from_status'] ?? 'new')) ?> → <?= e(label($h['to_status'])) ?></td>
<td><?= e($h['changed_by_email'] ?? 'Applicant') ?></td><td><?= e($h['note'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table>
</div></div>
