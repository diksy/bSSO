<?php if (!empty($error)): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/account" class="card stack form-narrow">
  <?= csrf_field() ?>
  <div class="field"><label for="name">Nama</label><input id="name" name="name" value="<?= e($user['name']) ?>" required maxlength="80"></div>
  <div class="field"><label for="email">Email</label><input id="email" value="<?= e($user['email']) ?>" disabled><p class="hint">Email tidak dapat diubah.</p></div>
  <p class="muted">Bergabung <?= e($user['created']) ?> · Masuk terakhir <?= e($user['last_login']) ?></p>
  <div><button class="btn btn-primary" type="submit">Simpan</button></div>
</form>
