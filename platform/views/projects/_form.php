<label for="name">Project name</label><input id="name" name="name" maxlength="150" required value="<?= e($p['name'] ?? '') ?>">
<label for="description">Description</label><textarea id="description" name="description"><?= e($p['description'] ?? '') ?></textarea>
<div class="grid grid-3">
<div><label for="pstatus">Status</label><select id="pstatus" name="status"><?php foreach (App\Services\ProjectService::STATUSES as $s): ?><option value="<?= $s ?>"<?= ($p['status'] ?? 'planned') === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
<div><label for="start_date">Start date</label><input id="start_date" name="start_date" type="date" value="<?= e($p['start_date'] ?? '') ?>"></div>
<div><label for="end_date">End date</label><input id="end_date" name="end_date" type="date" value="<?= e($p['end_date'] ?? '') ?>"></div></div>
