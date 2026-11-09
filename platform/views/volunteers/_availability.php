<?php $have = []; foreach ($availability as $a) { $have[$a['weekday'] . ':' . $a['period']] = true; } ?>
<div class="table-wrap"><table><thead><tr><th>Day</th><?php foreach (App\Services\VolunteerService::PERIODS as $p): ?><th><?= e(label($p)) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach (App\Services\ApplicationService::DAYS as $n => $d): ?><tr><th scope="row"><?= e($d) ?></th>
<?php foreach (App\Services\VolunteerService::PERIODS as $p): $k = $n . ':' . $p; ?><td><input type="checkbox" name="slots[]" value="<?= e($k) ?>" aria-label="<?= e($d . ' ' . $p) ?>"<?= isset($have[$k]) ? ' checked' : '' ?><?= $readonly ?? false ? ' disabled' : '' ?>></td><?php endforeach; ?></tr>
<?php endforeach; ?></tbody></table></div>
