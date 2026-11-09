<?php use App\Core\Csrf; ?>
<h1>Activities</h1>
<?php if ($manageable): ?><details class="card"><summary>Schedule an activity</summary>
<form method="post" action="<?= e(url('/activities')) ?>"><?= Csrf::field() ?>
<label for="project_id">Project</label><select id="project_id" name="project_id"><?php foreach ($manageable as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select>
<?= App\Core\View::partial('activities/_form', ['a' => [], 'leads' => []]) ?><div class="actions"><button class="btn">Schedule</button></div></form>
<p class="hint">Times are local (UK) time. You can choose a lead supervisor after scheduling.</p></details><?php endif; ?>
<form class="card filters" method="get">
<div><label for="fp">Project</label><select id="fp" name="project"><option value="">All</option><?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $f['project'] === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
<div><label for="fs">Status</label><select id="fs" name="status"><option value="">All</option><?php foreach (['scheduled','completed','cancelled'] as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
<div><label for="ff">From</label><input id="ff" type="date" name="from" value="<?= e($f['from']) ?>"></div><div><label for="ft">To</label><input id="ft" type="date" name="to" value="<?= e($f['to']) ?>"></div>
<div><button class="btn">Filter</button></div></form>
<div class="card table-wrap"><?php if (!$rows): ?><p class="empty">No activities.</p><?php else: ?><table><thead><tr><th>Date</th><th>Time</th><th>Activity</th><th>Project</th><th>Venue</th><th>Lead</th><th>Roster</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $a): ?><tr><td><?= e(fmt_date($a['activity_date'])) ?></td><td><?= e(substr($a['start_time'], 0, 5)) ?>–<?= e(substr($a['end_time'], 0, 5)) ?></td><td><a href="<?= e(url('/activities/' . $a['id'])) ?>"><?= e($a['title']) ?></a></td>
<td><?= e($a['project_name']) ?></td><td><?= e($a['venue']) ?></td><td><?= e($a['lead_email'] ?? '—') ?></td><td><?= (int) $a['recorded'] ?>/<?= (int) $a['rostered'] ?> recorded</td><td><?= e(label($a['status'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
