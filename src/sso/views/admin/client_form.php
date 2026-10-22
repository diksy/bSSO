<?php $w = $perm === 'manage'; $c = $c ?? null; $dis = $w ? '' : ' disabled'; $isNew = $c === null || ($c['id'] ?? '') === ''; ?>
<?php if (!empty($error)): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/admin/clients<?= $isNew ? '' : '/' . e(rawurlencode($c['id'])) ?>" class="card stack form-wide">
  <?= csrf_field() ?>
  <input type="hidden" name="back" value="/admin/clients">
  <div class="field"><label for="name">Nama</label><input id="name" name="name" value="<?= e($c['name'] ?? '') ?>" required<?= $dis ?>></div>
  <div class="field"><label for="type">Tipe</label>
    <select id="type" name="type"<?= $isNew ? $dis : ' disabled' ?>><?php foreach (['confidential' => 'confidential', 'public' => 'public (PKCE)'] as $t => $tl): ?><option value="<?= e($t) ?>"<?= ($c['type'] ?? '') === $t ? ' selected' : '' ?>><?= e($tl) ?></option><?php endforeach; ?></select><?php if (!$isNew): ?><p class="hint">Tipe klien tidak bisa diubah.</p><?php endif; ?></div>
  <fieldset class="field"><legend>Grant</legend>
    <?php foreach (['authorization_code', 'refresh_token', 'client_credentials'] as $g): ?>
      <label class="choice"><input type="checkbox" name="grants[]" value="<?= e($g) ?>"<?= in_array($g, $c['grants'] ?? [], true) ? ' checked' : '' ?><?= $dis ?>> <?= e($g) ?></label>
    <?php endforeach; ?>
  </fieldset>
  <div class="field"><label for="scopes">Scope (pisahkan spasi)</label><input id="scopes" name="scopes" value="<?= e(implode(' ', $c['scopes'] ?? [])) ?>"<?= $dis ?>></div>
  <div class="grid-2">
    <div class="field"><label for="att">TTL access token (detik)</label><input id="att" name="access_ttl" type="number" value="<?= e($c['access_ttl'] ?? 900) ?>"<?= $dis ?>></div>
    <div class="field"><label for="rtt">TTL refresh token (detik)</label><input id="rtt" name="refresh_ttl" type="number" value="<?= e($c['refresh_ttl'] ?? 2592000) ?>"<?= $dis ?>></div>
  </div>
  <div class="field"><label for="rl">Redirect URI login (satu per baris)</label><textarea id="rl" name="redirect_login" rows="3"<?= $dis ?>><?= e(implode("\n", $c['redirect_login'] ?? [])) ?></textarea><p class="hint">Mengubah redirect URI atau grant meminta verifikasi MFA ulang.</p></div>
  <div class="field"><label for="rp">Redirect URI post-logout</label><textarea id="rp" name="redirect_logout" rows="2"<?= $dis ?>><?= e(implode("\n", $c['redirect_logout'] ?? [])) ?></textarea></div>
  <div class="field"><label for="bc">Back-channel logout URI</label><input id="bc" name="backchannel" value="<?= e($c['backchannel'] ?? '') ?>"<?= $dis ?>></div>
  <?php if ($w): ?><div class="row"><button class="btn btn-primary" type="submit"><?= $isNew ? 'Buat klien' : 'Simpan' ?></button><a class="btn btn-secondary" href="/admin/clients">Batal</a></div><?php endif; ?>
</form>
<?php if (!$isNew && $w): ?>
<section class="card section">
  <h2>Tindakan klien</h2>
  <div class="row">
    <form method="post" action="/admin/clients/<?= e(rawurlencode($c['id'])) ?>/regenerate-secret"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>"><input type="hidden" name="back" value="/admin/clients"><button class="btn btn-secondary" type="submit">Regenerasi secret</button></form>
    <form method="post" action="/admin/clients/<?= e(rawurlencode($c['id'])) ?>/test-logout"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>"><input type="hidden" name="back" value="/admin/clients/<?= e(rawurlencode($c['id'])) ?>"><button class="btn btn-secondary" type="submit">Kirim logout token uji</button></form>
    <form method="post" action="/admin/clients/<?= e(rawurlencode($c['id'])) ?>/toggle"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>"><input type="hidden" name="back" value="/admin/clients"><button class="btn btn-danger" type="submit"><?= $c['active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
  </div>
  <p class="hint">Secret tidak pernah ditampilkan ulang; secret baru muncul sekali setelah regenerasi.</p>
</section>
<?php endif; ?>
