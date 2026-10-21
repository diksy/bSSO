<h1>Lupa kata sandi</h1>
<p class="muted">Masukkan email akun Anda. Kami kirim tautan untuk mengatur ulang kata sandi.</p>
<?php if (!empty($error)): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/password/forgot" class="stack" novalidate>
  <?= csrf_field() ?>
  <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" required value="<?= e($email) ?>"></div>
  <button class="btn btn-primary btn-block" type="submit">Kirim tautan</button>
</form>
<p class="muted"><a href="/login">Kembali ke halaman masuk</a></p>
