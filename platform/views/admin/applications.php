<h1>Volunteer applications</h1>
<form class="card filters" method="get">
  <div><label for="q">Search</label><input id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Name, email or reference"></div>
  <div><label for="status">Status</label><select id="status" name="status"><option value="">All</option>
    <?php foreach (['pending','under_review','approved','rejected','withdrawn'] as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
  <div><button class="btn" type="submit">Filter</button></div>
</form>
<div class="card table-wrap">
<p class="muted"><?= (int) $total ?> application(s)</p>
<?php if (!$rows): ?><p class="empty">No applications match these filters.</p><?php else: ?>
<table><thead><tr><th>Reference</th><th>Name</th><th>Email</th><th>Skills</th><th>Submitted</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $a): ?><tr>
  <td><a href="<?= e(url('/admin/applications/' . $a['id'])) ?>"><?= e($a['reference']) ?></a></td><td><?= e($a['full_name']) ?></td><td><?= e($a['email']) ?></td>
  <td><?= e($a['skills']) ?></td><td><?= e(local_dt($a['submitted_at'])) ?></td><td><span class="badge"><?= e(label($a['status'])) ?></span></td></tr>
<?php endforeach; ?></tbody></table><?php endif; ?>
<?= App\Core\View::partial('partials/pager', compact('page', 'pages')) ?>
</div>
