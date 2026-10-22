<p class="muted">Untuk hosting tanpa cron: jalankan tugas berkala secara manual.</p>
<p>Notifikasi logout gagal permanen: <strong><?= e($failed ?? 0) ?></strong></p>
<div class="grid-3">
<?php foreach ([['gc', 'Jalankan GC', 'Hapus sesi, kode, dan token kedaluwarsa.'], ['retry-logout', 'Retry notifikasi logout', 'Kirim ulang back-channel yang gagal.'], ['rotate-keys', 'Rotasi kunci terjadwal', 'Periksa apakah rotasi sudah jatuh tempo.']] as [$a, $label, $desc]): ?>
  <section class="card stack"><h2><?= e($label) ?></h2><p><?= e($desc) ?></p>
    <form method="post" action="/admin/maintenance/<?= e($a) ?>"><?= csrf_field() ?><input type="hidden" name="back" value="/admin/maintenance"><button class="btn btn-secondary" type="submit">Jalankan</button></form></section>
<?php endforeach; ?>
</div>
