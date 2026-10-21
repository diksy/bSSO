<?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/account/password" class="card stack form-narrow">
  <?= csrf_field() ?>
  <div class="field"><label for="current">Kata sandi saat ini</label><input id="current" name="current" type="password" autocomplete="current-password" required></div>
  <div class="field"><label for="new">Kata sandi baru</label><input id="new" name="new" type="password" autocomplete="new-password" required><p class="hint">Minimal 12 karakter.</p></div>
  <div class="field"><label for="confirm">Ulangi kata sandi baru</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" required></div>
  <div><button class="btn btn-primary" type="submit">Ganti kata sandi</button></div>
</form>
