<?php
/**
 * V4.8: compartilhamento com profissionais (paciente controla; profissional só lê).
 * GET: estado (meus compartilhamentos + registro de acessos; se profissional, convites e pacientes).
 * POST ?action=profile|code|invite|link|update|revoke|accept|decline
 * Toda ação confere dono/destinatário no servidor; id de outra pessoa responde 404 (sem dizer se existe).
 */
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/care.php';
$u = require_user_api();
$pdo = db();
$m = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');
$fmtShare = function (array $s): array {
    $pro = $s['professional_id'] !== null && $s['status'] !== 'pendente'
        ? care_display_name(['name' => $s['pro_name'], 'professional_kind' => $s['pro_kind'], 'professional_reg' => $s['pro_reg']]) : null;
    return ['id' => (int)$s['id'], 'status' => care_status($s), 'scope' => care_scope_keys((int)$s['scope_mask']),
        'profissional' => $pro, 'convite_para' => $pro === null ? ($s['professional_id'] !== null ? 'código de ' . ($s['pro_name'] ?: 'profissional') : $s['invite_email']) : null,
        'por_email' => $s['professional_id'] === null, 'data_from' => $s['data_from'], 'expires_on' => $s['expires_on'],
        'created_at' => $s['created_at'], 'accepted_at' => $s['accepted_at'], 'ended_at' => $s['ended_at'], 'ended_by' => $s['ended_by'], 'invite_expires_at' => $s['invite_expires_at']];
};
/** @return array<string,mixed>|null compartilhamento do paciente logado */
$mine = function (int $id) use ($pdo, $u): ?array {
    $st = $pdo->prepare('SELECT s.*, p.timezone AS ptz FROM care_shares s JOIN users p ON p.id = s.patient_id WHERE s.id = ? AND s.patient_id = ?');
    $st->execute([$id, $u['id']]);
    $r = $st->fetch();
    return $r ?: null;
};
$dates = function (array $in) use ($u): array {
    $out = [];
    foreach (['data_from', 'expires_on'] as $k) {
        $v = $in[$k] ?? null;
        if ($v === null || $v === '') { $out[$k] = null; continue; }
        if (!valid_iso_date($v)) json_out(['erro' => $k === 'data_from' ? 'Data inicial inválida.' : 'Data final inválida.'], 422);
        $out[$k] = (string)$v;
    }
    if ($out['expires_on'] !== null && $out['expires_on'] < care_today($u)) json_out(['erro' => 'A data final já passou.'], 422);
    if ($out['data_from'] !== null && $out['expires_on'] !== null && $out['data_from'] > $out['expires_on']) json_out(['erro' => 'A data inicial é depois da final.'], 422);
    return $out;
};
/** Escopo com categorias sensíveis exige as seções privadas desbloqueadas (mesmo PIN/biometria). */
$scopeIn = function ($raw, int $current = 0) use ($u): int {
    $scope = care_scope_from_input($raw);
    if ((($scope & ~$current) & CARE_SENSITIVE) && !private_unlocked($u)) json_out(['erro' => 'Para liberar categorias sensíveis, desbloqueie as seções privadas com seu PIN.', 'bloqueado' => true], 423);
    return $scope;
};

if ($m === 'GET') {
    $st = $pdo->prepare('SELECT s.*, p.timezone AS ptz, pr.name AS pro_name, pr.professional_kind AS pro_kind, pr.professional_reg AS pro_reg
        FROM care_shares s JOIN users p ON p.id = s.patient_id LEFT JOIN users pr ON pr.id = s.professional_id WHERE s.patient_id = ? ORDER BY s.id DESC LIMIT 100');
    $st->execute([$u['id']]);
    $shares = array_map($fmtShare, $st->fetchAll());
    $st = $pdo->prepare('SELECT share_id, actor_name, action, scope_mask, range_from, range_to, created_at FROM care_access_log WHERE patient_id = ? ORDER BY id DESC LIMIT 200');
    $st->execute([$u['id']]);
    $log = array_map(function (array $r): array { return ['share_id' => $r['share_id'] === null ? null : (int)$r['share_id'], 'quem' => $r['actor_name'], 'acao' => CARE_ACTIONS[$r['action']] ?? $r['action'], 'acao_codigo' => $r['action'],
        'escopo' => care_scope_labels((int)$r['scope_mask']), 'de' => $r['range_from'], 'ate' => $r['range_to'], 'quando_utc' => $r['created_at']]; }, $st->fetchAll());
    $out = ['me' => ['is_professional' => care_is_pro($u), 'kind' => $u['professional_kind'] ?? null, 'reg' => $u['professional_reg'] ?? null],
        'kinds' => PRO_KINDS, 'scopes' => array_map(function (array $s): array { return ['bit' => $s[0], 'label' => $s[1], 'default' => $s[2], 'sensitive' => $s[3]]; }, CARE_SCOPE),
        'shares' => $shares, 'log' => $log, 'smtp' => care_smtp_on(), 'unlocked' => private_unlocked($u),
        'pending' => array_map(function (array $s): array { return ['id' => (int)$s['id'], 'paciente' => (string)$s['pname'], 'scope' => care_scope_labels((int)$s['scope_mask']), 'expires_on' => $s['expires_on'], 'created_at' => $s['created_at']]; }, care_pending_for($pdo, $u)),
        'patients' => []];
    if (care_is_pro($u)) {
        $st = $pdo->prepare("SELECT s.*, p.name AS pname, p.timezone AS ptz FROM care_shares s JOIN users p ON p.id = s.patient_id WHERE s.professional_id = ? AND s.status = 'ativo' ORDER BY p.name");
        $st->execute([$u['id']]);
        foreach ($st->fetchAll() as $s) if (care_status($s) === 'ativo') $out['patients'][] = ['id' => (int)$s['id'], 'nome' => (string)$s['pname'], 'scope' => care_scope_labels((int)$s['scope_mask']), 'data_from' => $s['data_from'], 'expires_on' => $s['expires_on']];
    }
    json_out($out);
}
if ($m !== 'POST') json_out(['erro' => 'Método não permitido.'], 405);
$in = json_input();

if ($action === 'profile') {
    $on = !empty($in['is_professional']);
    $kind = is_string($in['kind'] ?? null) && isset(PRO_KINDS[$in['kind']]) ? $in['kind'] : null;
    $reg = is_scalar($in['reg'] ?? null) ? trim(mb_substr((string)preg_replace('/[\x00-\x1F]/', '', (string)$in['reg']), 0, 40)) : '';
    $pdo->prepare('UPDATE users SET is_professional = ?, professional_kind = ?, professional_reg = ? WHERE id = ?')->execute([$on ? 1 : 0, $on ? $kind : null, $on && $reg !== '' ? $reg : null, $u['id']]);
    json_out(['ok' => true, 'is_professional' => $on]);
}
if ($action === 'code') { // profissional gera um código de uso único (24 h) para o paciente digitar
    if (!care_is_pro($u)) json_out(['erro' => 'Marque sua conta como “Profissional de saúde” primeiro.'], 403);
    if (!rate_limit('care_code_' . $u['id'], 10, 3600, false)) json_out(['erro' => 'Muitos códigos em pouco tempo. Aguarde.'], 429);
    $pdo->prepare('UPDATE care_codes SET used_at = UTC_TIMESTAMP() WHERE professional_id = ? AND used_at IS NULL')->execute([$u['id']]);
    $code = care_new_code();
    $pdo->prepare('INSERT INTO care_codes (professional_id, code_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ' . CARE_CODE_HOURS . ' HOUR)')->execute([$u['id'], care_hash(care_code_normalize($code))]);
    json_out(['ok' => true, 'code' => $code, 'horas' => CARE_CODE_HOURS]);
}
if ($action === 'invite') { // paciente convida (e-mail ou código)
    if (!rate_limit('care_invite_' . $u['id'], 20, 3600, false)) json_out(['erro' => 'Muitos convites em pouco tempo. Aguarde.'], 429);
    $scope = $scopeIn($in['scope'] ?? CARE_SCOPE_DEFAULT);
    if ($scope === 0) json_out(['erro' => 'Escolha pelo menos uma categoria para compartilhar.'], 422);
    $dt = $dates($in);
    $code = care_code_normalize((string)($in['code'] ?? ''));
    if ($code !== '') {
        if (!rate_limit('care_codetry_' . $u['id'], 10, 900, false)) json_out(['erro' => 'Muitas tentativas. Aguarde 15 minutos.'], 429);
        $st = $pdo->prepare('SELECT c.id, c.professional_id, pr.name, pr.is_professional FROM care_codes c JOIN users pr ON pr.id = c.professional_id WHERE c.code_hash = ? AND c.used_at IS NULL AND c.expires_at > UTC_TIMESTAMP()');
        $st->execute([care_hash($code)]);
        $c = $st->fetch();
        if (!$c || !(int)$c['is_professional'] || (int)$c['professional_id'] === (int)$u['id']) json_out(['erro' => 'Código inválido ou expirado. Peça um novo ao profissional.'], 422);
        $st = $pdo->prepare("SELECT s.*, p.timezone AS ptz FROM care_shares s JOIN users p ON p.id = s.patient_id WHERE s.patient_id = ? AND s.professional_id = ? AND s.status IN ('pendente', 'ativo')");
        $st->execute([$u['id'], $c['professional_id']]);
        $live = array_filter($st->fetchAll(), function (array $x): bool { return in_array(care_status($x), ['pendente', 'ativo'], true); });
        if ($live) json_out(['erro' => 'Você já compartilha com esse profissional (ou há um convite pendente).'], 409);
        $use = $pdo->prepare('UPDATE care_codes SET used_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL');
        $use->execute([$c['id']]);
        if ($use->rowCount() !== 1) json_out(['erro' => 'Código inválido ou expirado. Peça um novo ao profissional.'], 422);
        $pdo->prepare("INSERT INTO care_shares (patient_id, professional_id, status, scope_mask, data_from, expires_on, invite_expires_at) VALUES (?, ?, 'pendente', ?, ?, ?, UTC_TIMESTAMP() + INTERVAL " . CARE_INVITE_DAYS . ' DAY)')
            ->execute([$u['id'], $c['professional_id'], $scope, $dt['data_from'], $dt['expires_on']]);
        $id = (int)$pdo->lastInsertId();
        care_log($pdo, $id, (int)$u['id'], (int)$u['id'], 'Você', 'convite', $scope);
        json_out(['ok' => true, 'id' => $id, 'por' => 'codigo', 'profissional' => (string)$c['name']]);
    }
    $email = normalize_email((string)($in['email'] ?? ''));
    if (!valid_email($email)) json_out(['erro' => 'Informe o e-mail do profissional ou o código que ele gerou.'], 422);
    if ($email === normalize_email((string)($u['email'] ?? ''))) json_out(['erro' => 'Use o e-mail do profissional, não o seu.'], 422);
    $st = $pdo->prepare("SELECT COUNT(*) FROM care_shares WHERE patient_id = ? AND invite_email = ? AND status = 'pendente' AND invite_expires_at > UTC_TIMESTAMP()");
    $st->execute([$u['id'], $email]);
    if ((int)$st->fetchColumn() > 0) json_out(['erro' => 'Já existe um convite pendente para esse e-mail. Cancele ou gere um novo link nele.'], 409);
    // Mesma resposta com ou sem conta para esse e-mail (sem revelar quem é usuário): sempre um link de uso único.
    [$raw, $hash] = care_new_token();
    $pdo->prepare("INSERT INTO care_shares (patient_id, invite_email, invite_token_hash, invite_expires_at, status, scope_mask, data_from, expires_on) VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL " . CARE_INVITE_DAYS . " DAY, 'pendente', ?, ?, ?)")
        ->execute([$u['id'], $email, $hash, $scope, $dt['data_from'], $dt['expires_on']]);
    $id = (int)$pdo->lastInsertId();
    care_log($pdo, $id, (int)$u['id'], (int)$u['id'], 'Você', 'convite', $scope);
    $link = care_invite_link($raw);
    $sent = care_send_invite_email($u, $email, $link);
    json_out(['ok' => true, 'id' => $id, 'por' => 'email', 'link' => $link, 'dias' => CARE_INVITE_DAYS, 'email_enviado' => $sent]);
}
if ($action === 'link') { // novo link para convite pendente por e-mail (o anterior deixa de valer)
    $s = $mine((int)($in['id'] ?? 0));
    if (!$s || $s['status'] !== 'pendente' || $s['professional_id'] !== null) json_out(['erro' => 'Convite não encontrado.'], 404);
    [$raw, $hash] = care_new_token();
    $pdo->prepare('UPDATE care_shares SET invite_token_hash = ?, invite_expires_at = UTC_TIMESTAMP() + INTERVAL ' . CARE_INVITE_DAYS . ' DAY WHERE id = ? AND patient_id = ?')->execute([$hash, $s['id'], $u['id']]);
    care_log($pdo, (int)$s['id'], (int)$u['id'], (int)$u['id'], 'Você', 'novo_link', (int)$s['scope_mask']);
    json_out(['ok' => true, 'link' => care_invite_link($raw), 'dias' => CARE_INVITE_DAYS, 'email_enviado' => !empty($in['reenviar']) ? care_send_invite_email($u, (string)$s['invite_email'], care_invite_link($raw)) : false]);
}
if ($action === 'update') {
    $s = $mine((int)($in['id'] ?? 0));
    if (!$s || !in_array(care_status($s), ['pendente', 'ativo'], true)) json_out(['erro' => 'Compartilhamento não encontrado.'], 404);
    $scope = $scopeIn($in['scope'] ?? (int)$s['scope_mask'], (int)$s['scope_mask']);
    if ($scope === 0) json_out(['erro' => 'Escolha pelo menos uma categoria (ou encerre o compartilhamento).'], 422);
    $dt = $dates($in);
    $pdo->prepare('UPDATE care_shares SET scope_mask = ?, data_from = ?, expires_on = ? WHERE id = ? AND patient_id = ?')->execute([$scope, $dt['data_from'], $dt['expires_on'], $s['id'], $u['id']]);
    care_log($pdo, (int)$s['id'], (int)$u['id'], (int)$u['id'], 'Você', 'escopo', $scope, $dt['data_from'], $dt['expires_on']);
    json_out(['ok' => true]);
}
if ($action === 'revoke') { // paciente encerra (ativo) ou cancela (pendente); profissional encerra o dele. Vale na hora.
    $id = (int)($in['id'] ?? 0);
    $s = $mine($id);
    if ($s) {
        if (!in_array($s['status'], ['pendente', 'ativo'], true)) json_out(['erro' => 'Este compartilhamento já foi encerrado.'], 409);
        $new = $s['status'] === 'pendente' ? 'cancelado' : 'revogado';
        $pdo->prepare("UPDATE care_shares SET status = ?, ended_at = UTC_TIMESTAMP(), ended_by = 'paciente', invite_token_hash = NULL WHERE id = ? AND patient_id = ?")->execute([$new, $id, $u['id']]);
        care_log($pdo, $id, (int)$u['id'], (int)$u['id'], 'Você', $new, (int)$s['scope_mask']);
        json_out(['ok' => true, 'status' => $new]);
    }
    $st = $pdo->prepare("SELECT * FROM care_shares WHERE id = ? AND professional_id = ? AND status = 'ativo'");
    $st->execute([$id, $u['id']]);
    $s = $st->fetch();
    if (!$s) json_out(['erro' => 'Compartilhamento não encontrado.'], 404);
    $pdo->prepare("UPDATE care_shares SET status = 'revogado', ended_at = UTC_TIMESTAMP(), ended_by = 'profissional' WHERE id = ?")->execute([$id]);
    care_log($pdo, $id, (int)$s['patient_id'], (int)$u['id'], care_display_name($u), 'revogado', (int)$s['scope_mask']);
    json_out(['ok' => true, 'status' => 'revogado']);
}
if ($action === 'accept') {
    $err = care_accept($pdo, $u, (int)($in['id'] ?? 0));
    if ($err !== null) json_out(['erro' => $err], care_is_pro($u) ? 404 : 403);
    json_out(['ok' => true]);
}
if ($action === 'decline') {
    $id = (int)($in['id'] ?? 0);
    $st = $pdo->prepare('SELECT s.*, p.timezone AS ptz FROM care_shares s JOIN users p ON p.id = s.patient_id WHERE s.id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s || !care_invite_is_for($s, $u)) json_out(['erro' => 'Convite não encontrado ou expirado.'], 404);
    $pdo->prepare("UPDATE care_shares SET status = 'recusado', ended_at = UTC_TIMESTAMP(), ended_by = 'profissional', invite_token_hash = NULL WHERE id = ? AND status = 'pendente'")->execute([$id]);
    care_log($pdo, $id, (int)$s['patient_id'], (int)$u['id'], care_display_name($u), 'recusado', (int)$s['scope_mask']);
    json_out(['ok' => true]);
}
json_out(['erro' => 'Ação desconhecida.'], 404);
