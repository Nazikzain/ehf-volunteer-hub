<h1>Integrity report</h1>
<div class="flash <?= $rep['violations'] ? 'warning' : 'success' ?>"><?= (int) $rep['violations'] ?> issue(s) found at <?= e(local_dt($rep['checked_at'])) ?>. This report only lists problems; it never changes data.</div>
<div class="card table-wrap"><table><thead><tr><th>Rule</th><th>Issues</th><th>Examples (ids)</th></tr></thead><tbody><?php foreach ($rep['checks'] as $c): ?><tr><td><?= e($c['check']) ?></td><td><?= $c['count'] ? '<span class="badge warn">' . (int) $c['count'] . '</span>' : '<span class="badge ok">0</span>' ?></td>
<td><?= e(implode('; ', array_map(fn($r) => implode(',', $r), array_slice($c['sample'], 0, 5)))) ?></td></tr><?php endforeach; ?></tbody></table></div>
