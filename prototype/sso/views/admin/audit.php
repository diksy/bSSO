<form method="get" action="/admin/audit" class="card filters">
  <div class="field"><label for="actor">Pelaku</label><input id="actor" name="actor" value="<?= e($f['actor']) ?>"></div>
  <div class="field"><label for="target">Target</label><input id="target" name="target" value="<?= e($f['target']) ?>"></div>
  <div class="field"><label for="event">Event</label><input id="event" name="event" value="<?= e($f['event']) ?>" placeholder="mis. login"></div>
  <div class="field"><label for="from">Dari</label><input id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
  <div class="field"><label for="to">Sampai</label><input id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
  <div class="filters-actions"><button class="btn btn-primary" type="submit">Filter</button>
  <?php if ($perm['label'] === 'Baca + ekspor'): ?><a class="btn btn-secondary" href="/admin/audit/export?<?= e($qs) ?>">Ekspor CSV</a><?php endif; ?></div>
</form>
<p class="muted"><?= e($total) ?> entri · halaman <?= e($page) ?> dari <?= e($pages) ?></p>
<div class="table-wrap"><table class="table">
<thead><tr><th>Waktu</th><th>Pelaku</th><th>Event</th><th>Target</th><th>Konteks</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
<tr><td><?= e($r['time']) ?></td><td><?= e($r['actor']) ?></td><td><code><?= e($r['event']) ?></code></td><td><?= e($r['target']) ?></td>
<td><?php if ($r['context']): ?><details><summary>Rincian</summary><pre><?= e(json_encode($r['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php else: ?><span class="muted">–</span><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="muted">Tidak ada entri.</td></tr><?php endif; ?>
</tbody></table></div>
<nav class="pager" aria-label="Paginasi">
  <?php $base = '/admin/audit?' . ($qs !== '' ? $qs . '&' : ''); ?>
  <?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="<?= e($base . 'page=' . ($page - 1)) ?>">Sebelumnya</a><?php endif; ?>
  <?php if ($page < $pages): ?><a class="btn btn-secondary btn-sm" href="<?= e($base . 'page=' . ($page + 1)) ?>">Berikutnya</a><?php endif; ?>
</nav>
