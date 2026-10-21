<div class="table-wrap"><table class="table">
<thead><tr><th>Urutan</th><th>Provider</th><th>Basis</th><th>Status</th><th><span class="visually-hidden">Aksi</span></th></tr></thead><tbody>
<?php foreach ($providers as $p): ?>
<tr><td><?= e($p['order']) ?></td><td><strong><?= e($p['name']) ?></strong></td><td><?= e($p['basis']) ?></td>
<td><span class="badge <?= $p['active'] ? 'badge-ok' : '' ?>"><?= $p['active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
<td><a href="/admin/providers/<?= e(rawurlencode($p['slug'])) ?>"><?= $perm['level'] === 'write' ? 'Atur' : 'Lihat' ?></a></td></tr>
<?php endforeach; ?></tbody></table></div>
