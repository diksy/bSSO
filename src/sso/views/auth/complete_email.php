<h1>Lengkapi email Anda</h1>
<p><?= e($provider['name']) ?> tidak membagikan alamat email. Masukkan email Anda; kami mengirim tautan verifikasi sebelum akun diaktifkan.</p>
<?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/federation/<?= e(rawurlencode($provider['slug'])) ?>/complete-email" class="stack">
  <?= csrf_field() ?>
  <div class="field">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="email" required>
  </div>
  <button class="btn btn-primary btn-block" type="submit">Kirim tautan verifikasi</button>
</form>
