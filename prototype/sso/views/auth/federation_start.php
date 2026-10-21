<h1><?= $link ? 'Tautkan ' : 'Masuk dengan ' ?><?= e($provider['name']) ?></h1>
<div class="alert alert-info">Ini halaman <strong>simulasi</strong> provider. Di produksi, Anda dialihkan ke <?= e($provider['name']) ?> (<?= e($provider['basis']) ?>). Pilih hasil yang ingin didemokan.</div>
<form method="post" action="/federation/<?= e(rawurlencode($provider['slug'])) ?>/callback" class="stack">
  <?= csrf_field() ?>
  <?php if ($link): ?>
    <input type="hidden" name="link" value="1">
    <p>Akun <?= e($provider['name']) ?> akan ditautkan ke akun Anda (<?= e($user['email']) ?>).</p>
  <?php else: ?>
    <fieldset class="field">
      <legend>Skenario hasil (cabang penautan akun)</legend>
      <label class="choice"><input type="radio" name="scenario" value="existing" checked> Identitas sudah tertaut: langsung masuk (budi@contoh.test, MFA aktif)</label>
      <label class="choice"><input type="radio" name="scenario" value="auto"> Email terverifikasi &amp; provider dipercaya: tautkan otomatis (citra@contoh.test)</label>
      <label class="choice"><input type="radio" name="scenario" value="confirm"> Email cocok tetapi belum bisa dipercaya: minta konfirmasi dulu</label>
      <label class="choice"><input type="radio" name="scenario" value="new"> Email tidak dikenal: buat akun baru</label>
      <label class="choice"><input type="radio" name="scenario" value="noemail"> Provider tidak memberi email (mis. X): lengkapi email</label>
    </fieldset>
  <?php endif; ?>
  <button class="btn btn-primary btn-block" type="submit">Lanjutkan sebagai simulasi</button>
</form>
<p class="center"><a href="<?= $link ? '/account/linked' : '/login' ?>">Batal</a></p>
