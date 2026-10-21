<h1>Peta halaman prototype</h1>
<p class="muted">Semua data dummy. Kata sandi <code>demo12345</code>, kode MFA <code>123456</code>.</p>
<p><a href="/panduan-integrasi">Panduan Integrasi</a> (publik) · <a href="/panduan-integrasi.md">/panduan-integrasi.md</a></p>
<div class="grid-2">
<section class="card"><h2>Alur masuk</h2><ul>
  <li><a href="/login">/login</a></li><li><a href="/login/mfa">/login/mfa</a> (setelah login akun ber-MFA)</li>
  <?php foreach ($providers as $p): if ($p['active']): ?><li><a href="/federation/<?= e($p['slug']) ?>/start">/federation/<?= e($p['slug']) ?>/start</a></li><?php endif; endforeach; ?>
  <li><a href="/logout">/logout</a> · <a href="/logged-out">/logged-out</a></li>
  <li><a href="/install">/install</a></li>
</ul></section>
<section class="card"><h2>Halaman error</h2><ul>
  <?php foreach (['redirect_uri', 'client', 'state', 'provider', 'rate', '403', '404'] as $k): ?><li><a href="/error/<?= e($k) ?>">/error/<?= e($k) ?></a></li><?php endforeach; ?>
</ul></section>
<section class="card"><h2>Akun (F7)</h2><ul>
  <li><a href="/account">/account</a></li><li><a href="/account/password">/account/password</a></li><li><a href="/account/mfa">/account/mfa</a></li><li><a href="/account/linked">/account/linked</a></li><li><a href="/account/sessions">/account/sessions</a></li>
</ul></section>
<section class="card"><h2>Admin (F8)</h2><ul>
  <li><a href="/admin">/admin</a> (dasbor)</li><li><a href="/admin/clients">/admin/clients</a></li><li><a href="/admin/providers">/admin/providers</a></li><li><a href="/admin/users">/admin/users</a></li><li><a href="/admin/keys">/admin/keys</a></li><li><a href="/admin/audit">/admin/audit</a></li><li><a href="/admin/maintenance">/admin/maintenance</a></li><li><a href="/admin/settings">/admin/settings</a></li><li><a href="/admin/roles">/admin/roles</a></li>
</ul></section>
</div>
