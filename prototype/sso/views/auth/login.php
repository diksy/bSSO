<h1>Masuk</h1>
<p class="muted">Satu akun untuk semua aplikasi organisasi Anda.</p>
<?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/login" class="stack" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= e($next) ?>">
  <div class="field">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="username" required value="<?= e($email) ?>">
  </div>
  <div class="field">
    <label for="password">Kata sandi</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
  </div>
  <button class="btn btn-primary btn-block" type="submit">Masuk</button>
</form>
<div class="divider"><span>atau lanjutkan dengan</span></div>
<ul class="provider-list">
  <?php foreach ($providers as $p): ?>
    <li><a class="btn btn-secondary btn-block" href="/federation/<?= e(rawurlencode($p['slug'])) ?>/start"><span class="pico" aria-hidden="true"><?= e(mb_substr($p['name'], 0, 1)) ?></span> <?= e($p['name']) ?></a></li>
  <?php endforeach; ?>
</ul>
<details class="demo-hint">
  <summary>Akun demo</summary>
  <p>Kata sandi: <code>demo12345</code> · Kode MFA: <code>123456</code></p>
  <ul>
    <li><code>admin@contoh.test</code> (superadmin)</li>
    <li><code>operator@contoh.test</code>, <code>auditor@contoh.test</code></li>
    <li><code>budi@contoh.test</code> (pengguna biasa, MFA)</li>
    <li><code>citra@contoh.test</code> (tanpa MFA)</li>
    <li><code>tim@contoh.test</code> (operator tanpa MFA: admin ditolak)</li>
    <li><code>eko@contoh.test</code> (ditangguhkan)</li>
  </ul>
</details>
