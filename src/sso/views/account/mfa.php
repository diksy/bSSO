<?php if ($codes): ?>
<section class="card">
  <h2>Kode cadangan</h2>
  <div class="alert alert-warn">Simpan kode ini di tempat aman. Setiap kode hanya berlaku sekali dan tidak akan ditampilkan lagi.</div>
  <ul class="codes">
    <?php foreach ($codes as $c): ?><li><code><?= e($c) ?></code></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php if ($user['mfa']): ?>
<section class="card stack">
  <p><span class="badge badge-ok">MFA aktif</span> Akun Anda dilindungi kode TOTP.</p>
  <?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/account/mfa/backup-codes" class="stack">
    <?= csrf_field() ?>
    <div class="field"><label for="pw-regen">Kata sandi</label><input id="pw-regen" type="password" name="password" autocomplete="current-password" required></div>
    <div><button class="btn btn-secondary" type="submit">Buat ulang kode cadangan</button></div>
  </form>
  <form method="post" action="/account/mfa/disable" class="stack">
    <?= csrf_field() ?>
    <div class="field"><label for="pw-off">Kata sandi</label><input id="pw-off" type="password" name="password" autocomplete="current-password" required></div>
    <div><button class="btn btn-danger" type="submit">Nonaktifkan MFA</button></div>
  </form>
</section>
<?php else: ?>
<section class="card stack form-narrow">
  <p><span class="badge badge-warn">MFA belum aktif</span></p>
  <ol>
    <li>Pasang aplikasi autentikator (mis. FreeOTP atau Aegis).</li>
    <li>Pindai kode QR di bawah, atau masukkan kunci manual.</li>
    <li>Masukkan 6 digit yang muncul.</li>
  </ol>
  <?php if ($qr): ?><p><img src="<?= e($qr) ?>" alt="Kode QR untuk aplikasi autentikator" width="200" height="200"></p><?php endif; ?>
  <p>Kunci manual: <code><?= e($secret) ?></code></p>
  <p class="hint">URI otpauth: <code><?= e($otpauthUri) ?></code></p>
  <?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/account/mfa/enable" class="stack">
    <?= csrf_field() ?>
    <div class="field"><label for="code">Kode 6 digit</label><input id="code" name="code" inputmode="numeric" class="otp" autocomplete="one-time-code" required></div>
    <div><button class="btn btn-primary" type="submit">Aktifkan MFA</button></div>
  </form>
</section>
<?php endif; ?>
