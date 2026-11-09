<?php $cls = ['green' => 'success', 'amber' => 'warning', 'red' => 'error']; ?>
<p><a href="<?= e(url('/admin/audit')) ?>">← Audit log</a></p><h1>Audit verification</h1>
<div class="flash <?= $cls[$rep['result']] ?>">Result: <strong><?= e(strtoupper($rep['result'])) ?></strong>. <?= (int) $rep['checked'] ?> entries checked (<?= e($rep['from'] ?? '—') ?>–<?= e($rep['to'] ?? '—') ?>). Head: sequence <?= e($rep['head']['seq'] ?? '—') ?>.</div>
<div class="card"><h2 class="section-title">Problems</h2><?php if (!$rep['problems']): ?><p>None found.</p><?php else: ?><ul><?php foreach ($rep['problems'] as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul><?php endif; ?>
<h2 class="section-title">Warnings</h2><?php if (!$rep['warnings']): ?><p>None.</p><?php else: ?><ul><?php foreach ($rep['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul><?php endif; ?>
<p class="hint">Head hash: <code><?= e($rep['head']['hash'] ?? '') ?></code></p></div>
