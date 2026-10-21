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
<div class="demo-bar">
  <form method="post" action="/admin/role-switch" class="inline-form">
    <?= csrf_field() ?>
    <label for="role-sw"><strong>Demo:</strong> lihat sebagai role</label>
    <select id="role-sw" name="role">
      <?php foreach ($roleList as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $role ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-secondary btn-sm" type="submit">Terapkan</button>
  </form>
  <form method="post" action="/admin/expire-stepup" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= e(parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH)) ?>">
    <span class="badge <?= $stepFresh ? 'badge-ok' : 'badge-warn' ?>">Step-up <?= $stepFresh ? 'segar' : 'kedaluwarsa' ?></span>
    <button class="btn btn-secondary btn-sm" type="submit">Simulasikan 5 menit berlalu</button>
  </form>
</div>
<div class="shell shell-admin">
  <?php ob_start(); ?>
    <ul>
    <?php foreach ($nav as $key => $n): ?>
      <li>
        <?php if ($n['perm']['level'] === 'none'): ?>
          <span class="nav-off" title="Tidak ada akses untuk role ini"><?= e($n['label']) ?> <small>terkunci</small></span>
        <?php else: ?>
          <a href="<?= e($n['url']) ?>"<?= $module === $key ? ' aria-current="page"' : '' ?>><?= e($n['label']) ?><?php if ($n['perm']['level'] === 'read'): ?> <small>baca</small><?php endif; ?></a>
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
    <?php if (!empty($_SESSION['one_time_secret'])): $ots = $_SESSION['one_time_secret']; unset($_SESSION['one_time_secret']); ?>
      <div class="alert alert-warn" role="status">
        <strong>Secret baru untuk <?= e($ots['for']) ?> (hanya ditampilkan sekali):</strong>
        <code class="code-block"><?= e($ots['secret']) ?></code>
        Salin sekarang; nilai ini tidak akan bisa dilihat lagi.
      </div>
    <?php endif; ?>
    <?php if ($perm['level'] === 'read'): ?>
      <div class="alert alert-info">Role <strong><?= e($roleList[$role] ?? $role) ?></strong> hanya dapat membaca modul ini (<?= e($perm['label']) ?>).</div>
    <?php endif; ?>
    <?= $content ?>
  </main>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
