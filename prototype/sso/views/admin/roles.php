<section class="card">
  <h2>Admin saat ini</h2>
  <div class="table-wrap"><table class="table">
  <thead><tr><th>User</th><th>Role</th><th><span class="visually-hidden">Aksi</span></th></tr></thead><tbody>
  <?php foreach ($admins as $a): ?>
  <tr><td><?= e($a['email']) ?><?= $a['email'] === $user['email'] ? ' <span class="badge">Anda</span>' : '' ?></td><td><?= e($a['admin_role']) ?></td>
  <td><div class="row">
    <form method="post" action="/admin/roles/grant" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($a['id']) ?>"><input type="hidden" name="back" value="/admin/roles">
      <label class="visually-hidden" for="r<?= e($a['id']) ?>">Role baru untuk <?= e($a['email']) ?></label>
      <select id="r<?= e($a['id']) ?>" name="role"><?php foreach ($matrix['roles'] as $k => $lb): ?><option value="<?= e($k) ?>"<?= $k === $a['admin_role'] ? ' selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?></select>
      <button class="btn btn-secondary btn-sm" type="submit">Ubah</button></form>
    <form method="post" action="/admin/roles/revoke"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($a['id']) ?>"><input type="hidden" name="back" value="/admin/roles"><button class="btn btn-danger btn-sm" type="submit">Cabut</button></form>
  </div></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <p class="hint">Anda tidak bisa mengubah role sendiri; superadmin terakhir tidak bisa dihapus.</p>
</section>
<section class="card section">
  <h2>Beri role</h2>
  <form method="post" action="/admin/roles/grant" class="row form-inline"><?= csrf_field() ?><input type="hidden" name="back" value="/admin/roles">
    <div class="field"><label for="gid">User</label><select id="gid" name="id"><?php foreach ($others as $o): ?><option value="<?= e($o['id']) ?>"><?= e($o['email']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="grole">Role</label><select id="grole" name="role"><?php foreach ($matrix['roles'] as $k => $lb): ?><option value="<?= e($k) ?>"><?= e($lb) ?></option><?php endforeach; ?></select></div>
    <div class="field"><button class="btn btn-primary" type="submit">Beri role</button></div>
  </form>
</section>
<section class="card section">
  <h2>Matriks hak akses</h2>
  <div class="table-wrap"><table class="table matrix">
  <thead><tr><th>Modul</th><?php foreach ($matrix['roles'] as $lb): ?><th><?= e($lb) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($matrix['modules'] as $mk => $ml): ?>
  <tr><th scope="row"><?= e($ml) ?></th>
    <?php foreach ($matrix['roles'] as $rk => $rl): $c = $matrix['matrix'][$mk][$rk]; ?><td class="lv-<?= e($c['level']) ?>"><?= e($c['label']) ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?></tbody></table></div>
</section>
