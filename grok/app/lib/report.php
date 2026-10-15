<?php
/** Gera dados e HTML de relatório (e-mail e página imprimível). */
require_once APP_ROOT . '/lib/signals.php';
/** @return array<string,mixed> */
function report_data(int $uid, string $fromDate, string $toDate): array {
    $st = db()->prepare('SELECT * FROM entries WHERE user_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date, period');
    $st->execute([$uid, $fromDate, $toDate]);
    $rows = $st->fetchAll();
    $days = [];
    foreach ($rows as $r) $days[$r['entry_date']][$r['period']] = $r;
    $moods = array_map(function ($r) { return (int)$r['mood']; }, $rows);
    // V4.1: sono e adesão com a MESMA regra em todo o app (Meu dia, indicador e relatório):
    // sono por noite = deitar→acordar quando informado; senão o maior "horas de sono" do dia (nunca média de períodos).
    $mk = [];
    try {
        $st = db()->prepare('SELECT entry_date, bed_time, wake_time, doses_json FROM daily_markers WHERE user_id = ? AND entry_date BETWEEN ? AND ?');
        $st->execute([$uid, $fromDate, $toDate]);
        foreach ($st->fetchAll() as $m) $mk[(string)$m['entry_date']] = $m;
    } catch (PDOException $e) { $mk = []; }
    $sleepByDay = [];
    foreach ($rows as $r) if ($r['sleep_hours'] !== null) $sleepByDay[(string)$r['entry_date']] = max($sleepByDay[(string)$r['entry_date']] ?? 0.0, (float)$r['sleep_hours']);
    foreach ($mk as $d => $m) { $h = sleep_hours_from_times($m['bed_time'] ?? null, $m['wake_time'] ?? null); if ($h !== null) $sleepByDay[$d] = $h; }
    $sleep = array_values($sleepByDay);
    // adesão: doses marcadas em Meu dia (por dose) têm prioridade; senão "Tomou a medicação?" dos registros
    $doses = 0; $taken = 0;
    foreach ($mk as $m) foreach ((array)json_decode((string)($m['doses_json'] ?? ''), true) as $t) { $doses++; $taken += (int)$t; }
    $med = array_filter(array_map(function ($r) { return $r['medication_taken']; }, $rows), function ($v) { return $v !== null; });
    $dist = array_fill_keys(array_keys(MOOD_LEVELS), 0);
    foreach ($moods as $m) $dist[$m]++;
    return [
        'rows' => $rows, 'days' => $days, 'from' => $fromDate, 'to' => $toDate,
        'count' => count($rows),
        'avg' => $moods ? round(array_sum($moods) / count($moods), 2) : null,
        'min' => $moods ? min($moods) : null, 'max' => $moods ? max($moods) : null,
        'sleep_avg' => $sleep ? round(array_sum($sleep) / count($sleep), 1) : null,
        'med_rate' => $doses ? (int)round(100 * $taken / $doses) : ($med ? (int)round(100 * array_sum($med) / count($med)) : null),
        'med_source' => $doses ? 'doses' : ($med ? 'registros' : null),
        'sleep_nights' => count($sleep),
        'sleep_by_day' => $sleepByDay,
        'dist' => $dist,
    ];
}
function fmt_date(string $d): string { return date('d/m/Y', strtotime($d)); }

/**
 * @param array<string,mixed> $user
 * @param array<string,mixed> $d
 */
function report_html(array $user, array $d, bool $withNotes, bool $withMediaList = false): string {
    $h = '<p style="margin:0 0 12px">Período: <strong>' . fmt_date($d['from']) . ' a ' . fmt_date($d['to']) . '</strong> · Paciente: <strong>' . e($user['name'] ?: 'Usuário') . '</strong></p>';
    if (!$d['count']) return $h . '<p>Nenhum registro de humor neste período.</p>';
    $h .= '<table role="presentation" style="width:100%;border-collapse:collapse;margin-bottom:16px;font-size:14px">';
    $sum = [
        'Registros' => $d['count'],
        'Humor médio' => fmt_num($d['avg'], 2, true) . ' (' . mood_label((int)round($d['avg'])) . ')',
        'Faixa' => mood_label($d['min']) . ' → ' . mood_label($d['max']),
        'Sono médio por noite' => $d['sleep_avg'] !== null ? fmt_num($d['sleep_avg'], 1) . ' h (' . plural_br((int)$d['sleep_nights'], 'noite', 'noites') . ')' : 'não informado',
        'Adesão à medicação' => $d['med_rate'] !== null ? $d['med_rate'] . '%' . ($d['med_source'] === 'doses' ? ' das doses marcadas' : ' dos registros') : 'não informada',
    ];
    foreach ($sum as $k => $v) $h .= '<tr><td style="padding:6px;border-bottom:1px solid #e3e8ec;color:#50606c">' . e($k) . '</td><td style="padding:6px;border-bottom:1px solid #e3e8ec"><strong>' . e($v) . '</strong></td></tr>';
    $h .= '</table><h2 style="font-size:16px;margin:24px 0 8px">Distribuição</h2><table role="presentation" style="width:100%;font-size:13px;border-collapse:collapse;margin-bottom:8px">';
    $max = max($d['dist']) ?: 1;
    foreach (array_reverse($d['dist'], true) as $lvl => $n) {
        $w = (int)round(100 * $n / $max);
        $h .= '<tr><td style="padding:3px 6px;white-space:nowrap">' . MOOD_LEVELS[$lvl][2] . ' ' . e(MOOD_LEVELS[$lvl][0]) . '</td><td style="width:55%;padding:3px"><div style="background:' . MOOD_COLORS[$lvl] . ';height:14px;border-radius:7px;width:' . max($w, $n ? 4 : 0) . '%"></div></td><td style="padding:3px 6px;text-align:right">' . $n . '</td></tr>';
    }
    $h .= '</table><h2 style="font-size:16px;margin:28px 0 8px">Linha do tempo (Life Chart)</h2>'
        . '<table style="width:100%;border-collapse:collapse;font-size:12px"><thead><tr><th style="text-align:left;padding:4px">Data</th>';
    foreach (PERIODS as $pl) $h .= '<th style="padding:4px">' . e($pl) . '</th>';
    $h .= '<th style="padding:4px">Sono</th></tr></thead><tbody>';
    foreach ($d['days'] as $date => $per) {
        $h .= '<tr><td style="padding:4px;border-top:1px solid #e3e8ec">' . fmt_date($date) . '</td>';
        // V4.2: mesmo sono por noite do resumo e do Histórico (deitar→acordar; senão o maior "horas de sono" do dia)
        $sleep = isset($d['sleep_by_day'][$date]) ? fmt_num($d['sleep_by_day'][$date], 1) . ' h' : '—';
        foreach (array_keys(PERIODS) as $p) {
            if (isset($per[$p])) {
                $m = (int)$per[$p]['mood'];
                $fg = in_array($m, [-3, -2, 3], true) ? '#fff' : '#1d2b36';
                $h .= '<td style="padding:4px;border-top:1px solid #e3e8ec;text-align:center"><span style="display:inline-block;padding:2px 6px;border-radius:6px;background:' . MOOD_COLORS[$m] . ';color:' . $fg . '" title="' . e(mood_label($m)) . '">' . MOOD_LEVELS[$m][2] . ' ' . ($m > 0 ? '+' : ($m < 0 ? '−' : '')) . abs($m) . '</span></td>';
            } else $h .= '<td style="padding:4px;border-top:1px solid #e3e8ec;text-align:center;color:#9aa">·</td>';
        }
        $h .= '<td style="padding:4px;border-top:1px solid #e3e8ec;text-align:center">' . e($sleep) . '</td></tr>';
    }
    $h .= '</tbody></table>';
    if ($withNotes) {
        $notes = array_filter($d['rows'], function ($r) { return trim((string)$r['note']) !== '' || trim((string)$r['tags']) !== ''; });
        if ($notes) {
            $h .= '<h2 style="font-size:16px;margin:28px 0 8px">Anotações</h2><ul style="padding-left:18px;font-size:13px">';
            foreach ($notes as $r) $h .= '<li style="margin-bottom:6px"><strong>' . fmt_date($r['entry_date']) . ' · ' . e(PERIODS[$r['period']]) . ':</strong> ' . nl2br(e($r['note'])) . ($r['tags'] ? ' <em>[' . e($r['tags']) . ']</em>' : '') . '</li>';
            $h .= '</ul>';
        }
    }
    if ($withMediaList) {
        $st = db()->prepare('SELECT kind, COUNT(*) n FROM media WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ? GROUP BY kind');
        $st->execute([$user['id'], $d['from'], $d['to']]);
        $kinds = ['audio' => ['áudio', 'áudios'], 'video' => ['vídeo', 'vídeos'], 'image' => ['imagem', 'imagens']];
        $list = array_map(function ($r) use ($kinds) { return plural_br((int)$r['n'], $kinds[$r['kind']][0], $kinds[$r['kind']][1]); }, $st->fetchAll());
        if ($list) $h .= '<p style="font-size:13px">Registros detalhados no período: ' . e(implode(', ', $list)) . ' (o conteúdo não é enviado por e-mail; peça ao paciente para mostrar na consulta).</p>';
    }
    return $h;
}

/**
 * Resumo dos marcadores diários e do indicador de sinais de (hipo)mania para relatórios.
 * Sexualidade: apenas agregados (média do desejo, dias com sinais de risco) e só quando $withSex.
 * Substâncias: apenas dias com uso por substância e dias acima do habitual, só quando $withSubst (e módulo ligado).
 * @return array<string,mixed>
 */
function markers_report_data(int $uid, string $fromDate, string $toDate, bool $withSex, bool $withSubst = false): array {
    $pdo = db();
    $base = date('Y-m-d', (int)strtotime("$fromDate -28 days"));
    $q = function (string $sql) use ($pdo, $uid, $base, $toDate): array { $st = $pdo->prepare($sql); $st->execute([$uid, $base, $toDate]); return $st->fetchAll(); };
    $entries = $q('SELECT entry_date, mood, sleep_hours, energy, irritability FROM entries WHERE user_id = ? AND entry_date BETWEEN ? AND ?');
    $markers = $q('SELECT * FROM daily_markers WHERE user_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date');
    $st = $pdo->prepare('SELECT prefs FROM users WHERE id = ?');
    $st->execute([$uid]);
    $prefs = user_prefs(['prefs' => $st->fetchColumn()]);
    // O indicador usa sexualidade só se o módulo estiver ligado; o detalhe só aparece com opt-in do destinatário.
    $sexAll = $prefs['modules']['sexualidade'] ? $q('SELECT entry_date, libido, activities, frequency, online_minutes, different_from_usual FROM sexuality_log WHERE user_id = ? AND entry_date BETWEEN ? AND ?') : [];
    $subAll = $prefs['modules']['substancias'] ? $q('SELECT entry_date, substance, amount, mood_effect, above_usual FROM substance_log WHERE user_id = ? AND entry_date BETWEEN ? AND ?') : [];
    $in = function (array $r) use ($fromDate, $toDate): bool { return $r['entry_date'] >= $fromDate && $r['entry_date'] <= $toDate; };
    // V4.2: o indicador usa os MESMOS dados do Histórico (módulos ligados); o detalhe privado só aparece com opt-in.
    $ind = array_filter(mania_indicators($entries, $markers, $sexAll, count($prefs['signs']), $subAll), function (string $d) use ($fromDate, $toDate): bool { return $d >= $fromDate && $d <= $toDate; }, ARRAY_FILTER_USE_KEY);
    $mk = array_values(array_filter($markers, $in));
    $avg = function (string $col) use ($mk): ?float { $v = array_filter(array_column($mk, $col), function ($x) { return $x !== null; }); return $v ? round(array_sum($v) / count($v), 1) : null; };
    // V4.2: conta todo dia com o sinal marcado (leve, moderado ou intenso), como aparece em Meu dia.
    $count = function (string $col) use ($mk): int { return count(array_filter($mk, function ($r) use ($col) { return (int)$r[$col] >= 1; })); };
    $sleeps = array_filter(array_map(function ($r) { return sleep_hours_from_times($r['bed_time'], $r['wake_time']); }, $mk), function ($x) { return $x !== null; });
    $doses = 0; $taken = 0;
    foreach ($mk as $r) foreach ((array)json_decode((string)$r['doses_json'], true) as $t) { $doses++; $taken += (int)$t; }
    $d = [
        'days' => count($mk),
        'sleep_times_avg' => $sleeps ? round(array_sum($sleeps) / count($sleeps), 1) : null,
        'sun_avg' => $avg('sun_minutes'), 'caffeine_avg' => $avg('caffeine_cups'), 'alcohol_avg' => $avg('alcohol_units'), 'exercise_avg' => $avg('exercise_minutes'),
        'racing' => $count('racing_thoughts'), 'speech' => $count('fast_speech'), 'spending' => $count('impulsive_spending'), 'irrit' => $count('irritability_day'), 'stress' => $count('stress_level'),
        'dose_rate' => $doses ? (int)round(100 * $taken / $doses) : null,
        'attention' => count(array_filter($ind, function ($x) { return $x['level'] === 'atencao'; })),
        'alert' => count(array_filter($ind, function ($x) { return $x['level'] === 'alerta'; })),
        'alert_days' => array_keys(array_filter($ind, function ($x) { return $x['level'] !== 'ok'; })),
        'sex' => null,
        'subst' => null,
    ];
    if ($withSubst && $prefs['modules']['substancias']) {
        $sb = array_values(array_filter($subAll, $in));
        $per = []; $any = []; $above = [];
        foreach ($sb as $r) {
            $per[SUBSTANCES[$r['substance']][0] ?? 'Outra'][$r['entry_date']] = true;
            $any[$r['entry_date']] = true;
            if ((int)$r['above_usual'] === 1) $above[$r['entry_date']] = true;
        }
        $d['subst'] = ['days' => count($any), 'above_days' => count($above), 'per' => array_map('count', $per)];
    }
    if ($withSex) {
        $sx = array_values(array_filter($sexAll, $in));
        $lib = array_filter(array_column($sx, 'libido'), function ($x) { return $x !== null; });
        $risk = count(array_filter($sx, function ($r) { foreach (explode(',', (string)$r['activities']) as $a) if ((SEX_ACTIVITIES[$a][1] ?? 0) > 0) return true; return false; }));
        $d['sex'] = ['days' => count($sx), 'libido_avg' => $lib ? round(array_sum($lib) / count($lib), 1) : null, 'risk_days' => $risk,
            'diff_days' => count(array_filter($sx, function ($r) { return (int)$r['different_from_usual'] === 1; }))];
    }
    return $d;
}

/** @param array<string,mixed> $m */
function markers_report_html(array $m): string {
    if (!$m['days'] && !$m['attention'] && !$m['alert'] && !($m['sex']['days'] ?? 0) && !($m['subst']['days'] ?? 0)) return '';
    $row = function (string $k, string $v): string { return '<tr><td style="padding:6px;border-bottom:1px solid #e3e8ec;color:#50606c">' . e($k) . '</td><td style="padding:6px;border-bottom:1px solid #e3e8ec"><strong>' . e($v) . '</strong></td></tr>'; };
    $n = function ($v, string $suf = '') { return $v === null ? '—' : fmt_num($v, 1) . $suf; };
    $h = '<h2 style="font-size:16px;margin:28px 0 8px">Marcadores diários e sinais de alerta</h2><table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px">';
    $h .= $row('Dias com marcadores', (string)$m['days']);
    $h .= $row('Indicador de (hipo)mania', plural_br((int)$m['alert'], 'dia', 'dias') . ' em alerta · ' . (int)$m['attention'] . ' em atenção');
    if ($m['alert_days']) $h .= $row('Dias sinalizados', implode(', ', array_map('fmt_date', array_slice($m['alert_days'], -10))));
    $h .= $row('Luz do sol (média)', $n($m['sun_avg'], ' min'));
    $h .= $row('Cafeína · Álcool (média/dia)', $n($m['caffeine_avg'], ' xíc.') . ' · ' . $n($m['alcohol_avg'], ' doses'));
    $h .= $row('Exercício (média)', $n($m['exercise_avg'], ' min'));
    $h .= $row('Dias com pensamentos / fala acelerados', $m['racing'] . ' / ' . $m['speech']);
    $h .= $row('Dias com gastos impulsivos · irritabilidade', $m['spending'] . ' · ' . $m['irrit']);
    $h .= $row('Dias com estresse', (string)$m['stress']);
    if ($m['sex'] !== null) {
        $s = $m['sex'];
        $h .= $row('Sexualidade — desejo médio (−2 a +2)', $s['libido_avg'] === null ? '—' : fmt_num($s['libido_avg'], 1, true));
        $h .= $row('Sexualidade — dias com sinais de risco / diferente do habitual', $s['risk_days'] . ' / ' . $s['diff_days']);
    }
    if ($m['subst'] !== null) {
        $s = $m['subst'];
        $per = []; foreach ($s['per'] as $k => $n) $per[] = $k . ': ' . $n;
        $h .= $row('Substâncias — dias com uso / acima do habitual', $s['days'] . ' / ' . $s['above_days']);
        if ($per) $h .= $row('Substâncias — dias por tipo', implode(' · ', $per));
    }
    return $h . '</table><p style="font-size:12px;color:#50606c">O indicador não é diagnóstico: combina sono abaixo do habitual, humor/energia elevados e outros sinais descritos para (hipo)mania.</p>';
}

/**
 * V4.2: alertas do app no período (os mesmos do Histórico: automáticos + personalizados), calculados na hora.
 * $allowedMask: privados que podem aparecer (1 sexualidade, 2 substâncias). Alertas privados fora da máscara não entram.
 * @return array{total:int, days:int, custom:int, list:list<array{date:string,title:string,severity:int}>}
 */
function report_alerts_data(int $uid, string $fromDate, string $toDate, int $allowedMask): array {
    $out = ['total' => 0, 'days' => 0, 'custom' => 0, 'list' => []];
    try {
        $st = db()->prepare('SELECT rule_code, severity, period_end, title, custom_rule_id, is_private FROM alerts WHERE user_id = ? AND period_end BETWEEN ? AND ? ORDER BY period_end, id');
        $st->execute([$uid, $fromDate, $toDate]);
        $rows = $st->fetchAll();
    } catch (PDOException $e) { return $out; } // migração V4.2 pendente
    $days = [];
    foreach ($rows as $r) {
        $mask = (int)$r['is_private'] | ($r['rule_code'] === 'substancias' ? 2 : 0);
        if (($mask & ~$allowedMask) !== 0) continue;
        $out['total']++;
        if ($r['custom_rule_id'] !== null) $out['custom']++;
        $days[(string)$r['period_end']] = true;
        $out['list'][] = ['date' => (string)$r['period_end'], 'title' => (string)$r['title'], 'severity' => (int)$r['severity']];
    }
    $out['days'] = count($days);
    return $out;
}

/** @param array{total:int, days:int, custom:int, list:list<array{date:string,title:string,severity:int}>} $a */
function report_alerts_html(array $a): string {
    $h = '<h2 style="font-size:16px;margin:28px 0 8px">Alertas do app no período</h2>';
    if (!$a['total']) return $h . '<p style="font-size:14px">Nenhum alerta neste período.</p>';
    $h .= '<p style="font-size:14px">' . e(plural_br($a['total'], 'alerta', 'alertas')) . ' em ' . e(plural_br($a['days'], 'dia', 'dias'))
        . ($a['custom'] ? ' (' . e(plural_br($a['custom'], 'personalizado', 'personalizados')) . ')' : '') . '.</p><ul style="padding-left:18px;font-size:13px">';
    foreach (array_slice($a['list'], -20) as $x) $h .= '<li>' . fmt_date($x['date']) . ' · ' . e($x['title']) . ' (' . e(alert_severity_label($x['severity'])) . ')</li>';
    return $h . '</ul>';
}
