<?php if ($perm === 'manage'): ?><p><a class="btn btn-primary" href="/admin/clients/new">Tambah klien</a></p><?php endif; ?>
<div class="table-wrap"><table class="table">
<thead><tr><th>Nama</th><th>Tipe</th><th>Grant</th><th>Status</th><th><span class="visually-hidden">Aksi</span></th></tr></thead>
<tbody>
<?php foreach ($clients as $c): ?>
<tr><td><strong><?= e($c['name']) ?></strong><br><code><?= e($c['id']) ?></code></td><td><?= e($c['type']) ?></td><td><?= e(implode(', ', $c['grants'])) ?></td>
<td><span class="badge <?= $c['active'] ? 'badge-ok' : '' ?>"><?= $c['active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
<td><a href="/admin/clients/<?= e(rawurlencode($c['id'])) ?>"><?= $perm === 'manage' ? 'Edit' : 'Lihat' ?></a></td></tr>
<?php endforeach; ?>
</tbody></table></div>
