<?php /* Variabel: $title, $user{email}, $flashes, $oneTimeSecret{for,secret}|null, $nav[key]{label,url,level none|read|manage}, $module, $perm (none|read|manage), $role. */ ?>
<header class="topbar">
  <div class="topbar-in">
    <p class="logo"><a href="/admin">Bikinin<span>SSO</span> <small>Admin</small></a></p>
    <div class="topbar-user">
      <a href="/account">Akun saya</a>
      <span class="muted"><?= e($user['email'] ?? '') ?></span>
      <a class="btn btn-secondary btn-sm" href="/logout">Keluar</a>
    </div>
  </div>
</header>
<div class="shell shell-admin">
  <?php ob_start(); ?>
    <ul>
    <?php foreach ($nav as $key => $n): ?>
      <li>
        <?php if ($n['level'] === 'none'): ?>
          <span class="nav-off" title="Tidak ada akses untuk role ini"><?= e($n['label']) ?> <small>terkunci</small></span>
        <?php else: ?>
          <a href="<?= e($n['url']) ?>"<?= $module === $key ? ' aria-current="page"' : '' ?>><?= e($n['label']) ?><?php if ($n['level'] === 'read'): ?> <small>baca</small><?php endif; ?></a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php $navList = ob_get_clean(); ?>
  <nav class="sidenav sidenav-desktop" aria-label="Menu admin">
<?= $navList ?>
  </nav>
  <details class="navmenu">
    <summary>Menu &#9662;</summary>
    <nav class="sidenav" aria-label="Menu admin (ponsel)">
<?= $navList ?>
    </nav>
  </details>
  <main id="main" class="content">
    <h1><?= e($title) ?></h1>
    <?php require __DIR__ . '/../partials/flashes.php'; ?>
    <?php if (!empty($oneTimeSecret)): ?>
      <div class="alert alert-warn" role="status">
        <strong>Secret baru untuk <?= e($oneTimeSecret['for']) ?> (hanya ditampilkan sekali):</strong>
        <code class="code-block"><?= e($oneTimeSecret['secret']) ?></code>
        Salin sekarang; nilai ini tidak akan bisa dilihat lagi.
      </div>
    <?php endif; ?>
    <?php if ($perm === 'read'): ?>
      <div class="alert alert-info">Role <strong><?= e($role) ?></strong> hanya dapat membaca modul ini.</div>
    <?php endif; ?>
    <?= $content ?>
  </main>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
