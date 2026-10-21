<h1>Cek email Anda</h1>
<p>Tautan verifikasi dikirim ke <strong><?= e($email) ?></strong>. Akun aktif setelah tautan dibuka.</p>
<div class="alert alert-info">Simulasi: tidak ada email dikirim. Tekan tombol di bawah untuk meniru klik tautan verifikasi.</div>
<form method="post" action="/federation/<?= e(rawurlencode($provider['slug'])) ?>/verify-email">
  <?= csrf_field() ?>
  <button class="btn btn-primary btn-block" type="submit">Buka tautan verifikasi</button>
</form>
