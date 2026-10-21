<h1>Atur ulang kata sandi</h1>
<?php if (!empty($error)): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/password/reset" class="stack" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <div class="field"><label for="new">Kata sandi baru</label><input id="new" name="new" type="password" autocomplete="new-password" required><p class="hint">Minimal 12 karakter.</p></div>
  <div class="field"><label for="confirm">Ulangi kata sandi baru</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" required></div>
  <button class="btn btn-primary btn-block" type="submit">Simpan kata sandi</button>
</form>
