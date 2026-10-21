<div class="table-wrap"><table class="table">
<thead><tr><th>kid</th><th>Status</th><th>Dibuat</th><th>Aktif sejak</th><th>Pensiun</th></tr></thead><tbody>
<?php foreach ($keys as $k): ?>
<tr><td><code><?= e($k['kid']) ?></code></td><td><span class="badge <?= $k['status'] === 'active' ? 'badge-ok' : ($k['status'] === 'retired' ? '' : 'badge-warn') ?>"><?= e($k['status']) ?></span></td><td><?= e($k['created']) ?></td><td><?= e($k['activate']) ?></td><td><?= e($k['retire']) ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if ($perm === 'manage'): ?>
<div class="grid-2 section">
  <section class="card stack"><h2>Rotasi manual</h2><p>Menjadikan kunci <code>next</code> aktif dan membuat kunci <code>next</code> baru. Meminta verifikasi MFA ulang.</p>
    <form method="post" action="/admin/keys/rotate"><?= csrf_field() ?><input type="hidden" name="back" value="/admin/keys"><button class="btn btn-secondary" type="submit">Rotasi sekarang</button></form></section>
  <section class="card stack"><h2>Rotasi darurat</h2><p>Untuk dugaan kunci bocor: kunci aktif langsung dipensiunkan. Ketik <code>ROTATE</code> untuk melanjutkan.</p>
    <form method="post" action="/admin/keys/rotate-emergency" class="stack"><?= csrf_field() ?><input type="hidden" name="back" value="/admin/keys">
      <div class="field"><label for="confirm">Ketik ROTATE</label><input id="confirm" name="confirm" autocomplete="off" required></div>
      <div><button class="btn btn-danger" type="submit">Rotasi darurat</button></div></form></section>
</div>
<?php endif; ?>
