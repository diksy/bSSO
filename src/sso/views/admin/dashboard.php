<div class="stats">
  <div class="stat"><span class="stat-n"><?= e($d['login_ok']) ?></span><span>Login berhasil (24 jam)</span></div>
  <div class="stat"><span class="stat-n"><?= e($d['login_fail']) ?></span><span>Login gagal (24 jam)</span></div>
  <div class="stat"><span class="stat-n"><?= e($d['active_sessions']) ?></span><span>Sesi aktif</span></div>
  <div class="stat"><span class="stat-n"><?= e($d['days_to_rotation']) ?> hari</span><span>Sampai rotasi kunci</span></div>
</div>
<div class="grid-2">
  <section class="card"><h2>token.reuse_detected terbaru</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Waktu</th><th>Klien</th><th>User</th></tr></thead><tbody>
    <?php foreach ($d['reuse_events'] as $r): ?><tr><td><?= e($r['time']) ?></td><td><?= e($r['client']) ?></td><td><?= e($r['user']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
  <section class="card"><h2>Notifikasi logout gagal permanen</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Waktu</th><th>Klien</th><th>Percobaan</th></tr></thead><tbody>
    <?php foreach ($d['failed_logout'] as $r): ?><tr><td><?= e($r['time']) ?></td><td><?= e($r['client']) ?></td><td><?= e($r['attempts']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
</div>
<section class="card section"><h2>Hak akses per role</h2><p class="muted">Lihat matriks lengkap di <a href="/admin/roles">Role admin</a> (khusus superadmin).</p></section>
