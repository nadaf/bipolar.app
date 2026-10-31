<?php
/** Registro de humor em 1 toque — destino das notificações ("Registrar agora"). */
require __DIR__ . '/_boot.php';
$user = current_user();
if (!$user) { redirect('/'); }
lock_guard_page($user);
$now = new DateTime('now', new DateTimeZone((string)$user['timezone']));
$h = (int)$now->format('G');
$p = (string)($_GET['p'] ?? '');
if (!isset(PERIODS[$p])) $p = $h < 12 ? 'manha' : ($h < 18 ? 'tarde' : 'noite');
$label = ['manha' => 'nesta manhã', 'tarde' => 'nesta tarde', 'noite' => 'nesta noite'][$p];
page_head('Registro rápido', 'app quick-page'); ?>
<section class="card narrow quick" id="quick" data-csrf="<?= e(csrf_token()) ?>" data-owner="<?= e(queue_owner()) ?>" data-date="<?= e($now->format('Y-m-d')) ?>" data-period="<?= e($p) ?>">
  <h1>Como está seu humor <?= e($label) ?>?</h1>
  <p class="small"><?= e(PERIODS[$p]) ?> de <?= e($now->format('d/m/Y')) ?> · um toque salva.</p>
  <div class="quick-grid" role="group" aria-label="Escolha seu humor">
    <?php foreach (array_reverse(MOOD_LEVELS, true) as $v => $m): ?>
    <button type="button" class="quick-btn lvl<?= $v + 3 ?>" data-mood="<?= $v ?>"><span class="mood-icon" aria-hidden="true"><?= $m[2] ?></span> <span><strong><?= $v > 0 ? '+' . $v : ($v < 0 ? '−' . abs($v) : '0') ?></strong> <?= e($m[0]) ?></span></button>
    <?php endforeach; ?>
  </div>
  <p id="quick-msg" role="status" class="quick-msg"></p>
  <p class="row"><a class="btn btn-ghost" href="/?aba=registrar" id="quick-more">Registrar com detalhes</a></p>
</section>
<script src="/assets/js/quick.js?v=3"></script>
<?php page_foot();
