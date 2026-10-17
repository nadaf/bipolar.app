<?php
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/custom_alerts.php';
$u = require_user_api();
$pdo = db();
$m = $_SERVER['REQUEST_METHOD'];

if ($m === 'GET') {
    $from = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-30 days')));
    $to = (string)($_GET['to'] ?? date('Y-m-d', strtotime('+1 day')));
    if (!valid_iso_date($from) || !valid_iso_date($to)) json_out(['erro' => 'Datas inválidas.'], 422);
    // V4.7: o Histórico mostra junto o estresse e a alimentação daquele período (period_markers)
    try {
        $st = $pdo->prepare('SELECT e.id, e.version, e.entry_date, e.period, e.mood, e.sleep_hours, e.irritability, e.anxiety, e.energy, e.medication_taken, e.note, e.tags,
            pm.stress_level AS pm_stress, pm.stress_note AS pm_stress_note, pm.food_sweets AS pm_sweets, pm.food_processed AS pm_processed, pm.food_binge AS pm_binge
            FROM entries e LEFT JOIN period_markers pm ON pm.user_id = e.user_id AND pm.entry_date = e.entry_date AND pm.period = e.period
            WHERE e.user_id = ? AND e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date, e.period');
        $st->execute([$u['id'], $from, $to]);
    } catch (PDOException $e) { // migração V4.7 ainda não aplicada
        $st = $pdo->prepare('SELECT id, version, entry_date, period, mood, sleep_hours, irritability, anxiety, energy, medication_taken, note, tags FROM entries WHERE user_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date, period');
        $st->execute([$u['id'], $from, $to]);
    }
    json_out(['entries' => $st->fetchAll()]);
}

if ($m === 'POST') {
    if (!rate_limit('entries_' . $u['id'], 120, 3600)) json_out(['erro' => 'Muitos registros em pouco tempo. Tente mais tarde.'], 429);
    $in = json_input();
    [$err, $v] = validate_entry($in);
    if ($err !== null) json_out(['erro' => $err], 422);
    $ver = client_version($in); // V4.3.1: concorrência otimista
    $claimed = false;
    // Edição por id (V4.1): o registro editado precisa ser da pessoa e continuar no mesmo dia/período,
    // ou ir para um dia/período livre. Nunca sobrescreve o registro de outro período.
    if (isset($in['id']) && (int)$in['id'] > 0) {
        $st = $pdo->prepare('SELECT id, entry_date, period FROM entries WHERE id = ? AND user_id = ?');
        $st->execute([(int)$in['id'], $u['id']]);
        $cur = $st->fetch();
        if (!$cur) json_out(['erro' => 'Registro não encontrado. Atualize a página.'], 404);
        if ((string)$cur['entry_date'] !== $v['entry_date'] || (string)$cur['period'] !== $v['period']) {
            $st = $pdo->prepare('SELECT id FROM entries WHERE user_id = ? AND entry_date = ? AND period = ?');
            $st->execute([$u['id'], $v['entry_date'], $v['period']]);
            if ($st->fetchColumn()) json_out(['erro' => 'Já existe um registro de ' . (PERIODS[$v['period']] ?? $v['period']) . ' nesse dia. Abra esse registro para editá-lo.', 'conflito' => true], 409);
            $claimed = version_guard($pdo, 'entries', ['id' => (int)$cur['id'], 'user_id' => $u['id']], $ver);
            $pdo->prepare('UPDATE entries SET entry_date = ?, period = ? WHERE id = ? AND user_id = ?')->execute([$v['entry_date'], $v['period'], (int)$cur['id'], $u['id']]);
        } else {
            $claimed = version_guard($pdo, 'entries', ['id' => (int)$cur['id'], 'user_id' => $u['id']], $ver);
        }
    } else {
        // sem id: confere o registro do mesmo dia/período (version 0 = a pessoa achava que o período estava livre)
        $claimed = version_guard($pdo, 'entries', ['user_id' => $u['id'], 'entry_date' => $v['entry_date'], 'period' => $v['period']], $ver);
    }
    // Edição segura: num registro já existente (mesma data + período), só os campos ENVIADOS são alterados.
    // Campos ausentes do JSON (ex.: cliente antigo, offline, integração) preservam o valor salvo.
    $set = ['mood=VALUES(mood)', 'id=LAST_INSERT_ID(id)'];
    foreach (ENTRY_OPTIONAL_FIELDS as $f) {
        if (array_key_exists($f, $in)) $set[] = "$f=VALUES($f)";
    }
    if ($v['client_uuid'] !== null) $set[] = 'client_uuid=VALUES(client_uuid)';
    if (!$claimed) $set[] = 'version=version+1';
    // V4.2: dois salvamentos quase simultâneos (toque duplo, fila offline + online) podem colidir no índice único
    // e o InnoDB devolve deadlock (1213) ou espera de trava (1205). Repete até 3 vezes antes de desistir.
    $ins = $pdo->prepare('INSERT INTO entries (user_id, entry_date, period, mood, sleep_hours, irritability, anxiety, energy, medication_taken, note, tags, client_uuid)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE ' . implode(', ', $set));
    for ($try = 1; ; $try++) {
        try {
            $ins->execute([$u['id'], $v['entry_date'], $v['period'], $v['mood'], $v['sleep_hours'], $v['irritability'], $v['anxiety'], $v['energy'], $v['medication_taken'], $v['note'], $v['tags'], $v['client_uuid']]);
            break;
        } catch (PDOException $ex) {
            if ($try >= 3 || !in_array((int)($ex->errorInfo[1] ?? 0), [1213, 1205], true)) throw $ex;
            usleep(80000 * $try);
        }
    }
    $id = (int)$pdo->lastInsertId();
    if ($id === 0) { // ON DUPLICATE sem mudança: lastInsertId pode vir 0; busca o id do registro do período
        $st = $pdo->prepare('SELECT id FROM entries WHERE user_id = ? AND entry_date = ? AND period = ?');
        $st->execute([$u['id'], $v['entry_date'], $v['period']]);
        $id = (int)$st->fetchColumn();
    }
    unset($_SESSION['sig_run']); // dados novos: o Histórico reavalia os alertas na próxima consulta

    // Aviso gentil: 3 ou mais registros recentes consecutivos em nível extremo (|humor| >= 2).
    $alert = null;
    if ($u['extreme_alerts']) {
        $st = $pdo->prepare('SELECT mood FROM entries WHERE user_id = ? ORDER BY entry_date DESC, period DESC LIMIT 4');
        $st->execute([$u['id']]);
        $recent = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (count($recent) >= 3) {
            $low = $high = 0;
            foreach ($recent as $r) { if ($r <= -2) $low++; else break; }
            foreach ($recent as $r) { if ($r >= 2) $high++; else break; }
            if ($low >= 3) $alert = 'Percebemos vários registros seguidos de humor bem baixo. Você não está sozinho(a): considere conversar com seu psiquiatra ou psicólogo nos próximos dias. Se pensar em se machucar, ligue agora para o CVV (188) ou SAMU (192).';
            elseif ($high >= 3) $alert = 'Percebemos vários registros seguidos de humor bem elevado. Pode ser um bom momento para falar com seu psiquiatra, cuidar do sono e evitar decisões importantes por impulso. Em emergência, ligue SAMU (192).';
        }
    }
    $st = $pdo->prepare('SELECT version FROM entries WHERE id = ? AND user_id = ?');
    $st->execute([$id, $u['id']]);
    $entry = ['id' => $id, 'version' => (int)$st->fetchColumn(), 'entry_date' => $v['entry_date'], 'period' => $v['period'], 'mood' => $v['mood'], 'sleep_hours' => $v['sleep_hours']];
    json_out(['ok' => true, 'id' => $id, 'alerta' => $alert, 'entry' => $entry, 'alertas_personalizados' => custom_after_save($pdo, $u, (string)$v['entry_date'])]);
}

if ($m === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('DELETE FROM entries WHERE id = ? AND user_id = ?');
    $st->execute([$id, $u['id']]);
    if ($st->rowCount() < 1) json_out(['erro' => 'Registro não encontrado.'], 404); // V4.4
    json_out(['ok' => true]);
}
json_out(['erro' => 'Método não permitido.'], 405);
