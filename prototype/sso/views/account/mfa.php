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
  <div class="row">
    <form method="post" action="/account/mfa"><?= csrf_field() ?><input type="hidden" name="action" value="regen"><button class="btn btn-secondary" type="submit">Buat ulang kode cadangan</button></form>
    <form method="post" action="/account/mfa"><?= csrf_field() ?><input type="hidden" name="action" value="disable"><button class="btn btn-danger" type="submit">Nonaktifkan MFA</button></form>
  </div>
</section>
<?php else: ?>
<section class="card stack form-narrow">
  <p><span class="badge badge-warn">MFA belum aktif</span></p>
  <ol>
    <li>Pasang aplikasi autentikator (mis. FreeOTP atau Aegis).</li>
    <li>Pindai kode QR di bawah, atau masukkan kunci manual.</li>
    <li>Masukkan 6 digit yang muncul.</li>
  </ol>
  <img class="qr" src="<?= e(asset('img/qr-placeholder.svg')) ?>" width="180" height="180" alt="Placeholder kode QR (prototype, bukan kode asli)">
  <p>Kunci manual (dummy): <code><?= e($secret) ?></code></p>
  <?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/account/mfa" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="enable">
    <div class="field"><label for="code">Kode 6 digit</label><input id="code" name="code" inputmode="numeric" class="otp" autocomplete="one-time-code" required><p class="hint">Demo: <code>123456</code></p></div>
    <div><button class="btn btn-primary" type="submit">Aktifkan MFA</button></div>
  </form>
</section>
<?php endif; ?>
