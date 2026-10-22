<?php $w = $perm === 'manage' && !$locked; $self = $self ?? false; $back = '/admin/users/' . $u['id'];
$act = static fn (string $a, string $label, string $cls = 'btn-secondary') => '<form method="post" action="/admin/users/' . e(rawurlencode($u['id'])) . '/' . $a . '">' . csrf_field() . '<input type="hidden" name="id" value="' . e($u['id']) . '"><input type="hidden" name="back" value="' . e($back) . '"><button class="btn ' . $cls . '" type="submit">' . e($label) . '</button></form>'; ?>
<?php if ($locked): ?><div class="alert alert-warn">Operator tidak boleh mengelola user ber-role admin; tampilan ini hanya baca.</div><?php endif; ?>
<section class="card">
  <p><strong><?= e($u['email']) ?></strong> · <span class="badge <?= $u['status'] === 'aktif' ? 'badge-ok' : 'badge-warn' ?>"><?= e($u['status']) ?></span> · role: <?= e($u['admin_role'] ?? 'tidak ada') ?> · MFA: <?= $u['mfa'] ? 'aktif' : 'belum' ?></p>
  <?php if ($w && !$self): ?>
  <div class="row">
    <?= $act('status', $u['status'] === 'aktif' ? 'Suspend' : 'Aktifkan', 'btn-danger') ?>
    <?= $act('reset-mfa', 'Reset MFA') ?>
    <?= $act('force-reset', 'Paksa reset password') ?>
  </div>
  <?php endif; ?>
</section>
<div class="grid-2">
<section class="card"><h2>Identitas tertaut</h2>
  <?php if (!$u['identities']): ?><p class="muted">Belum ada.</p><?php endif; ?>
  <ul class="plain">
  <?php foreach ($u['identities'] as $slug): ?>
    <li class="row between"><span><?= e(ucfirst($slug)) ?></span>
      <?php if ($w): ?><form method="post" action="/admin/users/<?= e(rawurlencode($u['id'])) ?>/unlink/<?= e(rawurlencode($slug)) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($u['id']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><button class="btn btn-secondary btn-sm" type="submit">Putuskan</button></form><?php endif; ?></li>
  <?php endforeach; ?>
  </ul></section>
<section class="card"><h2>Riwayat login</h2>
  <div class="table-wrap"><table class="table"><thead><tr><th>Waktu</th><th>Hasil</th><th>IP</th></tr></thead><tbody>
  <?php foreach ($logins as $l): ?><tr><td><?= e($l[0]) ?></td><td><?= e($l[1]) ?></td><td><?= e($l[2]) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></section>
</div>
<section class="card section"><h2>Sesi aktif</h2>
  <div class="table-wrap"><table class="table"><thead><tr><th>Perangkat</th><th>IP</th><th>Mulai</th><th><span class="visually-hidden">Aksi</span></th></tr></thead><tbody>
  <?php foreach ($sessions as $s): ?><tr><td><?= e($s['device']) ?></td><td><?= e($s['ip']) ?></td><td><?= e($s['started']) ?></td>
  <td><?php if ($w): ?><form method="post" action="/admin/users/<?= e(rawurlencode($u['id'])) ?>/sessions/<?= e(rawurlencode($s['sid'])) ?>/revoke"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><button class="btn btn-secondary btn-sm" type="submit">Cabut</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$sessions): ?><tr><td colspan="4" class="muted">Tidak ada sesi aktif.</td></tr><?php endif; ?>
  </tbody></table></div></section>
