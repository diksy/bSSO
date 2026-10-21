<h1>Keluar dari BikininSSO?</h1>
<p>Anda akan keluar dari sesi SSO. Aplikasi yang tersambung diberi tahu lewat logout back-channel.</p>
<form method="post" action="/logout" class="stack">
  <?= csrf_field() ?>
  <button class="btn btn-primary btn-block" type="submit">Ya, keluar</button>
  <a class="btn btn-secondary btn-block" href="<?= $user ? '/account' : '/login' ?>">Batal</a>
</form>
