<?php foreach ($flashes ?? [] as $f): ?>
<div class="alert alert-<?= e($f['type']) ?>" role="<?= $f['type'] === 'err' ? 'alert' : 'status' ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>
