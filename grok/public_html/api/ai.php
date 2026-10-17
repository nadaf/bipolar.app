<?php
/** "Reflexões com IA" — IA opcional com o provedor e a chave da própria pessoa (BYOK). Ver app/lib/ai.php e ai_providers.php. */
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/ai.php';
$u = require_user_api();
$pdo = db();
$action = (string)($_GET['action'] ?? '');
$s = ai_settings($u);
$cfg = ai_config();

$choice = ai_user_choice($u);
$cred = ai_credential($u);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $provs = [];
    foreach (ai_providers() as $k => $p) $provs[] = ['key' => $k, 'label' => $p['label'], 'models' => $p['models'], 'keys_url' => $p['keys_url'], 'terms_url' => $p['terms_url'], 'needs_url' => $k === 'custom'];
    // V4.3.1: configured = a IA realmente funciona para esta pessoa (chave própria salva ou chave do operador)
    json_out(['configured' => $cred !== null, 'server_ready' => ai_configured(), 'operator_fallback' => ai_operator_fallback() !== null, 'byok' => ai_byok_available(), 'ready' => $cred !== null, 'settings' => $s,
        // a chave NUNCA volta: só se existe e os 4 últimos caracteres
        'choice' => $choice, 'using_operator_key' => $cred !== null && !empty($cred['operator']),
        'model' => $cred['model'] ?? '', 'provider' => $cred['provider'] ?? '', 'month_cost' => round(ai_month_cost($pdo, (int)$u['id']), 4), 'cap' => $cfg['cap'],
        'providers' => $provs, 'categories' => array_map(function (array $c): string { return $c[0]; }, AI_CATEGORIES)]);
}
$in = json_input();
if ($action === 'key' || $action === 'key_test') {
    if (!ai_byok_available()) json_out(['erro' => 'Este servidor ainda não permite chaves próprias (falta ai.secret_key no config.php).'], 503);
    if (!rate_limit('ai_key_' . $u['id'], 20, 3600, false)) json_out(['erro' => 'Muitas tentativas. Aguarde alguns minutos.'], 429);
    $prov = (string)($in['provider'] ?? $choice['provider']);
    if (!isset(ai_providers()[$prov])) json_out(['erro' => 'Escolha um provedor.'], 422);
    $model = trim((string)($in['model'] ?? ''));
    if ($model === '') $model = ai_providers()[$prov]['models'][0] ?? '';
    if (!ai_valid_model($model)) json_out(['erro' => 'Informe o nome do modelo (só letras, números e . _ - / :).'], 422);
    $base = $prov === 'custom' ? trim((string)($in['base_url'] ?? $choice['base_url'])) : '';
    if ($prov === 'custom' && ($p = ai_base_url_problem($base)) !== null) json_out(['erro' => $p], 422);
    $newKey = trim((string)($in['api_key'] ?? ''));
    if ($newKey !== '' && !ai_valid_key($newKey)) json_out(['erro' => 'Essa chave não parece válida. Copie de novo, sem espaços.'], 422);
    if ($newKey === '') {
        // sem chave nova: só vale manter a atual se o provedor não mudou
        $old = $choice['has_key'] && $choice['provider'] === $prov ? ai_decrypt_key((string)$u['ai_key_enc']) : null;
        if ($old === null) json_out(['erro' => 'Cole a chave de API do provedor escolhido.'], 422);
        $newKey = $old;
    }
    $test = ['provider' => $prov, 'key' => $newKey, 'model' => $model, 'base_url' => $base];
    if ($action === 'key_test') {
        [$ok, $msg] = ai_test_key($test);
        json_out($ok ? ['ok' => true, 'mensagem' => $msg] : ['ok' => false, 'erro' => $msg, 'mensagem' => $msg], $ok ? 200 : 422);
    }
    $pdo->prepare('UPDATE users SET ai_provider = ?, ai_model = ?, ai_base_url = ?, ai_key_enc = ?, ai_key_last4 = ?, ai_key_updated_at = UTC_TIMESTAMP() WHERE id = ?')
        ->execute([$prov, $model, $base !== '' ? $base : null, ai_encrypt_key($newKey), substr($newKey, -4), $u['id']]);
    json_out(['ok' => true, 'choice' => ['provider' => $prov, 'model' => $model, 'base_url' => $base, 'has_key' => true, 'last4' => substr($newKey, -4)]]);
}
if ($action === 'key_remove') {
    $pdo->prepare('UPDATE users SET ai_key_enc = NULL, ai_key_last4 = NULL, ai_key_updated_at = NULL WHERE id = ?')->execute([$u['id']]);
    json_out(['ok' => true]);
}
if ($action === 'consent') {
    $cats = array_values(array_intersect(array_keys(AI_CATEGORIES), array_map('strval', (array)($in['categories'] ?? []))));
    $on = ($in['consent'] ?? false) === true;
    if ($on && !$cats) json_out(['erro' => 'Escolha ao menos uma categoria de dados.'], 422);
    prefs_patch($pdo, $u, ['ai' => ['consent' => $on, 'consent_at' => $on ? gmdate('c') : null, 'categories' => $cats]]);
    json_out(['ok' => true]);
}
if ($cred === null) json_out(['erro' => 'Escolha um provedor e cole sua chave de API em Conta → Reflexões com IA.'], 503);
if (!$s['consent']) json_out(['erro' => 'Ative a IA e dê seu consentimento em Conta → Reflexões com IA.'], 403);
$operator = !empty($cred['operator']);
if (!in_array($action, ['resumo', 'explicar', 'consulta', 'checkin'], true)) json_out(['erro' => 'Ação inválida.'], 400);

// Crise: checagem local ANTES de qualquer envio
$userText = '';
if ($action === 'checkin') foreach ((array)($in['messages'] ?? []) as $msg) if (is_array($msg) && ($msg['role'] ?? '') === 'user') $userText .= ' ' . (string)($msg['content'] ?? '');
if ($action === 'consulta') $userText = (string)($in['notes'] ?? '');
if ($userText !== '' && ai_detect_crisis($userText)) json_out(['crise' => true, 'texto' => AI_CRISIS_MESSAGE]);

if (!rate_limit('ai_' . $u['id'], $cfg['per_hour'], 3600, false)) json_out(['erro' => 'Limite de uso da IA por hora atingido. Tente mais tarde.'], 429);
if ($operator && ai_month_cost($pdo, (int)$u['id']) >= $cfg['cap']) json_out(['erro' => 'O limite mensal de uso da IA foi atingido. Ele renova no início do próximo mês.'], 429);

$name = trim(explode(' ', trim((string)$u['name']))[0] ?? '');
$msgs = [['role' => 'system', 'content' => ai_system_prompt($name)]];
$data = ai_context($pdo, $u, $s['categories'], $action === 'consulta' ? 30 : ($action === 'resumo' ? 7 : 14));
if ($action === 'explicar') {
    $st = $pdo->prepare('SELECT title, explanation, evidence_json, severity, is_private FROM alerts WHERE id = ? AND user_id = ?');
    $st->execute([(int)($in['id'] ?? 0), $u['id']]);
    $a = $st->fetch();
    if (!$a) json_out(['erro' => 'Alerta não encontrado.'], 404);
    // V4.2: alerta com dados privados só vai para a IA desbloqueado E com a categoria autorizada
    $pm = (int)$a['is_private'];
    if ($pm && (!private_unlocked($u) || (($pm & 1) && !in_array('sexualidade', $s['categories'], true)) || (($pm & 2) && !in_array('substancias', $s['categories'], true)) || (($pm & 4) && !in_array('pensamentos_morte', $s['categories'], true))))
        json_out(['erro' => 'Este alerta usa dados privados que você não autorizou enviar à IA (ou as seções estão bloqueadas).'], 403);
    $data .= "\n\nALERTA: " . $a['title'] . ' (nível ' . $a['severity'] . '). ' . $a['explanation'];
}
$msgs[] = ['role' => 'system', 'content' => ai_task_prompt($action) . "\n\nDADOS AUTORIZADOS PELA PESSOA:\n" . $data];
if ($action === 'consulta' && trim((string)($in['notes'] ?? '')) !== '') $msgs[] = ['role' => 'user', 'content' => 'O que eu quero levar: ' . mb_substr((string)$in['notes'], 0, 1500)];
if ($action === 'checkin') {
    $hist = array_slice(array_values(array_filter((array)($in['messages'] ?? []), 'is_array')), -12);
    foreach ($hist as $m) {
        $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $msgs[] = ['role' => $role, 'content' => mb_substr((string)($m['content'] ?? ''), 0, 1500)];
    }
    if (!$hist) $msgs[] = ['role' => 'user', 'content' => 'Oi, quero fazer meu check-in.'];
}
if ($action === 'resumo' || $action === 'explicar') $msgs[] = ['role' => 'user', 'content' => $action === 'resumo' ? 'Faça meu resumo da semana.' : 'Me explique esse alerta.'];

try { $r = ai_chat($msgs, null, $cred); } catch (AiError $e) {
    $code = ['timeout' => 504, 'chave' => 503, 'modelo' => 503, 'credito' => 402, 'limite' => 429][$e->kind] ?? 502;
    json_out(['erro' => $e->getMessage(), 'tipo' => $e->kind], $code);
} catch (RuntimeException $e) { json_out(['erro' => $e->getMessage()], 502); }
ai_record_usage($pdo, (int)$u['id'], $r['usage'], $operator);
[$text, $entry] = $action === 'checkin' ? ai_extract_entry($r['text']) : [$r['text'], null];
if (ai_detect_crisis($text)) $text .= "\n\n" . 'CVV 188 (24h) · SAMU 192.';
if ($action === 'resumo' && in_array('humor', $s['categories'], true)) { // V4.2: contagem e lista vêm do banco, não do modelo
    $tzU = new DateTimeZone((string)$u['timezone']);
    $text .= "\n\n" . ai_facts_line(ai_facts($pdo, $u, (new DateTime('now', $tzU))->modify('-6 days')->format('Y-m-d'), (new DateTime('now', $tzU))->format('Y-m-d')));
}
json_out(['texto' => $text, 'registro' => $entry, 'aviso' => 'Gerado por IA (' . (ai_providers()[$r['provider']]['label'] ?? $r['provider']) . ', ' . $r['model'] . '). Pode errar; não é orientação médica.']);
