<h1>Konfirmasi tautan akun</h1>
<p>Sudah ada akun BikininSSO dengan email <strong><?= e($target['email']) ?></strong>, tetapi <?= e($provider['name']) ?> belum dapat memastikan kepemilikannya. Demi keamanan, masuk dulu dengan cara lama Anda untuk menautkan <?= e($provider['name']) ?>.</p>
<?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/federation/<?= e(rawurlencode($provider['slug'])) ?>/confirm-link" class="stack">
  <?= csrf_field() ?>
  <div class="field">
    <label for="password">Kata sandi untuk <?= e($target['email']) ?></label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <p class="hint">Demo: <code>demo12345</code>. Jika MFA aktif, Anda tetap diminta kode setelah ini.</p>
  </div>
  <button class="btn btn-primary btn-block" type="submit">Konfirmasi dan tautkan</button>
</form>
<p class="center"><a href="/login">Batal dan kembali</a></p>
