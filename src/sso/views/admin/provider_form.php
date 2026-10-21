<?php $w = $perm === 'manage'; $dis = $w ? '' : ' disabled'; $back = '/admin/providers/' . rawurlencode($pr['slug']); ?>
<form method="post" action="/admin/providers/<?= e(rawurlencode($pr['slug'])) ?>" class="card stack form-wide" enctype="multipart/form-data">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pr['slug']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
  <label class="choice"><input type="checkbox" name="active" value="1"<?= $pr['active'] ? ' checked' : '' ?><?= $dis ?>> Aktif (tombol tampil di halaman masuk)</label>
  <div class="field"><label for="order">Urutan tombol</label><input id="order" name="order" type="number" value="<?= e($pr['order']) ?>"<?= $dis ?>></div>
  <div class="field"><label for="cid">Client ID</label><input id="cid" name="client_id" value="<?= e($pr['client_id']) ?>"<?= $dis ?>></div>
  <div class="field"><label for="sec">Client secret</label><input id="sec" name="secret" type="password" autocomplete="new-password" value="" placeholder="<?= $pr['secret_set'] ? 'Tersimpan. Kosongkan untuk mempertahankan' : 'Belum diisi' ?>"<?= $dis ?>><p class="hint">Write-only: nilai lama tidak pernah dikirim ke peramban.</p></div>
  <label class="choice"><input type="checkbox" name="trust_email" value="1"<?= $pr['trust_email'] ? ' checked' : '' ?><?= $dis ?>> Percayai email dari provider ini (<code>trust_email</code>)</label>
  <div class="field"><label for="extra">extra_config (JSON)</label><textarea id="extra" name="extra" rows="3"<?= $dis ?>><?= e($pr['extra']) ?></textarea></div>
  <?php if ($pr['slug'] === 'apple'): ?><div class="field"><label for="p8">File .p8 Apple</label><input id="p8" name="p8" type="file"<?= $dis ?>><p class="hint">Disimpan di <code>keys/</code>, bukan di database.</p></div><?php endif; ?>
  <div class="field"><label for="cb">URL callback (daftarkan di console provider)</label><input id="cb" value="<?= e($callback) ?>" readonly></div>
  <?php if ($w): ?><div class="row"><button class="btn btn-primary" type="submit">Simpan</button><a class="btn btn-secondary" href="/admin/providers">Batal</a></div><?php endif; ?>
</form>
<?php if ($w): ?>
<form method="post" action="/admin/providers/<?= e(rawurlencode($pr['slug'])) ?>/test-connection" class="section"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($pr['slug']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><button class="btn btn-secondary" type="submit">Tes koneksi (discovery + JWKS)</button></form>
<?php endif; ?>
