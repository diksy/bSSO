<section class="card stack form-narrow">
  <h2>Verifikasi MFA ulang</h2>
  <p>Aksi sensitif memerlukan kode TOTP yang baru (maks. 5 menit).<?php if ($pending): ?> Aksi yang menunggu: <strong><?= e($pending['module']) ?> / <?= e($pending['action']) ?></strong>.<?php endif; ?></p>
  <?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/admin/stepup" class="stack">
    <?= csrf_field() ?>
    <div class="field"><label for="code">Kode 6 digit</label><input id="code" name="code" inputmode="numeric" class="otp" autocomplete="one-time-code" required></div>
    <div><button class="btn btn-primary" type="submit">Verifikasi dan lanjutkan</button> <a class="btn btn-secondary" href="/admin">Batal</a></div>
  </form>
</section>
