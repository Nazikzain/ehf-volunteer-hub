<?php use App\Core\Csrf; ?>
<h1>Notifications</h1>
<form method="post" action="<?= e(url('/notifications/read')) ?>"><?= Csrf::field() ?><button class="btn small secondary">Mark all as read</button></form>
<div class="card"><?php if (!$rows): ?><p class="empty">You have no notifications.</p><?php else: ?><table><thead><tr><th>When</th><th>Notification</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $n): ?><tr><td><?= e(local_dt($n['created_at'])) ?></td><td><?= $n['read_at'] ? '' : '<span class="badge">New</span> ' ?><?php if ($n['link_path']): ?><a href="<?= e(url($n['link_path'])) ?>"><?= e($n['title']) ?></a><?php else: ?><?= e($n['title']) ?><?php endif; ?><?= $n['body'] ? '<br><span class="muted">' . e($n['body']) . '</span>' : '' ?></td>
<td><?php if (!$n['read_at']): ?><form method="post" action="<?= e(url('/notifications/read')) ?>"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button class="btn small secondary">Mark read</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
