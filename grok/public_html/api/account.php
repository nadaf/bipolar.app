<?php
require __DIR__ . '/../_boot.php';
$u = require_user_api();
$pdo = db();
$action = (string)($_GET['action'] ?? '');

// V5.1: a exportação traz dados íntimos (sexualidade, substâncias, pensamentos de morte, anotações).
// Só por POST + CSRF, depois de confirmar a identidade (senha, PIN, biometria ou login recente) e com um token de uso único (10 min).
if ($action === 'export' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    json_out(['erro' => 'Para baixar seus dados, use o botão em Conta → Seus dados (é preciso confirmar que é você).', 'stepup' => true], 405);
}
if ($action === 'export_prepare' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!stepup_check($u, json_input())) stepup_required($u, 'baixar seus dados');
    json_out(['ok' => true, 'token' => create_auth_token((int)$u['id'], 'export', 600), 'aviso' => 'O arquivo contém dados íntimos (sexualidade, substâncias, pensamentos de morte e anotações, se você registrou). Guarde em local seguro e não envie a ninguém sem querer.']);
}
if ($action === 'export' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ein = $_POST ?: json_input();
    $tokUid = consume_auth_token(is_string($ein['token'] ?? null) ? $ein['token'] : '', 'export');
    if ($tokUid === null || $tokUid !== (int)$u['id']) json_out(['erro' => 'O pedido de exportação expirou. Toque de novo em baixar e confirme que é você.', 'stepup' => true], 403);
    $_GET['format'] = is_string($ein['format'] ?? null) ? $ein['format'] : 'json';
    $q = function ($sql) use ($pdo, $u) { $st = $pdo->prepare($sql); $st->execute([$u['id']]); return $st->fetchAll(); };
    $entries = $q('SELECT entry_date, period, mood, sleep_hours, irritability, anxiety, energy, medication_taken, note, tags, created_at, updated_at FROM entries WHERE user_id = ? ORDER BY entry_date, period');
    try { $utz = new DateTimeZone((string)($u['timezone'] ?? 'America/Sao_Paulo')); } catch (Exception $e) { $utz = new DateTimeZone('America/Sao_Paulo'); }
    $stamp = (new DateTime('now', $utz))->format('Y-m-d-His'); // nome do arquivo no fuso do usuário
    $fmt = (string)($_GET['format'] ?? 'json');
    // V4.7: estresse e alimentação por período entram nas mesmas linhas do CSV (data + período)
    $pmRows = $q('SELECT entry_date, period, ' . implode(', ', PERIOD_MARKER_FIELDS) . ', updated_at FROM period_markers WHERE user_id = ? ORDER BY entry_date, period');
    // V4.8: cigarros e outra forma de nicotina (do dia) entram em cada linha do dia, junto da cafeína
    $dayRows = [];
    foreach ($q('SELECT entry_date, caffeine_cups, cigarettes, nicotine_other FROM daily_markers WHERE user_id = ?') as $r) $dayRows[(string)$r['entry_date']] = $r;
    $pm = [];
    foreach ($pmRows as $r) $pm[$r['entry_date'] . '|' . $r['period']] = $r;
    /** Formato Excel-BR: separador ";", vírgula decimal, datas dd/mm/aaaa, UTF-8 com BOM */
    $csv = function (array $entries) use ($u, $pm, $dayRows): string {
        $out = fopen('php://temp', 'w+');
        if ($out === false) return '';
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['data', 'período', 'humor', 'descrição', 'sono_h', 'irritabilidade', 'ansiedade', 'energia', 'medicação', 'anotação', 'etiquetas', 'criado_em (' . tz_label_br((string)$u['timezone']) . ')', 'estresse', 'acontecimento', 'doces', 'ultraprocessados', 'comeu_fora_de_hora',
            'pens_positivos', 'pens_negativos', 'pens_acelerados', 'pens_grandiosidade', 'pens_ruminacao', 'pens_morte', 'cafe_xicaras_dia', 'cigarros_dia', 'outra_nicotina_dia', 'religiosidade'], ';', '"', '\\');
        $th = function ($v): string { return $v === null ? '' : (THOUGHT_LEVELS[(int)$v] ?? (string)$v); };
        $lv = function ($v): string { return $v === null ? '' : (FOOD_LEVELS[(int)$v] ?? (string)$v); };
        $tzUser = new DateTimeZone((string)$u['timezone']);
        foreach ($entries as $r) {
            $created = (new DateTime((string)$r['created_at'], new DateTimeZone('UTC')))->setTimezone($tzUser)->format('d/m/Y H:i');
            fputcsv($out, [date('d/m/Y', (int)strtotime((string)$r['entry_date'])), PERIODS[$r['period']], $r['mood'], mood_label((int)$r['mood']), fmt_num($r['sleep_hours'], 1), $r['irritability'], $r['anxiety'], $r['energy'], $r['medication_taken'] === null ? '' : ($r['medication_taken'] ? 'sim' : 'não'), $r['note'], $r['tags'], $created,
                $pm[$r['entry_date'] . '|' . $r['period']]['stress_level'] ?? '', $pm[$r['entry_date'] . '|' . $r['period']]['stress_note'] ?? '',
                $lv($pm[$r['entry_date'] . '|' . $r['period']]['food_sweets'] ?? null), $lv($pm[$r['entry_date'] . '|' . $r['period']]['food_processed'] ?? null),
                isset($pm[$r['entry_date'] . '|' . $r['period']]['food_binge']) ? ((int)$pm[$r['entry_date'] . '|' . $r['period']]['food_binge'] ? 'sim' : 'não') : '',
                $th($pm[$r['entry_date'] . '|' . $r['period']]['th_positive'] ?? null), $th($pm[$r['entry_date'] . '|' . $r['period']]['th_negative'] ?? null),
                $th($pm[$r['entry_date'] . '|' . $r['period']]['th_racing'] ?? null), $th($pm[$r['entry_date'] . '|' . $r['period']]['th_grandiose'] ?? null),
                $th($pm[$r['entry_date'] . '|' . $r['period']]['th_rumination'] ?? null), $th($pm[$r['entry_date'] . '|' . $r['period']]['th_death'] ?? null),
                $dayRows[$r['entry_date']]['caffeine_cups'] ?? '', $dayRows[$r['entry_date']]['cigarettes'] ?? '',
                isset($dayRows[$r['entry_date']]['nicotine_other']) ? (NICOTINE_OTHER[$dayRows[$r['entry_date']]['nicotine_other']] ?? '') : '',
                isset($pm[$r['entry_date'] . '|' . $r['period']]['religiosity']) ? (RELIGIOSITY_LEVELS[(int)$pm[$r['entry_date'] . '|' . $r['period']]['religiosity']] ?? '') : ''], ';', '"', '\\');
        }
        rewind($out);
        $s = (string)stream_get_contents($out);
        fclose($out);
        return $s;
    };
    if ($fmt === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"bipolar-registros-$stamp.csv\"");
        echo $csv($entries);
        exit;
    }
    $user = $u;
    unset($user['id'], $user['password_hash'], $user['session_epoch'], $user['sex_pin_hash'], $user['prefs'], $user['ai_key_enc'], $user['mfa_totp_secret_enc'], $user['mfa_totp_last_step']);
    $user['verificacao_duas_etapas'] = mfa_enabled($u); // V4.4: o segredo (cifrado) e os códigos nunca saem na exportação
    $user['tem_chave_ia'] = !empty($u['ai_key_enc']); // a chave de IA (cifrada) nunca sai na exportação
    $user['preferencias'] = user_prefs($u);
    $user['tem_senha'] = !empty($u['password_hash']);
    $user['tem_pin'] = !empty($u['sex_pin_hash']);
    $data = [
        'exportado_em_utc' => gmdate('c'),
        'usuario' => $user,
        'identidades' => $q('SELECT provider, created_at FROM oauth_identities WHERE user_id = ?'),
        'registros' => $entries,
        'midias' => $q('SELECT id, kind, mime, size_bytes, original_name, created_at FROM media WHERE user_id = ?'),
        'destinatarios' => $q('SELECT name, email, role, frequency, interval_days, weekday, include_notes, include_markers, include_sexuality, include_substances, include_food, include_death, include_alerts, include_custom_alerts, invite_status, confirmed_at, last_sent_at FROM recipients WHERE user_id = ?'),
        'marcadores_diarios' => $q('SELECT entry_date, bed_time, wake_time, sleep_quality, sun_minutes, sun_period, caffeine_cups, alcohol_units, substances, exercise_minutes, racing_thoughts, fast_speech, impulsive_spending, spending_amount, irritability_day, cycle_phase, stress_level, stress_note, food_sweets, food_processed, food_binge, th_positive, th_negative, th_grandiose, th_rumination, religiosity, th_death, cigarettes, nicotine_other, doses_json, signs_json, updated_at FROM daily_markers WHERE user_id = ? ORDER BY entry_date'),
        'marcadores_por_periodo' => $pmRows,
        'sexualidade' => $q('SELECT entry_date, libido, activities, frequency, online_minutes, different_from_usual, note, updated_at FROM sexuality_log WHERE user_id = ? ORDER BY entry_date'),
        'substancias' => $q('SELECT entry_date, period, substance, form, other_name, amount, reason, mood_effect, above_usual, created_at FROM substance_log WHERE user_id = ? ORDER BY entry_date, id'),
        'alertas' => $q('SELECT rule_code, custom_rule_id, is_private, severity, period_start, period_end, title, explanation, feedback, created_at FROM alerts WHERE user_id = ? ORDER BY created_at'),
        'aparelhos_biometria' => $q('SELECT name, alg, created_at, last_used_at FROM webauthn_credentials WHERE user_id = ?'),
        'uso_ia' => $q('SELECT month, requests, input_tokens, output_tokens, cost_usd FROM ai_usage WHERE user_id = ?'),
        'lembretes' => $q('SELECT time_local, days, channel, active FROM reminders WHERE user_id = ?'),
        'notificacoes_push' => $q('SELECT created_at FROM push_subscriptions WHERE user_id = ?'),
        'alertas_personalizados' => $q('SELECT name, logic, conditions_json, severity, notify_push, notify_email, cooldown_days, active, last_fired_on, created_at FROM alert_rules WHERE user_id = ? ORDER BY id'),
        // V4.8: com quem você compartilhou e quem viu o quê e quando
        'compartilhamentos_profissionais' => $q('SELECT s.status, s.scope_mask, s.data_from, s.expires_on, s.created_at, s.accepted_at, s.ended_at, s.ended_by, COALESCE(p.name, s.invite_email) AS profissional FROM care_shares s LEFT JOIN users p ON p.id = s.professional_id WHERE s.patient_id = ? ORDER BY s.id'),
        'acessos_profissionais' => $q('SELECT actor_name, action, scope_mask, range_from, range_to, created_at FROM care_access_log WHERE patient_id = ? ORDER BY id'),
        'observacao' => 'O pacote ZIP (Conta > Seus dados) traz este arquivo, o CSV e todos os áudios, vídeos e imagens.',
    ];
    if ($fmt === 'zip') {
        // V4.3: pacote completo — JSON + CSV + todos os arquivos de mídia
        if (!class_exists('ZipArchive')) json_out(['erro' => 'Este servidor não oferece arquivos ZIP. Use o JSON e baixe as mídias pelo Histórico.'], 501);
        @set_time_limit(300);
        $tmp = (string)tempnam(sys_get_temp_dir(), 'bpexp');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) json_out(['erro' => 'Não foi possível montar o pacote. Tente de novo.'], 500);
        $zip->addFromString('dados.json', (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->addFromString('registros.csv', $csv($entries));
        $st = $pdo->prepare('SELECT id, stored_name, original_name, mime FROM media WHERE user_id = ? ORDER BY id');
        $st->execute([$u['id']]);
        $dir = STORAGE_DIR . '/media/' . (int)$u['id'];
        foreach ($st->fetchAll() as $m) {
            $f = $dir . '/' . basename((string)$m['stored_name']);
            if (!is_file($f)) continue;
            $ext = pathinfo((string)$m['stored_name'], PATHINFO_EXTENSION);
            $zip->addFile($f, 'midias/' . (int)$m['id'] . ($ext !== '' ? '.' . preg_replace('/[^a-z0-9]/i', '', $ext) : ''));
        }
        $zip->addFromString('LEIA-ME.txt', "Cópia completa dos seus dados do Bipolar, gerada em " . gmdate('d/m/Y H:i') . " (UTC).\r\n"
            . "ATENÇÃO: este pacote contém dados íntimos de saúde (por exemplo sexualidade, substâncias, pensamentos de morte e anotações, se você registrou). Guarde em local seguro.\r\n"
            . "dados.json: tudo o que está guardado na sua conta.\r\nregistros.csv: os registros de humor, para abrir no Excel.\r\nmidias/: áudios, vídeos e imagens (o número é o id em dados.json > midias).\r\n");
        $zip->close();
        header('Content-Type: application/zip');
        header("Content-Disposition: attachment; filename=\"bipolar-tudo-$stamp.zip\"");
        header('Content-Length: ' . (string)filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
    header("Content-Disposition: attachment; filename=\"bipolar-dados-$stamp.json\"");
    json_out($data);
}
if ($action === 'settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_input();
    $tz = (string)($in['timezone'] ?? $u['timezone']);
    if (!valid_timezone($tz)) json_out(['erro' => 'Fuso horário inválido.'], 422);
    $email = normalize_email(is_string($in['email'] ?? null) ? $in['email'] : (string)($u['email'] ?? ''));
    if ($email !== '' && !valid_email($email)) json_out(['erro' => 'E-mail inválido.'], 422);
    // V5.1: o e-mail NÃO muda aqui. Trocar pede confirmação de identidade e depois o clique no link enviado ao novo endereço.
    $aviso = null; $pendente = $u['pending_email'] ?? null;
    if ($email !== normalize_email((string)($u['email'] ?? '')) && $email !== normalize_email((string)($pendente ?? ''))) {
        if (!stepup_check($u, $in)) stepup_required($u, 'trocar o e-mail');
        if ($email === '') {
            if (has_email_identity($u)) json_out(['erro' => 'Você entra com e-mail e senha, então o e-mail não pode ficar em branco.'], 422);
            email_change_cancel($u);
            $pdo->prepare('UPDATE users SET email = NULL, email_verified_email = NULL WHERE id = ?')->execute([$u['id']]);
            $aviso = 'E-mail removido. Lembretes por e-mail deixam de ser enviados.'; $pendente = null;
        } else {
            if (!rate_limit('email_change_' . $u['id'], 5, 3600, false)) json_out(['erro' => 'Muitos pedidos de troca de e-mail. Aguarde uma hora.'], 429);
            email_change_request($u, $email);
            $pendente = $email;
            $aviso = 'Enviamos um link de confirmação para ' . $email . '. Seu e-mail só muda depois que você clicar nele (vale ' . EMAIL_CHANGE_TTL_HOURS . ' h). Até lá, continua valendo o e-mail atual.';
        }
    }
    $pdo->prepare('UPDATE users SET name = ?, timezone = ?, extreme_alerts = ? WHERE id = ?')
        ->execute([mb_substr(trim((string)($in['name'] ?? '')), 0, 150), $tz, !empty($in['extreme_alerts']) ? 1 : 0, $u['id']]);
    if ($tz !== (string)$u['timezone']) $pdo->prepare('UPDATE reminders SET next_due_at = NULL WHERE user_id = ?')->execute([$u['id']]); // V5.1: recalcula no novo fuso
    json_out(['ok' => true, 'email_pendente' => $pendente, 'aviso' => $aviso]);
}
if ($action === 'email_cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') { // V5.1
    email_change_cancel($u);
    json_out(['ok' => true]);
}
if ($action === 'email_confirm' && $_SERVER['REQUEST_METHOD'] === 'POST') { // V5.1: comprovar o e-mail atual (ex.: sem verificação obrigatória)
    $cur = normalize_email((string)($u['email'] ?? ''));
    if ($cur === '') json_out(['erro' => 'Informe um e-mail primeiro.'], 422);
    if (!rate_limit('email_change_' . $u['id'], 5, 3600, false)) json_out(['erro' => 'Muitos pedidos. Aguarde uma hora.'], 429);
    email_change_request($u, $cur);
    json_out(['ok' => true, 'aviso' => 'Enviamos um link de confirmação para ' . $cur . '.']);
}
if ($action === 'prefs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_input();
    if (!array_key_exists('subst_favorites', $in)) $in['subst_favorites'] = user_prefs($u)['subst_favorites']; // favoritos são editados no próprio módulo
    foreach (['trusted_contact', 'death_blur'] as $keep) if (!array_key_exists($keep, $in)) $in[$keep] = user_prefs($u)[$keep]; // V4.8
    $p = normalize_prefs($in);
    // mantém as chaves editadas em outras telas (alertas, bloqueio, IA, ajuste de sensibilidade)
    $raw = json_decode((string)($u['prefs'] ?? ''), true);
    $all = array_merge(is_array($raw) ? $raw : [], $p);
    $pdo->prepare('UPDATE users SET prefs = ? WHERE id = ?')->execute([json_encode($all, JSON_UNESCAPED_UNICODE), $u['id']]);
    if (!$p['modules']['sexualidade'] && !$p['modules']['substancias'] && !$p['modules']['pensamentos_morte']) unset($_SESSION['sex_unlocked']);
    json_out(['ok' => true, 'prefs' => $p]);
}
if ($action === 'sexpin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_input();
    if (!rate_limit('sexpin_' . $u['id'], 5, 900)) json_out(['erro' => 'Muitas tentativas. Aguarde 15 minutos.'], 429);
    if (!empty($u['sex_pin_hash']) && !password_verify((string)($in['atual'] ?? ''), (string)$u['sex_pin_hash'])) json_out(['erro' => 'PIN atual incorreto.'], 422);
    $pin = (string)($in['pin'] ?? '');
    if ($pin !== '' && !preg_match('/^\d{4,8}$/', $pin)) json_out(['erro' => 'O PIN deve ter de 4 a 8 números.'], 422);
    $pdo->prepare('UPDATE users SET sex_pin_hash = ? WHERE id = ?')->execute([$pin === '' ? null : password_hash($pin, PASSWORD_DEFAULT), $u['id']]);
    unset($_SESSION['sex_unlocked']);
    json_out(['ok' => true, 'has_pin' => $pin !== '']);
}
if ($action === 'password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_input();
    $cur = is_string($in['atual'] ?? null) ? $in['atual'] : '';
    $new = is_string($in['nova'] ?? null) ? $in['nova'] : '';
    if (empty($u['password_hash'])) json_out(['erro' => 'Esta conta não usa senha.'], 422);
    if (!rate_limit('pwchange_' . $u['id'], 10, 900)) json_out(['erro' => 'Muitas tentativas. Aguarde 15 minutos.'], 429);
    if (!password_verify($cur, (string)$u['password_hash'])) json_out(['erro' => 'Senha atual incorreta.'], 422);
    if ($p = password_problem($new, (string)$u['email'])) json_out(['erro' => $p], 422);
    set_password((int)$u['id'], $new);
    login_user((int)$u['id']); // nova sessão com o novo epoch; demais aparelhos saem
    json_out(['ok' => true, 'csrf' => csrf_token()]);
}
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((json_input()['confirm'] ?? '') !== 'EXCLUIR') json_out(['erro' => 'Confirmação incorreta.'], 422);
    require_once APP_ROOT . '/lib/storage_cleanup.php';
    // V5.1: primeiro o banco (cascata apaga o resto); depois os arquivos, conferindo cada um.
    // Se algum arquivo resistir, a resposta diz isso (não promete exclusão completa) e o cron termina a limpeza.
    $st = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $st->execute([$u['id']]);
    if ($st->rowCount() !== 1) json_out(['erro' => 'Não foi possível excluir a conta agora. Tente de novo.'], 500);
    $filesOk = storage_remove_dir(STORAGE_DIR . '/media/' . (int)$u['id']);
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true, 'completo' => $filesOk, 'mensagem' => $filesOk ? 'Sua conta e todos os seus dados foram apagados.'
        : 'Sua conta e seus registros foram apagados. Alguns arquivos de mídia ainda não puderam ser removidos do servidor; a limpeza automática tenta de novo em até 1 hora. Ninguém consegue abri-los pelo app.']);
}
json_out(['erro' => 'Ação inválida.'], 400);
