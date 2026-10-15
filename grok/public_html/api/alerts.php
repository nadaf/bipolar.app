<?php
/** Alertas automáticos: lista, feedback ("faz sentido"/"não faz sentido") e preferências. */
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/alerts.php';
require_once APP_ROOT . '/lib/custom_alerts.php';
$u = require_user_api();
$pdo = db();
$m = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');

if ($m === 'GET') {
    // V4.2: análise na hora, a cada consulta (antes: no máximo a cada 10 min e só depois do cron).
    // Nada é enviado daqui: o que for novo fica "pendente" e o cron entrega push/e-mail.
    try { alerts_run_for_user($pdo, $u, false); } catch (Throwable $e) { error_log('alerts GET: ' . $e->getMessage()); }
    try { custom_eval_user($pdo, $u, false); } catch (Throwable $e) { error_log('alerts GET (personalizados): ' . $e->getMessage()); }
    $unlocked = private_unlocked($u);
    $st = $pdo->prepare('SELECT id, rule_code, kind, severity, period_start, period_end, title, explanation, evidence_json, feedback, seen_at, created_at, custom_rule_id, is_private FROM alerts WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 30');
    $st->execute([$u['id']]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['evidence'] = json_decode((string)$r['evidence_json'], true) ?: [];
        unset($r['evidence_json']);
        $r['severity_label'] = alert_severity_label((int)$r['severity']);
        $r['active'] = $r['created_at'] >= gmdate('Y-m-d H:i:s', time() - 72 * 3600) && $r['feedback'] !== 'nao';
        $r['custom'] = $r['custom_rule_id'] !== null || $r['rule_code'] === 'personalizado';
        $r['private'] = (int)$r['is_private'] > 0;
        $r['locked'] = $r['private'] && !$unlocked;
        if ($r['locked']) { // sem título nem motivo enquanto as seções privadas estiverem bloqueadas
            $r['title'] = $r['custom'] ? 'Alerta personalizado privado' : 'Alerta privado';
            $r['explanation'] = 'Este alerta usa dados das seções privadas. Desbloqueie Sexualidade/Substâncias com seu PIN ou biometria para ver os detalhes.';
            $r['evidence'] = [];
        }
        unset($r['is_private']);
        $rows[] = $r;
    }
    json_out(['alerts' => $rows, 'prefs' => alert_prefs($u), 'tune' => alert_tune($u), 'plan' => user_prefs($u)['action_plan']]);
}
if ($m === 'POST' && $action === 'feedback') {
    $in = json_input();
    $sense = $in['faz_sentido'] ?? null;
    if (!is_bool($sense)) json_out(['erro' => 'Resposta inválida.'], 422);
    $st = $pdo->prepare('SELECT rule_code, feedback FROM alerts WHERE id = ? AND user_id = ?');
    $st->execute([(int)($in['id'] ?? 0), $u['id']]);
    $a = $st->fetch();
    if (!$a) json_out(['erro' => 'Alerta não encontrado.'], 404);
    $pdo->prepare('UPDATE alerts SET feedback = ?, feedback_at = UTC_TIMESTAMP(), seen_at = COALESCE(seen_at, UTC_TIMESTAMP()) WHERE id = ?')->execute([$sense ? 'sim' : 'nao', (int)$in['id']]);
    $tune = alert_tune($u);
    if ($a['feedback'] === null && isset(ALERT_RULES[$a['rule_code']])) { // só o primeiro feedback de cada alerta automático ajusta a sensibilidade
        $tune[$a['rule_code']] = alert_feedback_tune($tune[$a['rule_code']] ?? 0, $sense);
        prefs_patch($pdo, $u, ['alert_tune' => $tune]);
    }
    json_out(['ok' => true, 'tune' => $tune]);
}
if ($m === 'POST' && $action === 'seen') {
    $pdo->prepare('UPDATE alerts SET seen_at = UTC_TIMESTAMP() WHERE user_id = ? AND seen_at IS NULL')->execute([$u['id']]);
    json_out(['ok' => true]);
}
if ($m === 'POST' && $action === 'prefs') {
    $in = json_input();
    $p = ['engine' => !empty($in['engine']), 'push' => !empty($in['push']), 'email' => !empty($in['email'])];
    $patch = ['alerts' => $p];
    if (!empty($in['reset_tune'])) $patch['alert_tune'] = [];
    prefs_patch($pdo, $u, $patch);
    json_out(['ok' => true, 'prefs' => $p]);
}
json_out(['erro' => 'Ação inválida.'], 400);
