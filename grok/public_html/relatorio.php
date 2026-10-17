<?php
require __DIR__ . '/_boot.php';
require_once APP_ROOT . '/lib/report.php';
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self'; frame-ancestors 'none'; base-uri 'self'");
$u = current_user();
if (!$u) redirect('/');
$todayU = (new DateTime('now', new DateTimeZone((string)$u['timezone'])))->format('Y-m-d'); // "hoje" no fuso da pessoa
$from = valid_iso_date($_GET['de'] ?? null) ? (string)$_GET['de'] : date('Y-m-d', (int)strtotime("$todayU -29 days"));
$to = valid_iso_date($_GET['ate'] ?? null) ? (string)$_GET['ate'] : $todayU;
if ($from > $to) [$from, $to] = [$to, $from];
$data = report_data((int)$u['id'], $from, $to);
$pf = user_prefs($u);
$withSex = !empty($_GET['sexualidade']) && $pf['modules']['sexualidade'];
$withSub = !empty($_GET['substancias']) && $pf['modules']['substancias'];
$withFood = !empty($_GET['alimentacao']) && $pf['modules']['alimentacao']; // V4.7
// V4.8: pensamentos de morte só se a pessoa marcar na hora de imprimir (módulo ligado e seções privadas desbloqueadas)
$withDeath = !empty($_GET['pensamentos_morte']) && $pf['modules']['pensamentos_morte'] && private_unlocked($u);
// V4.2: alertas calculados na hora (iguais ao Histórico), sem esperar o cron; nada é enviado daqui.
require_once APP_ROOT . '/lib/alerts.php';
require_once APP_ROOT . '/lib/custom_alerts.php';
try { alerts_run_for_user(db(), $u, false); custom_eval_user(db(), $u, false); } catch (Throwable $e) { error_log('relatorio alertas: ' . $e->getMessage()); }
page_head('Relatório', 'print-page'); ?>
<section class="card report">
  <div class="row between no-print"><a class="btn" href="/">← Voltar</a><button class="btn btn-primary" type="button" id="btn-print">Imprimir / salvar PDF</button></div>
  <h1>Relatório de humor</h1>
  <?= report_html($u, $data, !empty($_GET['notas'])) ?>
  <?= markers_report_html(markers_report_data((int)$u['id'], $from, $to, $withSex, $withSub, $withFood, ['death' => $withDeath])) ?>
  <?= report_alerts_html(report_alerts_data((int)$u['id'], $from, $to, ($withSex ? CUSTOM_PRIV_SEX : 0) | ($withSub ? CUSTOM_PRIV_SUBST : 0) | ($withDeath ? CUSTOM_PRIV_DEATH : 0))) ?>
  <p class="small">Escala inspirada no NIMH Life Chart Method: −3 depressão grave … 0 eutimia … +3 mania. Gerado em <?= e((new DateTime('now', new DateTimeZone($u['timezone'])))->format('d/m/Y H:i')) ?> (<?= e(tz_label_br((string)$u['timezone'])) ?>).</p>
</section>
<script src="/assets/js/print.js"></script>
<?php page_foot();
