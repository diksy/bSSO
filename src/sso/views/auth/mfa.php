<h1>Verifikasi dua langkah</h1>
<p class="muted">Masukkan 6 digit dari aplikasi autentikator Anda, atau salah satu kode cadangan.</p>
<?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/login/mfa" class="stack">
  <?= csrf_field() ?>
  <div class="field">
    <label for="code">Kode verifikasi</label>
    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required class="otp" maxlength="12">
  </div>
  <button class="btn btn-primary btn-block" type="submit">Verifikasi</button>
</form>
<p class="center"><a href="/login">Kembali ke halaman masuk</a></p>
