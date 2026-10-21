<?php $w = $perm['level'] === 'write'; $dis = $w ? '' : ' disabled'; ?>
<form method="post" action="/admin/settings/save" class="card stack form-wide">
  <?= csrf_field() ?><input type="hidden" name="back" value="/admin/settings">
  <?php foreach ([['sso_session_hours', 'Umur sesi SSO (jam)'], ['password_min', 'Panjang minimum kata sandi'], ['rate_limit_attempts', 'Ambang rate limit (percobaan)'], ['rate_limit_window_min', 'Jendela rate limit (menit)'], ['key_rotation_days', 'key.rotation_days'], ['key_overlap_days', 'key.overlap_days'], ['admin_idle_minutes', 'Idle timeout admin (menit)']] as [$k, $label]): ?>
    <div class="field"><label for="<?= e($k) ?>"><?= e($label) ?></label><input id="<?= e($k) ?>" name="<?= e($k) ?>" type="number" min="1" value="<?= e($s[$k]) ?>"<?= $dis ?>></div>
  <?php endforeach; ?>
  <label class="choice"><input type="checkbox" name="mfa_required_all" value="1"<?= $s['mfa_required_all'] ? ' checked' : '' ?><?= $dis ?>> Wajibkan MFA untuk semua user</label>
  <p class="hint">Mengubah pengaturan meminta verifikasi MFA ulang dan dicatat di audit log (before/after).</p>
  <?php if ($w): ?><div><button class="btn btn-primary" type="submit">Simpan pengaturan</button></div><?php endif; ?>
</form>
