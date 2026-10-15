<?php
require __DIR__ . '/../_boot.php';
$u = require_user_api();
$pdo = db();
$action = (string)($_GET['action'] ?? '');

if ($action === 'export' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = function ($sql) use ($pdo, $u) { $st = $pdo->prepare($sql); $st->execute([$u['id']]); return $st->fetchAll(); };
    $entries = $q('SELECT entry_date, period, mood, sleep_hours, irritability, anxiety, energy, medication_taken, note, tags, created_at, updated_at FROM entries WHERE user_id = ? ORDER BY entry_date, period');
    try { $utz = new DateTimeZone((string)($u['timezone'] ?? 'America/Sao_Paulo')); } catch (Exception $e) { $utz = new DateTimeZone('America/Sao_Paulo'); }
    $stamp = (new DateTime('now', $utz))->format('Y-m-d-His'); // nome do arquivo no fuso do usuário
    $fmt = (string)($_GET['format'] ?? 'json');
    /** Formato Excel-BR: separador ";", vírgula decimal, datas dd/mm/aaaa, UTF-8 com BOM */
    $csv = function (array $entries) use ($u): string {
        $out = fopen('php://temp', 'w+');
        if ($out === false) return '';
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['data', 'período', 'humor', 'descrição', 'sono_h', 'irritabilidade', 'ansiedade', 'energia', 'medicação', 'anotação', 'etiquetas', 'criado_em (' . tz_label_br((string)$u['timezone']) . ')'], ';', '"', '\\');
        $tzUser = new DateTimeZone((string)$u['timezone']);
        foreach ($entries as $r) {
            $created = (new DateTime((string)$r['created_at'], new DateTimeZone('UTC')))->setTimezone($tzUser)->format('d/m/Y H:i');
            fputcsv($out, [date('d/m/Y', (int)strtotime((string)$r['entry_date'])), PERIODS[$r['period']], $r['mood'], mood_label((int)$r['mood']), fmt_num($r['sleep_hours'], 1), $r['irritability'], $r['anxiety'], $r['energy'], $r['medication_taken'] === null ? '' : ($r['medication_taken'] ? 'sim' : 'não'), $r['note'], $r['tags'], $created], ';', '"', '\\');
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
        'destinatarios' => $q('SELECT name, email, role, frequency, interval_days, weekday, include_notes, include_markers, include_sexuality, include_substances, include_alerts, include_custom_alerts, invite_status, confirmed_at, last_sent_at FROM recipients WHERE user_id = ?'),
        'marcadores_diarios' => $q('SELECT entry_date, bed_time, wake_time, sleep_quality, sun_minutes, sun_period, caffeine_cups, alcohol_units, substances, exercise_minutes, racing_thoughts, fast_speech, impulsive_spending, spending_amount, irritability_day, cycle_phase, stress_level, stress_note, doses_json, signs_json, updated_at FROM daily_markers WHERE user_id = ? ORDER BY entry_date'),
        'sexualidade' => $q('SELECT entry_date, libido, activities, frequency, online_minutes, different_from_usual, note, updated_at FROM sexuality_log WHERE user_id = ? ORDER BY entry_date'),
        'substancias' => $q('SELECT entry_date, period, substance, form, other_name, amount, reason, mood_effect, above_usual, created_at FROM substance_log WHERE user_id = ? ORDER BY entry_date, id'),
        'alertas' => $q('SELECT rule_code, custom_rule_id, is_private, severity, period_start, period_end, title, explanation, feedback, created_at FROM alerts WHERE user_id = ? ORDER BY created_at'),
        'aparelhos_biometria' => $q('SELECT name, alg, created_at, last_used_at FROM webauthn_credentials WHERE user_id = ?'),
        'uso_ia' => $q('SELECT month, requests, input_tokens, output_tokens, cost_usd FROM ai_usage WHERE user_id = ?'),
        'lembretes' => $q('SELECT time_local, days, channel, active FROM reminders WHERE user_id = ?'),
        'notificacoes_push' => $q('SELECT created_at FROM push_subscriptions WHERE user_id = ?'),
        'alertas_personalizados' => $q('SELECT name, logic, conditions_json, severity, notify_push, notify_email, cooldown_days, active, last_fired_on, created_at FROM alert_rules WHERE user_id = ? ORDER BY id'),
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
    $email = trim((string)($in['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['erro' => 'E-mail inválido.'], 422);
    $pdo->prepare('UPDATE users SET name = ?, email = ?, timezone = ?, extreme_alerts = ? WHERE id = ?')
        ->execute([mb_substr(trim((string)($in['name'] ?? '')), 0, 150), $email ?: null, $tz, !empty($in['extreme_alerts']) ? 1 : 0, $u['id']]);
    json_out(['ok' => true]);
}
if ($action === 'prefs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_input();
    if (!array_key_exists('subst_favorites', $in)) $in['subst_favorites'] = user_prefs($u)['subst_favorites']; // favoritos são editados no próprio módulo
    $p = normalize_prefs($in);
    // mantém as chaves editadas em outras telas (alertas, bloqueio, IA, ajuste de sensibilidade)
    $raw = json_decode((string)($u['prefs'] ?? ''), true);
    $all = array_merge(is_array($raw) ? $raw : [], $p);
    $pdo->prepare('UPDATE users SET prefs = ? WHERE id = ?')->execute([json_encode($all, JSON_UNESCAPED_UNICODE), $u['id']]);
    if (!$p['modules']['sexualidade'] && !$p['modules']['substancias']) unset($_SESSION['sex_unlocked']);
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
    $dir = STORAGE_DIR . '/media/' . (int)$u['id'];
    if (is_dir($dir)) { foreach (glob($dir . '/*') ?: [] as $f) @unlink($f); @rmdir($dir); }
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$u['id']]); // cascata apaga o resto
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true]);
}
json_out(['erro' => 'Ação inválida.'], 400);
