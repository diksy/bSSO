<form method="get" action="/admin/users" class="row search">
  <label class="visually-hidden" for="q">Cari email atau nama</label>
  <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Cari email atau nama">
  <button class="btn btn-secondary" type="submit">Cari</button>
</form>
<div class="table-wrap"><table class="table">
<thead><tr><th>Nama</th><th>Email</th><th>Role</th><th>MFA</th><th>Status</th><th><span class="visually-hidden">Aksi</span></th></tr></thead><tbody>
<?php foreach ($users as $u): ?>
<tr><td><?= e($u['name']) ?></td><td><?= e($u['email']) ?></td><td><?= e($u['admin_role'] ?? '–') ?></td>
<td><?= $u['mfa'] ? 'Aktif' : 'Belum' ?></td>
<td><span class="badge <?= $u['status'] === 'aktif' ? 'badge-ok' : 'badge-warn' ?>"><?= e($u['status']) ?></span></td>
<td><a href="/admin/users/<?= e((string) $u['id']) ?>">Detail</a></td></tr>
<?php endforeach; ?>
<?php if (!$users): ?><tr><td colspan="6" class="muted">Tidak ada user yang cocok.</td></tr><?php endif; ?>
</tbody></table></div>
