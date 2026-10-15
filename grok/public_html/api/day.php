<?php
/** Marcadores diários, Sexualidade (privado) e indicador de sinais de (hipo)mania. */
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/custom_alerts.php';
if (($_GET['action'] ?? '') === 'unlock') define('ALLOW_LOCKED', true);
$u = require_user_api();
$pdo = db();
$prefs = user_prefs($u);
$sexOn = $prefs['modules']['sexualidade'];
$subOn = $prefs['modules']['substancias'];
$m = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');

/**
 * Os módulos privados (Sexualidade e Substâncias) ficam bloqueados por biometria e/ou PIN (se houver) pelo tempo
 * escolhido em Conta (padrão 15 minutos) — ver app/lib/lock.php.
 * @param array<string,mixed> $u
 */
function sex_unlocked(array $u): bool { return private_unlocked($u); }

if ($m === 'GET') {
    $from = (string)($_GET['from'] ?? '');
    $to = (string)($_GET['to'] ?? '');
    if (!valid_iso_date($from) || !valid_iso_date($to) || $from > $to) json_out(['erro' => 'Datas inválidas.'], 422);
    // inclui 28 dias antes para a linha de base pessoal de sono
    $base = date('Y-m-d', (int)strtotime("$from -28 days"));
    $q = function (string $sql) use ($pdo, $u, $base, $to): array { $st = $pdo->prepare($sql); $st->execute([$u['id'], $base, $to]); return $st->fetchAll(); };
    $entries = $q('SELECT entry_date, period, mood, sleep_hours, energy, irritability FROM entries WHERE user_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date');
    $markers = $q('SELECT * FROM daily_markers WHERE user_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date');
    $sexAll = $sexOn ? $q('SELECT entry_date, version, libido, activities, frequency, online_minutes, different_from_usual, note FROM sexuality_log WHERE user_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date') : [];
    $subAll = $subOn ? $q('SELECT id, entry_date, period, substance, form, other_name, amount, reason, mood_effect, above_usual FROM substance_log WHERE user_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date, id') : [];
    $ind = mania_indicators($entries, $markers, $sexAll, count($prefs['signs']), $subAll);
    $today = (new DateTime('now', new DateTimeZone((string)$u['timezone'])))->format('Y-m-d');
    $warning = early_warning($ind, min($to, $today));
    $inRange = function (array $rows) use ($from, $to): array { return array_values(array_filter($rows, function (array $r) use ($from, $to): bool { return $r['entry_date'] >= $from && $r['entry_date'] <= $to; })); };
    foreach ($markers as &$mk) { $mk['doses'] = json_decode((string)$mk['doses_json'], true) ?: new stdClass(); $mk['signs'] = json_decode((string)$mk['signs_json'], true) ?: []; unset($mk['doses_json'], $mk['signs_json'], $mk['id'], $mk['user_id']); }
    unset($mk);
    $unlocked = sex_unlocked($u);
    $subUnlocked = $subOn && $unlocked;
    json_out([
        'markers' => $inRange($markers),
        'sexuality' => $sexOn && $unlocked ? $inRange($sexAll) : [],
        'substances' => $subUnlocked ? $inRange($subAll) : [],
        'subst_summary' => $subUnlocked && !empty($_GET['resumo']) ? substance_mood_summary($inRange($subAll), $inRange($entries)) : [],
        'subst' => ['enabled' => $subOn, 'locked' => $subOn && !$unlocked, 'blur' => $prefs['subst_blur'], 'favorites' => $prefs['subst_favorites']],
        'sex' => ['enabled' => $sexOn, 'locked' => $sexOn && !$unlocked, 'has_pin' => !empty($u['sex_pin_hash']), 'has_passkey' => webauthn_count($u) > 0, 'blur' => $prefs['sex_blur']],
        'indicators' => array_filter($ind, function (string $d) use ($from, $to): bool { return $d >= $from && $d <= $to; }, ARRAY_FILTER_USE_KEY) ?: new stdClass(),
        'warning' => $warning,
        'action_plan' => $warning ? $prefs['action_plan'] : '',
    ]);
}

if ($m === 'POST') unset($_SESSION['sig_run']); // dados novos: alertas reavaliados na próxima consulta
if ($m === 'POST' && $action === 'unlock') {
    if (!rate_limit('sexpin_' . $u['id'], 5, 900)) json_out(['erro' => 'Muitas tentativas. Aguarde 15 minutos.'], 429);
    $pin = (string)(json_input()['pin'] ?? '');
    if (empty($u['sex_pin_hash']) && webauthn_count($u) > 0) json_out(['erro' => 'Use a biometria do aparelho para desbloquear.'], 422);
    if (empty($u['sex_pin_hash']) || password_verify($pin, (string)$u['sex_pin_hash'])) {
        lock_mark_unlocked();
        json_out(['ok' => true]);
    }
    json_out(['erro' => 'PIN incorreto.'], 422);
}
if ($m === 'POST' && $action === 'lock') {
    lock_now();
    json_out(['ok' => true]);
}

/**
 * Mantém daily_markers.alcohol_units igual à soma dos registros de Álcool do dia (um só número de álcool no app).
 * V4.2: o mesmo para Café/cafeína → daily_markers.caffeine_cups.
 */
function sync_alcohol(PDO $pdo, int $uid, string $date, string $sub = 'alcool'): void
{
    $col = $sub === 'cafe' ? 'caffeine_cups' : 'alcohol_units';
    $max = $sub === 'cafe' ? 30 : 50;
    $st = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(COALESCE(amount, 1)), 0) FROM substance_log WHERE user_id = ? AND entry_date = ? AND substance = ?');
    $st->execute([$uid, $date, $sub]);
    [$n, $sum] = $st->fetch(PDO::FETCH_NUM);
    if ((int)$n === 0) return;
    $pdo->prepare("INSERT INTO daily_markers (user_id, entry_date, $col) VALUES (?,?,?) ON DUPLICATE KEY UPDATE $col = VALUES($col)")
        ->execute([$uid, $date, min($max, (int)round((float)$sum))]);
}
if ($m === 'POST' && in_array($action, ['subst_add', 'subst_delete', 'subst_fav', 'subst_wipe'], true)) {
    if (!$subOn) json_out(['erro' => 'O módulo Substâncias está desligado. Ative em Conta.'], 403);
    if (!sex_unlocked($u)) json_out(['erro' => 'Desbloqueie com seu PIN.', 'bloqueado' => true], 423);
    $in = json_input();
    if ($action === 'subst_fav') {
        $p = json_decode((string)($u['prefs'] ?? ''), true);
        $p = is_array($p) ? $p : [];
        $p['subst_favorites'] = normalize_subst_favorites((array)($in['favorites'] ?? []));
        $pdo->prepare('UPDATE users SET prefs = ? WHERE id = ?')->execute([json_encode($p, JSON_UNESCAPED_UNICODE), $u['id']]);
        json_out(['ok' => true, 'favorites' => $p['subst_favorites']]);
    }
    if ($action === 'subst_wipe') {
        if (($in['confirm'] ?? false) !== true) json_out(['erro' => 'Confirmação ausente.'], 422);
        $pdo->prepare('DELETE FROM substance_log WHERE user_id = ?')->execute([$u['id']]);
        json_out(['ok' => true]);
    }
    if ($action === 'subst_delete') {
        $st = $pdo->prepare('SELECT entry_date, substance FROM substance_log WHERE id = ? AND user_id = ?');
        $st->execute([(int)($in['id'] ?? 0), $u['id']]);
        $row = $st->fetch();
        if (!$row) json_out(['erro' => 'Registro não encontrado.'], 404);
        $pdo->prepare('DELETE FROM substance_log WHERE id = ? AND user_id = ?')->execute([(int)$in['id'], $u['id']]);
        if (in_array($row['substance'], ['alcool', 'cafe'], true)) sync_alcohol($pdo, (int)$u['id'], (string)$row['entry_date'], (string)$row['substance']);
        json_out(['ok' => true]);
    }
    if (!rate_limit('subst_' . $u['id'], 300, 3600)) json_out(['erro' => 'Muitos envios em pouco tempo. Tente mais tarde.'], 429);
    [$err, $v] = validate_substance($in);
    if ($err !== null) json_out(['erro' => $err], 422);
    $pdo->prepare('INSERT INTO substance_log (user_id, entry_date, period, substance, form, other_name, amount, reason, mood_effect, above_usual) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$u['id'], $v['entry_date'], $v['period'], $v['substance'], $v['form'], $v['other_name'], $v['amount'], $v['reason'], $v['mood_effect'], $v['above_usual']]);
    $id = (int)$pdo->lastInsertId();
    if (in_array($v['substance'], ['alcool', 'cafe'], true)) sync_alcohol($pdo, (int)$u['id'], $v['entry_date'], $v['substance']);
    json_out(['ok' => true, 'id' => $id, 'alertas_personalizados' => custom_after_save($pdo, $u, (string)$v['entry_date'])]);
}

if ($m === 'POST' && $action === 'sex') {
    if (!$sexOn) json_out(['erro' => 'O módulo Sexualidade está desligado. Ative em Conta.'], 403);
    if (!sex_unlocked($u)) json_out(['erro' => 'Desbloqueie com seu PIN.', 'bloqueado' => true], 423);
    $sin = json_input();
    [$err, $v] = validate_sexuality($sin);
    if ($err !== null) json_out(['erro' => $err], 422);
    $claimed = version_guard($pdo, 'sexuality_log', ['user_id' => $u['id'], 'entry_date' => $v['entry_date']], client_version($sin)); // V4.3.1
    $pdo->prepare('INSERT INTO sexuality_log (user_id, entry_date, libido, activities, frequency, online_minutes, different_from_usual, note) VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE ' . ($claimed ? '' : 'version=version+1, ') . 'libido=VALUES(libido), activities=VALUES(activities), frequency=VALUES(frequency), online_minutes=VALUES(online_minutes),
        different_from_usual=VALUES(different_from_usual), note=VALUES(note)')
        ->execute([$u['id'], $v['entry_date'], $v['libido'], $v['activities'], $v['frequency'], $v['online_minutes'], $v['different_from_usual'], $v['note']]);
    $st = $pdo->prepare('SELECT version FROM sexuality_log WHERE user_id = ? AND entry_date = ?');
    $st->execute([$u['id'], $v['entry_date']]);
    json_out(['ok' => true, 'version' => (int)$st->fetchColumn(), 'alertas_personalizados' => custom_after_save($pdo, $u, (string)$v['entry_date'])]);
}
if ($m === 'POST' && $action === 'sex_delete') {
    if (!sex_unlocked($u)) json_out(['erro' => 'Desbloqueie com seu PIN.', 'bloqueado' => true], 423);
    $in = json_input();
    if (($in['all'] ?? false) === true) $pdo->prepare('DELETE FROM sexuality_log WHERE user_id = ?')->execute([$u['id']]);
    elseif (valid_iso_date($in['entry_date'] ?? null)) $pdo->prepare('DELETE FROM sexuality_log WHERE user_id = ? AND entry_date = ?')->execute([$u['id'], $in['entry_date']]);
    json_out(['ok' => true]);
}

if ($m === 'POST') {
    if (!rate_limit('day_' . $u['id'], 240, 3600)) json_out(['erro' => 'Muitos envios em pouco tempo. Tente mais tarde.'], 429);
    [$err, $v] = validate_day(json_input());
    if ($err !== null) json_out(['erro' => $err], 422);
    $cols = array_keys($v);
    $set = array_map(function (string $c): string { return "$c=VALUES($c)"; }, array_diff($cols, ['entry_date']));
    $sql = 'INSERT INTO daily_markers (user_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(',?', count($cols)) . ')'
        . ($set ? ' ON DUPLICATE KEY UPDATE ' . implode(', ', $set) : ' ON DUPLICATE KEY UPDATE entry_date=entry_date');
    $pdo->prepare($sql)->execute(array_merge([$u['id']], array_values($v)));
    json_out(['ok' => true, 'alertas_personalizados' => custom_after_save($pdo, $u, (string)$v['entry_date'])]);
}
json_out(['erro' => 'Método não permitido.'], 405);
