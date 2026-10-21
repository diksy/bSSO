<p class="muted">Perangkat dan peramban yang sedang masuk ke akun Anda.</p>
<ul class="list-card">
<?php foreach ($sessions as $s): ?>
  <li>
    <span class="grow"><strong><?= e($s['device']) ?></strong> <?php if ($s['current']): ?><span class="badge badge-ok">Sesi ini</span><?php endif; ?><br>
      <span class="muted">IP <?= e($s['ip']) ?> · mulai <?= e($s['started']) ?> · aktif <?= e($s['last']) ?></span></span>
    <?php if (!$s['current']): ?>
      <form method="post" action="/account/sessions/<?= e(rawurlencode($s['id'])) ?>/revoke"><?= csrf_field() ?><button class="btn btn-secondary btn-sm" type="submit">Cabut</button></form>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
<form method="post" action="/account/sessions/revoke-all" class="section">
  <?= csrf_field() ?>
  <button class="btn btn-danger" type="submit">Keluar dari semua perangkat</button>
</form>
