<?php if ($done): ?>
<h1>Pemasangan selesai</h1>
<div class="alert alert-ok" role="status">Superadmin pertama dibuat dan <code>storage/install.lock</code> ditulis. Halaman ini nonaktif sekarang.</div>
<p><a class="btn btn-primary btn-block" href="/login">Ke halaman masuk</a></p>
<?php else: ?>
<h1>Pasang BikininSSO</h1>
<div class="alert alert-info">Halaman ini hanya aktif selama tabel <code>admin_roles</code> kosong dan <code>install.lock</code> belum ada. Buat superadmin pertama.</div>
<?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/install" class="stack">
  <?= csrf_field() ?>
  <?php if (!empty($needToken)): ?><div class="field"><label for="install_token">Token pemasangan</label><input id="install_token" name="install_token" type="password" autocomplete="off" required></div><?php endif; ?>
  <div class="field"><label for="email">Email superadmin</label><input id="email" name="email" type="email" required></div>
  <div class="field"><label for="password">Kata sandi (min. 12 karakter)</label><input id="password" name="password" type="password" autocomplete="new-password" required></div>
  <div class="field"><label for="password2">Ulangi kata sandi</label><input id="password2" name="password2" type="password" autocomplete="new-password" required></div>
  <button class="btn btn-primary btn-block" type="submit">Buat superadmin</button>
</form>
<?php endif; ?>
