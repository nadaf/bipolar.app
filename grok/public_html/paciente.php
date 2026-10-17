<?php
/**
 * V4.8: visão SÓ LEITURA do profissional para um paciente que compartilhou. Sem edição, sem exportação bruta,
 * sem anotações. Só o escopo e as datas liberados; cada abertura entra no registro de acessos que o paciente vê.
 * Qualquer id que não seja um compartilhamento ativo deste profissional responde 404 (sem revelar se existe).
 */
require __DIR__ . '/_boot.php';
require_once APP_ROOT . '/lib/care.php';
require_once APP_ROOT . '/lib/report.php';
require_once APP_ROOT . '/lib/custom_alerts.php';
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self'; frame-ancestors 'none'; base-uri 'self'");
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$u = current_user();
if (!$u) redirect('/');
$pdo = db();
$s = care_share_for_professional($pdo, $u, (int)($_GET['c'] ?? 0));
if (!$s) {
    http_response_code(404);
    page_head('Não encontrado'); ?>
<section class="card narrow"><h1>Compartilhamento não encontrado</h1><p>Este compartilhamento não existe, foi encerrado pelo paciente ou passou do prazo.</p><p><a class="btn" href="/?aba=conta#care-card">Meus pacientes</a></p></section>
<?php page_foot();
    exit;
}
[$from, $to] = care_clip_range($s, isset($_GET['de']) ? (string)$_GET['de'] : null, isset($_GET['ate']) ? (string)$_GET['ate'] : null);
$scope = (int)$s['scope_mask'];
care_log($pdo, (int)$s['id'], (int)$s['patient_id'], (int)$u['id'], care_display_name($u), 'visualizou', $scope, $from, $to);
page_head('Paciente: ' . ((string)$s['pname'] ?: 'Paciente'), 'print-page'); ?>
<section class="card report">
  <div class="row between no-print"><a class="btn" href="/?aba=conta#care-card">← Meus pacientes</a><button class="btn btn-primary" type="button" id="btn-print">Imprimir / salvar PDF</button></div>
  <h1>Registros compartilhados <span class="pro-badge">só leitura</span></h1>
  <p class="small">Compartilhado por <strong><?= e((string)$s['pname']) ?></strong>. Categorias liberadas: <?= e(implode('; ', care_scope_labels($scope))) ?>.
    <?= $s['data_from'] ? 'Dados a partir de ' . e(fmt_date((string)$s['data_from'])) . '. ' : '' ?><?= $s['expires_on'] ? 'Acesso até ' . e(fmt_date((string)$s['expires_on'])) . '. ' : '' ?>
    O paciente vê quando você abre esta página e pode encerrar o acesso a qualquer momento.</p>
  <form class="row no-print" method="get" action="/paciente.php">
    <input type="hidden" name="c" value="<?= (int)$s['id'] ?>">
    <div class="field"><label for="pc-de">De</label><input type="date" id="pc-de" name="de" value="<?= e($from) ?>"<?= $s['data_from'] ? ' min="' . e((string)$s['data_from']) . '"' : '' ?>></div>
    <div class="field"><label for="pc-ate">Até</label><input type="date" id="pc-ate" name="ate" value="<?= e($to) ?>"></div>
    <button class="btn" type="submit">Ver período</button>
  </form>
  <?= care_report_html($s, $from, $to) ?>
  <p class="small">Escala inspirada no NIMH Life Chart Method: −3 depressão grave … 0 eutimia … +3 mania. Dados registrados pelo próprio paciente; não é diagnóstico.</p>
</section>
<script src="/assets/js/print.js"></script>
<?php page_foot();
