<?php
/**
 * V4.2: alertas personalizados — listar, modelos, criar/editar, pausar, excluir e prévia ("teria avisado nestes dias").
 * Regras que usam Sexualidade ou Substâncias só são vistas/alteradas com as seções privadas desbloqueadas.
 */
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/custom_alerts.php';
$u = require_user_api();
$pdo = db();
$m = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');
$mods = user_prefs($u)['modules'];
$unlocked = private_unlocked($u);

/**
 * Regra para a tela. Privada e bloqueada: só id/estado, sem nome nem condições.
 * @param array<string,mixed> $r
 * @return array<string,mixed>
 */
function custom_rule_out(array $r, bool $unlocked): array
{
    if ($r['is_private'] && !$unlocked) return ['id' => $r['id'], 'version' => $r['version'], 'private' => true, 'locked' => true, 'active' => $r['active'], 'name' => 'Alerta privado', 'description' => 'Desbloqueie as seções privadas para ver e editar.'];
    return $r + ['private' => (bool)$r['is_private'], 'locked' => false, 'description' => custom_describe_rule($r)];
}
/**
 * @param array<string,mixed> $u
 * @return array<string,mixed>|null
 */
function custom_find(PDO $pdo, array $u, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM alert_rules WHERE id = ? AND user_id = ?');
    $st->execute([$id, $u['id']]);
    $r = $st->fetch();
    return $r ? custom_rule_from_row($r) : null;
}
$needUnlock = function (int $mask) use ($unlocked): void {
    if ($mask && !$unlocked) json_out(['erro' => 'Este alerta usa dados privados (Sexualidade/Substâncias). Desbloqueie com seu PIN ou biometria.', 'bloqueado' => true], 423);
};

if ($m === 'GET') {
    $metrics = [];
    foreach (custom_metrics() as $k => $x) {
        $avail = $x[6] === 0 || ($x[6] === CUSTOM_PRIV_SEX && $mods['sexualidade']) || ($x[6] === CUSTOM_PRIV_SUBST && $mods['substancias']);
        if (!$avail) continue;
        $metrics[] = ['key' => $k, 'label' => $x[0], 'group' => $x[1], 'min' => $x[2], 'max' => $x[3], 'step' => $x[4], 'type' => $x[5], 'private' => $x[6] > 0, 'unit' => $x[7]];
    }
    $tpls = [];
    foreach (custom_templates() as $k => $t) {
        $mask = custom_private_mask($t['conditions']);
        if (($mask & CUSTOM_PRIV_SEX && !$mods['sexualidade']) || ($mask & CUSTOM_PRIV_SUBST && !$mods['substancias'])) continue;
        $tpls[] = ['key' => $k, 'private' => $mask > 0] + $t;
    }
    json_out([
        'rules' => array_map(function (array $r) use ($unlocked): array { return custom_rule_out($r, $unlocked); }, custom_rules($pdo, $u)),
        'metrics' => $metrics, 'templates' => $tpls, 'ops' => CUSTOM_OPS, 'locked' => !$unlocked,
        'push' => push_configured(), 'email' => !empty($u['email']), 'max_rules' => CUSTOM_MAX_RULES,
    ]);
}
if ($m !== 'POST') json_out(['erro' => 'Método não permitido.'], 405);
$in = json_input();

if ($action === 'save' || $action === 'preview') {
    $raw = $in;
    if ($action === 'preview' && !empty($in['id']) && empty($in['conditions'])) {
        $r = custom_find($pdo, $u, (int)$in['id']);
        if (!$r) json_out(['erro' => 'Alerta não encontrado.'], 404);
        $raw = $r;
    }
    [$err, $rule] = custom_rule_validate($raw, $mods);
    if ($err !== null) json_out(['erro' => $err], 422);
    $needUnlock((int)$rule['is_private']);
    if ($action === 'preview') {
        $days = max(14, min(180, (int)($in['dias'] ?? 90)));
        $to = custom_today($u);
        $from = sig_add($to, -($days - 1));
        $p = custom_preview(custom_load($pdo, $u, $from, $to), $rule, $from, $to);
        $dup = custom_find_duplicate(custom_rules($pdo, $u), $rule, (int)($in['id'] ?? 0));
        json_out(['de' => $from, 'ate' => $to, 'dias' => $days, 'matched' => $p['matched'], 'fired' => $p['fired'], 'skipped' => $p['skipped'],
            'cooldown_days' => $p['cooldown_days'], 'description' => custom_describe_rule($rule),
            'duplicate_of' => $dup ? ['id' => $dup['id'], 'name' => $dup['name']] : null]);
    }
    $id = (int)($in['id'] ?? 0);
    // V4.4: confere o dono ANTES da duplicidade (id de outra pessoa → 404, nunca 409)
    $cur = null;
    if ($id > 0) {
        $cur = custom_find($pdo, $u, $id);
        if (!$cur) json_out(['erro' => 'Alerta não encontrado.'], 404);
        $needUnlock((int)$cur['is_private']);
    }
    // V4.3: bloqueia regra idêntica (mesmas condições): avisaria duas vezes nos mesmos dias
    $dup = custom_find_duplicate(custom_rules($pdo, $u), $rule, $id);
    if ($dup) json_out(['erro' => 'Você já tem um alerta com exatamente estas condições: “' . $dup['name'] . '”. Edite esse alerta ou mude alguma condição.', 'duplicado' => ['id' => $dup['id'], 'name' => $dup['name']]], 409);
    $json = json_encode($rule['conditions'], JSON_UNESCAPED_UNICODE);
    if ($id > 0 && $cur) {
        $claimed = version_guard($pdo, 'alert_rules', ['id' => $id, 'user_id' => $u['id']], client_version($in)); // V4.3.1
        $pdo->prepare('UPDATE alert_rules SET ' . ($claimed ? '' : 'version = version + 1, ') . 'name = ?, logic = ?, conditions_json = ?, severity = ?, notify_push = ?, notify_email = ?, cooldown_days = ?, is_private = ?, template = ? WHERE id = ? AND user_id = ?')
            ->execute([$rule['name'], $rule['logic'], $json, $rule['severity'], (int)$rule['notify_push'], (int)$rule['notify_email'], $rule['cooldown_days'], $rule['is_private'], $rule['template'], $id, $u['id']]);
    } else {
        $st = $pdo->prepare('SELECT COUNT(*) FROM alert_rules WHERE user_id = ?');
        $st->execute([$u['id']]);
        if ((int)$st->fetchColumn() >= CUSTOM_MAX_RULES) json_out(['erro' => 'Você chegou ao limite de ' . CUSTOM_MAX_RULES . ' alertas personalizados. Apague um para criar outro.'], 422);
        $pdo->prepare('INSERT INTO alert_rules (user_id, name, logic, conditions_json, severity, notify_push, notify_email, cooldown_days, is_private, template) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$u['id'], $rule['name'], $rule['logic'], $json, $rule['severity'], (int)$rule['notify_push'], (int)$rule['notify_email'], $rule['cooldown_days'], $rule['is_private'], $rule['template']]);
        $id = (int)$pdo->lastInsertId();
    }
    // avalia já (só no app; push/e-mail ficam para o cron da próxima hora)
    $fired = 0;
    try { $fired = count(custom_eval_user($pdo, $u, false)); } catch (Throwable $e) { error_log('custom_alerts save: ' . $e->getMessage()); }
    $saved = custom_find($pdo, $u, $id);
    json_out(['ok' => true, 'rule' => $saved ? custom_rule_out($saved, true) : null, 'disparou' => $fired]);
}
if ($action === 'toggle' || $action === 'delete') {
    $r = custom_find($pdo, $u, (int)($in['id'] ?? 0));
    if (!$r) json_out(['erro' => 'Alerta não encontrado.'], 404);
    $needUnlock((int)$r['is_private']);
    if ($action === 'toggle') {
        $on = !empty($in['active']);
        $pdo->prepare('UPDATE alert_rules SET active = ?, version = version + 1 WHERE id = ? AND user_id = ?')->execute([$on ? 1 : 0, $r['id'], $u['id']]);
        json_out(['ok' => true, 'active' => $on]);
    }
    // os avisos já mostrados continuam no Histórico (sem o vínculo com a regra apagada)
    $pdo->prepare('DELETE FROM alert_rules WHERE id = ? AND user_id = ?')->execute([$r['id'], $u['id']]);
    $pdo->prepare('UPDATE alerts SET pending_delivery = 0 WHERE user_id = ? AND custom_rule_id = ?')->execute([$u['id'], $r['id']]);
    json_out(['ok' => true]);
}
json_out(['erro' => 'Ação inválida.'], 400);
