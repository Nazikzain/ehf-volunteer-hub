<?php use App\Core\Csrf; use App\Security\Gate; ?>
<h1>Projects</h1>
<?php if (Gate::allows('projects.manage')): ?><details class="card"><summary>Create a project</summary>
<form method="post" action="<?= e(url('/projects')) ?>"><?= Csrf::field() ?><?= App\Core\View::partial('projects/_form', ['p' => []]) ?><div class="actions"><button class="btn">Create project</button></div></form></details><?php endif; ?>
<form class="card filters" method="get"><div><label for="q">Search</label><input id="q" name="q" value="<?= e($f['q']) ?>"></div>
<div><label for="status">Status</label><select id="status" name="status"><option value="">All</option><?php foreach (App\Services\ProjectService::STATUSES as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div><div><button class="btn">Filter</button></div></form>
<div class="card table-wrap"><?php if (!$projects): ?><p class="empty">No projects to show.</p><?php else: ?>
<table><thead><tr><th>Project</th><th>Status</th><th>Dates</th><th>Active volunteers</th><th>Activities</th><th>Supervisors</th></tr></thead><tbody>
<?php foreach ($projects as $p): ?><tr><td><a href="<?= e(url('/projects/' . $p['id'])) ?>"><?= e($p['name']) ?></a></td><td><span class="badge"><?= e(label($p['status'])) ?></span></td>
<td><?= e(fmt_date($p['start_date'])) ?>–<?= e(fmt_date($p['end_date'])) ?></td><td><?= (int) $p['active_volunteers'] ?></td><td><?= (int) $p['activities'] ?></td><td><?= e($p['supervisors'] ?? '—') ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?></div>
