<h1>Daftar</h1>
<p class="muted">Buat akun untuk semua aplikasi organisasi Anda.</p>
<?php if (!empty($error)): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/register" class="stack" novalidate>
  <?= csrf_field() ?>
  <div class="field"><label for="name">Nama</label><input id="name" name="name" autocomplete="name" required maxlength="80" value="<?= e($name) ?>"></div>
  <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" required value="<?= e($email) ?>"></div>
  <div class="field"><label for="password">Kata sandi</label><input id="password" name="password" type="password" autocomplete="new-password" required><p class="hint">Minimal 12 karakter.</p></div>
  <button class="btn btn-primary btn-block" type="submit">Daftar</button>
</form>
<p class="muted"><a href="/login">Sudah punya akun? Masuk</a></p>
