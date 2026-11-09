<?php if (($pages ?? 1) > 1): ?><nav class="actions" aria-label="Pages">
<?php for ($i = 1; $i <= $pages; $i++): ?><a class="btn small <?= $i === $page ? '' : 'secondary' ?>" href="<?= e(url($request->path, ['page' => $i] + $request->query)) ?>"><?= $i ?></a><?php endfor; ?>
</nav><?php endif; ?>
