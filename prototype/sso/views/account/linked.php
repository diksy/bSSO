<p class="muted">Tautkan akun eksternal agar bisa masuk dengan salah satunya. Kata sandi Anda tetap berlaku.</p>
<ul class="list-card">
<?php foreach ($providers as $p): $isLinked = in_array($p['slug'], $user['identities'], true); ?>
  <li>
    <span class="pico" aria-hidden="true"><?= e(mb_substr($p['name'], 0, 1)) ?></span>
    <span class="grow"><strong><?= e($p['name']) ?></strong>
      <?php if ($isLinked): ?><span class="badge badge-ok">Tertaut</span><?php elseif (!$p['active']): ?><span class="badge">Nonaktif</span><?php endif; ?>
    </span>
    <?php if ($isLinked): ?>
      <form method="post" action="/account/linked/<?= e(rawurlencode($p['slug'])) ?>/unlink"><?= csrf_field() ?><button class="btn btn-secondary btn-sm" type="submit">Putuskan</button></form>
    <?php elseif ($p['active']): ?>
      <a class="btn btn-secondary btn-sm" href="/federation/<?= e(rawurlencode($p['slug'])) ?>/start?link=1">Tautkan</a>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
