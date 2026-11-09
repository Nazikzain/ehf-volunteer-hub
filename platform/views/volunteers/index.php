<h1>Volunteers</h1>
<form class="card filters" method="get">
  <div><label for="q">Search</label><input id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Name or email"></div>
  <div><label for="status">Status</label><select id="status" name="status"><option value="">All</option><?php foreach (App\Services\VolunteerService::STATUSES as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
  <div><label for="skill">Skill</label><select id="skill" name="skill"><option value="">Any</option><?php foreach ($skills as $s): ?><option value="<?= (int) $s['id'] ?>"<?= $f['skill'] === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div><label for="project">Project</label><select id="project" name="project"><option value="">Any</option><?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $f['project'] === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
  <div><button class="btn">Filter</button></div>
</form>
<div class="card table-wrap"><p class="muted"><?= (int) $total ?> volunteer(s)</p>
<?php if (!$rows): ?><p class="empty">No volunteers match these filters.</p><?php else: ?>
<table><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Skills</th><th>Status</th><th>Since</th></tr></thead><tbody>
<?php foreach ($rows as $v): ?><tr><td><a href="<?= e(url('/volunteers/' . $v['id'])) ?>"><?= e($v['full_name']) ?></a></td><td><?= e($v['email']) ?></td><td><?= e($v['phone'] ?? '') ?></td>
<td><?= e($v['skills'] ?? '') ?></td><td><span class="badge"><?= e(label($v['status'])) ?></span></td><td><?= e(fmt_date($v['start_date'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
<?= App\Core\View::partial('partials/pager', compact('page', 'pages')) ?></div>
