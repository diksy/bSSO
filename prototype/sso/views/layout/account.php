<header class="topbar">
  <div class="topbar-in">
    <p class="logo"><a href="/account">Bikinin<span>SSO</span></a></p>
    <div class="topbar-user">
      <?php if (!empty($user['admin_role'])): ?><a href="/admin">Admin</a><?php endif; ?>
      <span class="muted"><?= e($user['email'] ?? '') ?></span>
      <a class="btn btn-secondary btn-sm" href="/logout">Keluar</a>
    </div>
  </div>
</header>
<div class="shell">
  <nav class="tabs" aria-label="Menu akun">
    <?php foreach (['profile' => ['/account', 'Profil'], 'password' => ['/account/password', 'Kata sandi'], 'mfa' => ['/account/mfa', 'MFA'], 'linked' => ['/account/linked', 'Akun tertaut'], 'sessions' => ['/account/sessions', 'Sesi aktif']] as $k => [$href, $label]): ?>
      <a href="<?= e($href) ?>"<?= ($tab ?? '') === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <main id="main" class="content">
    <h1><?= e($title) ?></h1>
    <?php require __DIR__ . '/../partials/flashes.php'; ?>
    <?= $content ?>
  </main>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
