<h1>Dashboard</h1>
<?php if (!empty($admin)): $k = $admin; ?>
<?php if ($k['fallback_pending'] > 0): ?><div class="flash error">Audit fallback events are waiting for reconciliation. <a href="<?= e(url('/admin/audit')) ?>">Open the audit log</a>.</div><?php endif; ?>
<div class="grid grid-4">
<?php foreach ([['volunteers_active','Active volunteers'],['volunteers_total','Total volunteers'],['apps_pending','Pending applications'],['apps_review','Applications under review'],
  ['projects_active','Active projects'],['activities_upcoming','Upcoming activities'],['hours_pending','Hours awaiting approval'],['certificates_issued','Certificates issued']] as [$key, $lbl]): ?>
<div class="kpi"><div class="v"><?= (int) $k[$key] ?></div><div class="l"><?= e($lbl) ?></div></div><?php endforeach; ?>
<div class="kpi"><div class="v"><?= e(minutes_to_hours($k['minutes_approved'])) ?></div><div class="l">Approved volunteer hours (all time)</div></div>
<div class="kpi"><div class="v"><?= $k['attendance'] === null ? '—' : e($k['attendance']) . '%' ?></div><div class="l">Attendance rate (completed activities)</div></div>
<div class="kpi"><div class="v"><?= (int) $k['missing_attendance'] ?></div><div class="l">Past rosters without attendance</div></div>
<div class="kpi"><div class="v"><?= (int) $k['training_expiring'] ?></div><div class="l">Training expiring in 30 days</div></div>
</div>
<div class="grid grid-2">
<div class="card"><h2 class="section-title">Approved hours by month</h2>
<?php if (!$k['monthly']): ?><p class="empty">No approved hours yet.</p><?php else: $max = max(array_column($k['monthly'], 'minutes')) ?: 1; ?>
<div class="chart" role="img" aria-label="Approved hours per month"><?php foreach ($k['monthly'] as $m): ?><div class="col"><span class="h<?= (int) round(10 * $m['minutes'] / $max) ?>"></span><?= e(minutes_to_hours($m['minutes'])) ?>h<br><?= e($m['ym']) ?></div><?php endforeach; ?></div>
<?php endif; ?></div>
<div class="card"><h2 class="section-title">Applications by month</h2>
<?php if (!$k['applications_monthly']): ?><p class="empty">No applications yet.</p><?php else: ?><table><thead><tr><th>Month</th><th>Applications</th></tr></thead><tbody><?php foreach ($k['applications_monthly'] as $m): ?><tr><td><?= e($m['ym']) ?></td><td><?= (int) $m['n'] ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
</div>
<?php if ($k['activities_no_lead'] > 0): ?><div class="flash warning"><?= (int) $k['activities_no_lead'] ?> upcoming activit(ies) have no lead supervisor.</div><?php endif; ?>
<?php endif; ?>
<?php if (!empty($sup)): ?>
<div class="grid grid-3"><div class="kpi"><div class="v"><?= count($sup['projects']) ?></div><div class="l">Projects I supervise</div></div>
<div class="kpi"><div class="v"><?= (int) $sup['hours_pending'] ?></div><div class="l">Hours awaiting my approval</div></div>
<div class="kpi"><div class="v"><?= (int) $sup['missing_attendance'] ?></div><div class="l">Past rosters without attendance</div></div></div>
<div class="card"><h2 class="section-title">My projects</h2><?php if (!$sup['projects']): ?><p class="empty">You are not supervising any project at the moment.</p><?php else: ?><ul><?php foreach ($sup['projects'] as $p): ?><li><a href="<?= e(url('/projects/' . $p['id'])) ?>"><?= e($p['name']) ?></a> · <?= (int) $p['vols'] ?> active volunteer(s)</li><?php endforeach; ?></ul><?php endif; ?></div>
<div class="card"><h2 class="section-title">Upcoming activities</h2><?php if (!$sup['activities']): ?><p class="empty">No upcoming activities.</p><?php else: ?><ul><?php foreach ($sup['activities'] as $a): ?><li><a href="<?= e(url('/activities/' . $a['id'])) ?>"><?= e($a['title']) ?></a> · <?= e($a['project_name']) ?> · <?= e(fmt_date($a['activity_date'])) ?> <?= e(substr($a['start_time'], 0, 5)) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
<?php endif; ?>
<?php if (!empty($vol) && $vol['v']): ?>
<p>Welcome, <?= e($vol['v']['full_name']) ?>. Thank you for volunteering with Ethnic Health Forum.</p>
<div class="grid grid-3"><div class="kpi"><div class="v"><?= e(minutes_to_hours($vol['minutes'])) ?></div><div class="l">My approved hours</div></div>
<div class="kpi"><div class="v"><?= (int) $vol['attended'] ?></div><div class="l">Sessions attended</div></div>
<div class="kpi"><div class="v"><?= (int) $vol['pending'] ?></div><div class="l">Hours awaiting approval</div></div></div>
<div class="card"><h2 class="section-title">My upcoming activities</h2><?php if (!$vol['upcoming']): ?><p class="empty">No upcoming activities.</p><?php else: ?><ul><?php foreach ($vol['upcoming'] as $a): ?><li><?= e($a['title']) ?> · <?= e($a['project_name']) ?> · <?= e(fmt_date($a['activity_date'])) ?> <?= e(substr($a['start_time'], 0, 5)) ?>–<?= e(substr($a['end_time'], 0, 5)) ?> · <?= e($a['venue']) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
<?php endif; ?>
<div class="card"><h2 class="section-title">Announcements</h2><?php if (!$announcements): ?><p class="empty">No announcements.</p><?php else: foreach ($announcements as $a): ?><article><h3><?= e($a['title']) ?></h3><p><?= nl2br(e($a['body'])) ?></p><p class="muted"><?= e(local_dt($a['published_at'])) ?></p></article><?php endforeach; endif; ?></div>
