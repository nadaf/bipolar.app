<?php
require __DIR__ . '/_boot.php';
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self'; frame-ancestors 'none'; base-uri 'self'");
header('Cache-Control: no-store, private');
$u = current_user();
if (!$u) redirect('/');
// V5.1: com o app bloqueado, só a tela de bloqueio genérica, antes de qualquer consulta ou cálculo do relatório
lock_guard_page($u);
require_once APP_ROOT . '/lib/report.php';
$todayU = (new DateTime('now', new DateTimeZone((string)$u['timezone'])))->format('Y-m-d'); // "hoje" no fuso da pessoa
$from = valid_iso_date($_GET['de'] ?? null) ? (string)$_GET['de'] : date('Y-m-d', (int)strtotime("$todayU -29 days"));
$to = valid_iso_date($_GET['ate'] ?? null) ? (string)$_GET['ate'] : $todayU;
if ($from > $to) [$from, $to] = [$to, $from];
$pf = user_prefs($u);
// V5.1: seções privadas (sexualidade, substâncias, pensamentos de morte) só com as seções privadas desbloqueadas.
// Os parâmetros da URL só escolhem o que entra; nunca liberam o que está bloqueado.
$unlocked = private_unlocked($u);
$withSex = !empty($_GET['sexualidade']) && $pf['modules']['sexualidade'] && $unlocked;
$withSub = !empty($_GET['substancias']) && $pf['modules']['substancias'] && $unlocked;
$withFood = !empty($_GET['alimentacao']) && $pf['modules']['alimentacao']; // V4.7
$withDeath = !empty($_GET['pensamentos_morte']) && $pf['modules']['pensamentos_morte'] && $unlocked;
$askedPrivate = (!empty($_GET['sexualidade']) || !empty($_GET['substancias']) || !empty($_GET['pensamentos_morte'])) && !$unlocked;
$data = report_data((int)$u['id'], $from, $to);
// V4.2: alertas calculados na hora (iguais ao Histórico), sem esperar o cron; nada é enviado daqui.
require_once APP_ROOT . '/lib/alerts.php';
require_once APP_ROOT . '/lib/custom_alerts.php';
try { alerts_run_for_user(db(), $u, false); custom_eval_user(db(), $u, false); } catch (Throwable $e) { error_log('relatorio alertas: ' . $e->getMessage()); }
page_head('Relatório', 'print-page'); ?>
<section class="card report">
  <div class="row between no-print"><a class="btn" href="/">← Voltar</a><button class="btn btn-primary" type="button" id="btn-print">Imprimir / salvar PDF</button></div>
  <h1>Relatório de humor</h1>
  <?php if ($askedPrivate): ?><p class="notice no-print" role="status">As seções privadas não entraram porque estão bloqueadas. Desbloqueie com seu PIN ou biometria no app e gere o relatório de novo.</p><?php endif; ?>
  <?= report_html($u, $data, !empty($_GET['notas'])) ?>
  <?= markers_report_html(markers_report_data((int)$u['id'], $from, $to, $withSex, $withSub, $withFood, ['death' => $withDeath])) ?>
  <?= report_alerts_html(report_alerts_data((int)$u['id'], $from, $to, ($withSex ? CUSTOM_PRIV_SEX : 0) | ($withSub ? CUSTOM_PRIV_SUBST : 0) | ($withDeath ? CUSTOM_PRIV_DEATH : 0))) ?>
  <p class="small">Escala inspirada no NIMH Life Chart Method: −3 depressão grave … 0 eutimia … +3 mania. Gerado em <?= e((new DateTime('now', new DateTimeZone($u['timezone'])))->format('d/m/Y H:i')) ?> (<?= e(tz_label_br((string)$u['timezone'])) ?>).</p>
</section>
<script src="/assets/js/print.js"></script>
<?php page_foot();
