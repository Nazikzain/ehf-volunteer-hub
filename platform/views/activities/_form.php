<label for="title">Title</label><input id="title" name="title" maxlength="180" required value="<?= e($a['title'] ?? '') ?>">
<label for="adesc">Description</label><textarea id="adesc" name="description"><?= e($a['description'] ?? '') ?></textarea>
<div class="grid grid-3"><div><label for="activity_date">Date</label><input id="activity_date" type="date" name="activity_date" required value="<?= e($a['activity_date'] ?? '') ?>"></div>
<div><label for="start_time">Start</label><input id="start_time" type="time" name="start_time" required value="<?= e(substr($a['start_time'] ?? '', 0, 5)) ?>"></div>
<div><label for="end_time">End</label><input id="end_time" type="time" name="end_time" required value="<?= e(substr($a['end_time'] ?? '', 0, 5)) ?>"></div></div>
<label for="venue">Venue</label><input id="venue" name="venue" maxlength="200" required value="<?= e($a['venue'] ?? '') ?>">
<?php if ($leads): ?><label for="lead">Lead supervisor</label><select id="lead" name="lead_supervisor_id"><option value="">None</option><?php foreach ($leads as $l): ?><option value="<?= (int) $l['id'] ?>"<?= (string) ($a['lead_supervisor_id'] ?? '') === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['email']) ?></option><?php endforeach; ?></select><?php endif; ?>
