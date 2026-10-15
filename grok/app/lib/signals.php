<?php
/**
 * Motor automático de sinais de alerta (V4). NÃO é diagnóstico: compara os dados recentes com a SUA linha de base
 * (mediana e MAD dos 28 dias anteriores) e com sinais descritos na literatura (ver docs/ALERTAS.md).
 * Funções puras: signals_daily() monta a série diária e signals_evaluate() devolve os alertas candidatos.
 */

const ALERT_RULES = [
    'mania' => ['(hipo)mania', 'Sinais de possível (hipo)mania'],
    'depressao' => ['depressão', 'Sinais de possível depressão'],
    'misto' => ['estado misto', 'Sinais de possível estado misto'],
    'ciclagem' => ['instabilidade', 'Humor muito instável / ciclagem rápida'],
    'lacuna' => ['registros', 'Vários dias sem registro'],
    'adesao' => ['medicação', 'Doses de medicação esquecidas'],
    'substancias' => ['substâncias', 'Uso de substâncias acima do seu habitual'],
    'pessoal' => ['sinais pessoais', 'Seus sinais de alerta pessoais apareceram'],
];
const ALERT_COOLDOWN_HOURS = 72;

function sig_dbr(string $d): string { return substr($d, 8, 2) . '/' . substr($d, 5, 2); }
function sig_h(float $h): string { return fmt_num($h, 1) . ' h'; }
function sig_add(string $d, int $n): string { return date('Y-m-d', (int)strtotime("$d " . ($n >= 0 ? '+' : '') . "$n days")); }

/**
 * Série diária com todas as fontes.
 * @param list<array<string,mixed>> $entries
 * @param list<array<string,mixed>> $markers
 * @param list<array<string,mixed>> $sex
 * @param list<array<string,mixed>> $subst
 * @param array<string,int> $mediaByDay
 * @return array<string, array<string,mixed>>
 */
function signals_daily(array $entries, array $markers, array $sex = [], array $subst = [], array $mediaByDay = []): array
{
    $D = [];
    $blank = ['n' => 0, 'moods' => [], 'mood_mean' => null, 'mood_min' => null, 'mood_max' => null, 'range' => null, 'sleep' => null,
        'energy' => null, 'irrit' => null, 'racing' => null, 'speech' => null, 'spending' => null, 'spend_amount' => null,
        'caffeine' => null, 'alcohol' => null, 'sun' => null, 'stress' => null, 'cycle' => null, 'signs' => 0, 'missed' => 0, 'doses' => 0,
        'libido' => null, 'sex_risk' => 0, 'stim' => 0, 'cannabis' => null, 'subst_above' => 0, 'media' => 0, 'bed' => null];
    foreach ($entries as $e) {
        $d = (string)$e['entry_date'];
        $D[$d] = $D[$d] ?? $blank;
        $D[$d]['n']++;
        $D[$d]['moods'][(string)($e['period'] ?? $D[$d]['n'])] = (int)$e['mood'];
        if (isset($e['sleep_hours'])) $D[$d]['sleep'] = max((float)($D[$d]['sleep'] ?? 0), (float)$e['sleep_hours']);
        if (isset($e['energy'])) $D[$d]['energy'] = max($D[$d]['energy'] ?? 0, (int)$e['energy']);
        if (isset($e['irritability'])) $D[$d]['irrit'] = max($D[$d]['irrit'] ?? 0, (int)$e['irritability']);
        if (array_key_exists('medication_taken', $e) && $e['medication_taken'] !== null) { $D[$d]['doses']++; if ((int)$e['medication_taken'] === 0) $D[$d]['missed']++; }
    }
    foreach ($markers as $m) {
        $d = (string)$m['entry_date'];
        $D[$d] = $D[$d] ?? $blank;
        $h = sleep_hours_from_times($m['bed_time'] ?? null, $m['wake_time'] ?? null);
        if ($h !== null) $D[$d]['sleep'] = $h;
        if (!empty($m['bed_time'])) { $b = (int)substr((string)$m['bed_time'], 0, 2) * 60 + (int)substr((string)$m['bed_time'], 3, 2); $D[$d]['bed'] = $b < 720 ? $b + 1440 : $b; }
        foreach (['racing_thoughts' => 'racing', 'fast_speech' => 'speech', 'impulsive_spending' => 'spending', 'caffeine_cups' => 'caffeine', 'alcohol_units' => 'alcohol', 'sun_minutes' => 'sun', 'stress_level' => 'stress'] as $c => $k) {
            if (isset($m[$c])) $D[$d][$k] = (int)$m[$c];
        }
        if (isset($m['spending_amount'])) $D[$d]['spend_amount'] = (float)$m['spending_amount'];
        if (isset($m['irritability_day'])) $D[$d]['irrit'] = max($D[$d]['irrit'] ?? 0, (int)$m['irritability_day']);
        $D[$d]['cycle'] = $m['cycle_phase'] ?? null;
        $s = json_decode((string)($m['signs_json'] ?? ''), true);
        $D[$d]['signs'] = is_array($s) ? count($s) : 0;
        $doses = json_decode((string)($m['doses_json'] ?? ''), true);
        if (is_array($doses) && $doses) { $D[$d]['doses'] = count($doses); $D[$d]['missed'] = count(array_filter($doses, function ($v): bool { return (int)$v === 0; })); }
    }
    foreach ($sex as $s) {
        $d = (string)$s['entry_date'];
        $D[$d] = $D[$d] ?? $blank;
        $D[$d]['libido'] = $s['libido'] === null ? null : (int)$s['libido'];
        $risk = 0;
        foreach (explode(',', (string)($s['activities'] ?? '')) as $a) $risk += SEX_ACTIVITIES[$a][1] ?? 0;
        $D[$d]['sex_risk'] = $risk;
    }
    foreach ($subst as $u) {
        $d = (string)$u['entry_date'];
        $D[$d] = $D[$d] ?? $blank;
        $cls = SUBSTANCES[$u['substance']][3] ?? 'outra';
        if ($cls === 'estimulante') $D[$d]['stim']++;
        if ($cls === 'cannabis') $D[$d]['cannabis'] = ($D[$d]['cannabis'] ?? 0) + (float)($u['amount'] ?? 1);
        if ($u['substance'] === 'alcool' && $D[$d]['alcohol'] === null) $D[$d]['alcohol'] = (int)round((float)($u['amount'] ?? 1));
        if ((int)($u['above_usual'] ?? 0) === 1) $D[$d]['subst_above']++;
    }
    foreach ($mediaByDay as $d => $n) { $D[$d] = $D[$d] ?? $blank; $D[$d]['media'] = (int)$n; }
    foreach ($D as $d => &$x) {
        if ($x['moods']) {
            $x['mood_mean'] = array_sum($x['moods']) / count($x['moods']);
            $x['mood_min'] = min($x['moods']);
            $x['mood_max'] = max($x['moods']);
            $x['range'] = $x['mood_max'] - $x['mood_min'];
        }
    }
    unset($x);
    ksort($D);
    return $D;
}

/**
 * Linha de base robusta: mediana e MAD (×1,4826) dos 28 dias antes de $day. Null com menos de $min valores.
 * @param array<string, array<string,mixed>> $D
 * @return array{med:float,mad:float,n:int}|null
 */
function signals_baseline(array $D, string $key, string $day, int $min = 7, int $span = 28): ?array
{
    $from = sig_add($day, -$span);
    $v = [];
    foreach ($D as $d => $x) if ($d >= $from && $d < $day && $x[$key] !== null) $v[] = (float)$x[$key];
    if (count($v) < $min) return null;
    $med = signals_median($v);
    $dev = array_map(function (float $a) use ($med): float { return abs($a - $med); }, $v);
    return ['med' => $med, 'mad' => 1.4826 * signals_median($dev), 'n' => count($v)];
}
/** @param list<float> $v */
function signals_median(array $v): float
{
    sort($v);
    $n = count($v);
    if ($n === 0) return 0.0;
    return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}
/**
 * z robusto com piso de escala (evita z enorme quando a pessoa é muito regular).
 * @param array{med:float,mad:float,n:int}|null $b
 */
function signals_z(?float $x, ?array $b, float $floor): ?float
{
    if ($x === null || $b === null) return null;
    return ($x - $b['med']) / max($b['mad'], $floor);
}
/**
 * MSSD (mean squared successive difference) de uma série.
 * @param list<int|float> $s
 */
function signals_mssd(array $s): ?float
{
    if (count($s) < 3) return null;
    $t = 0.0;
    for ($i = 1, $n = count($s); $i < $n; $i++) $t += ($s[$i] - $s[$i - 1]) ** 2;
    return $t / (count($s) - 1);
}

/**
 * Avalia todas as regras para o dia $today.
 * @param array<string, array<string,mixed>> $D série de signals_daily
 * @param array<string,int> $tune ajuste por regra (-2 = bem menos sensível … +1 = mais sensível)
 * @return list<array{rule:string,severity:int,period_start:string,period_end:string,title:string,explanation:string,evidence:list<string>,crisis:bool}>
 */
function signals_evaluate(array $D, string $today, array $tune = []): array
{
    $out = [];
    $day = function (string $d) use ($D): array { return $D[$d] ?? []; };
    $last = function (int $n) use ($today): array { $r = []; for ($i = $n - 1; $i >= 0; $i--) $r[] = sig_add($today, -$i); return $r; };
    $t = function (string $r) use ($tune): int { return max(-2, min(1, (int)($tune[$r] ?? 0))); };
    $W3 = $last(3); $W7 = $last(7); $W14 = $last(14);

    // ---- sono vs linha de base (usado por várias regras)
    $sleepEv = []; $sleepDown = false; $sleepUp = false;
    foreach ($W3 as $d) {
        $s = $day($d)['sleep'] ?? null;
        if ($s === null) continue;
        $b = signals_baseline($D, 'sleep', $d);
        $base = $b['med'] ?? 7.0;
        $z = signals_z((float)$s, $b, 0.5);
        if (($z !== null && $z <= -1.5 && $s <= $base - 1.5) || ($b === null && $s <= 5)) { $sleepDown = true; $sleepEv[] = 'dormiu ' . sig_h((float)$s) . ' em ' . sig_dbr($d) . ' (seu habitual: ' . sig_h($base) . ')'; }
        if ($z !== null && $z >= 2 && $s >= $base + 2) { $sleepUp = true; $sleepEv[] = 'dormiu ' . sig_h((float)$s) . ' em ' . sig_dbr($d) . ', bem acima do habitual (' . sig_h($base) . ')'; }
    }
    $ctx = [];
    foreach ($W7 as $d) {
        if (($day($d)['stress'] ?? 0) >= 3) $ctx['estresse'] = 'estresse intenso/acontecimento marcante em ' . sig_dbr($d) . ' (eventos de vida podem desencadear episódios)';
        if (($day($d)['cycle'] ?? null) === 'pre_menstrual' || ($day($d)['cycle'] ?? null) === 'menstruacao') $ctx['ciclo'] = 'fase pré-menstrual/menstrual registrada (algumas pessoas percebem piora nessa fase)';
    }

    // ---- MANIA / HIPOMANIA
    $pts = 0; $ev = []; $moodUp = false; $maxMood = -9;
    foreach ($W3 as $d) $maxMood = max($maxMood, (int)($day($d)['mood_max'] ?? -9));
    $upDays = count(array_filter($W3, function (string $d) use ($day): bool { return ($day($d)['mood_mean'] ?? -9) >= 1; }));
    if ($maxMood >= 2 || $upDays >= 2) { $moodUp = true; $pts += 2; $ev[] = $maxMood >= 2 ? 'humor bem elevado (até +' . $maxMood . ')' : "humor elevado em $upDays dos últimos 3 dias"; }
    if ($sleepDown) { $pts += 2; $ev = array_merge($ev, array_filter($sleepEv, function (string $s): bool { return strpos($s, 'acima') === false; })); }
    $flag = function (string $k, int $min, string $txt) use ($W3, $day, &$pts, &$ev): void {
        foreach ($W3 as $d) if (($day($d)[$k] ?? 0) >= $min) { $pts++; $ev[] = $txt . ' (' . sig_dbr($d) . ')'; return; }
    };
    $flag('energy', 3, 'energia intensa');
    $flag('racing', 2, 'pensamentos acelerados');
    $flag('speech', 2, 'fala acelerada');
    $flag('spending', 2, 'gastos impulsivos');
    $flag('irrit', 2, 'irritabilidade');
    $flag('stim', 1, 'uso de estimulante');
    foreach ($W3 as $d) {
        $x = $day($d);
        if (($x['libido'] ?? 0) >= 1 || ($x['sex_risk'] ?? 0) >= 1) { $pts++; $ev[] = 'desejo/comportamento sexual acima do habitual (' . sig_dbr($d) . ')'; break; }
    }
    foreach (['caffeine' => ['cafeína bem acima do habitual', 1.0], 'media' => ['muitas notas em áudio/vídeo (mais que o habitual)', 1.0], 'spend_amount' => ['gasto do dia bem acima do habitual', 50.0], 'cannabis' => ['cannabis acima do habitual', 1.0]] as $k => [$txt, $floor]) {
        foreach ($W3 as $d) {
            $z = signals_z(isset($day($d)[$k]) ? (float)$day($d)[$k] : null, signals_baseline($D, $k, $d, 5), $floor);
            if ($z !== null && $z >= 2) { $pts++; $ev[] = $txt . ' (' . sig_dbr($d) . ')'; break; }
        }
    }
    $bedLate = null;
    foreach ($W3 as $d) { $z = signals_z(isset($day($d)['bed']) ? (float)$day($d)['bed'] : null, signals_baseline($D, 'bed', $d, 5), 30); if ($z !== null && $z >= 2) $bedLate = $d; }
    if ($bedLate) { $pts++; $ev[] = 'foi deitar bem mais tarde que o habitual (' . sig_dbr($bedLate) . ')'; }
    $signsMax = 0; foreach ($W3 as $d) $signsMax = max($signsMax, (int)($day($d)['signs'] ?? 0));
    if ($signsMax >= 1) { $pts++; $ev[] = ($signsMax === 1 ? '1 sinal pessoal marcado' : "$signsMax sinais pessoais marcados"); }
    $need = 4 - $t('mania');
    if (($moodUp || $sleepDown) && $pts >= $need) {
        $sev = ($pts >= $need + 3 || $maxMood >= 3) ? 3 : ($pts >= $need + 1 ? 2 : 1);
        $out[] = signals_make('mania', $sev, $W3[0], $today, $ev, $ctx, false);
    }

    // ---- DEPRESSÃO
    $logged = array_values(array_filter($W7, function (string $d) use ($day): bool { return ($day($d)['mood_mean'] ?? null) !== null; }));
    $low = array_filter($logged, function (string $d) use ($day): bool { return $day($d)['mood_mean'] <= -1; });
    $veryLow = array_filter($logged, function (string $d) use ($day): bool { return $day($d)['mood_min'] <= -2; });
    $minMood = 9; foreach ($W3 as $d) $minMood = min($minMood, (int)($day($d)['mood_min'] ?? 9));
    $needD = 4 - $t('depressao');
    $recentVeryLow = count(array_filter($W3, function (string $d) use ($day): bool { return ($day($d)['mood_min'] ?? 9) <= -2; }));
    if (count($low) >= $needD || $recentVeryLow >= 2 || $minMood <= -3) {
        $ev = [];
        if ($low) $ev[] = 'humor deprimido em ' . count($low) . ' dos últimos 7 dias';
        if ($veryLow) $ev[] = 'humor bem baixo (−2 ou menos) em ' . plural_br(count($veryLow), 'dia', 'dias');
        if ($minMood <= -3) $ev[] = 'registro de depressão grave (−3) nos últimos 3 dias';
        foreach ($sleepEv as $s) $ev[] = $s;
        $lowE = count(array_filter($W7, function (string $d) use ($day): bool { return isset($day($d)['energy']) && $day($d)['energy'] === 0; }));
        if ($lowE >= 2) $ev[] = "energia nula em $lowE dias";
        $sunB = signals_baseline($D, 'sun', $today, 5);
        $sunVals = array_filter(array_map(function (string $d) use ($day) { return $day($d)['sun'] ?? null; }, $W7), function ($v): bool { return $v !== null; });
        if ($sunB && $sunVals && signals_median(array_map('floatval', array_values($sunVals))) < $sunB['med'] * 0.5) $ev[] = 'bem menos exposição ao sol que o habitual';
        $sev = $minMood <= -3 ? 3 : (count($veryLow) >= 2 || count($low) >= 6 ? 2 : 1);
        $out[] = signals_make('depressao', $sev, $W7[0], $today, $ev, $ctx, $minMood <= -3);
    }

    // ---- ESTADO MISTO
    $mixedDays = []; $swingIrr = null;
    foreach ($W3 as $d) {
        $x = $day($d);
        if (($x['mood_min'] ?? 9) > -1) continue;
        $act = [];
        if (($x['racing'] ?? 0) >= 2) $act[] = 'pensamentos acelerados';
        if (($x['speech'] ?? 0) >= 2) $act[] = 'fala acelerada';
        if (($x['irrit'] ?? 0) >= 2) $act[] = 'irritabilidade';
        if (($x['energy'] ?? 0) >= 2) $act[] = 'muita energia/agitação';
        if (($x['mood_max'] ?? -9) >= 1) $act[] = 'humor elevado no mesmo dia';
        if ($act) $mixedDays[$d] = $act;
        if (($x['range'] ?? 0) >= 3 && ($x['irrit'] ?? 0) >= 2) $swingIrr = $d;
    }
    if (count($mixedDays) >= max(1, 2 - $t('misto')) || $swingIrr) {
        $ev = [];
        foreach ($mixedDays as $d => $a) $ev[] = 'humor deprimido junto com ' . implode(', ', $a) . ' (' . sig_dbr($d) . ')';
        if ($swingIrr) $ev[] = 'variação de humor de ' . $day($swingIrr)['range'] . ' pontos no mesmo dia, com irritabilidade (' . sig_dbr($swingIrr) . ')';
        if ($sleepDown) $ev[] = 'sono reduzido';
        $out[] = signals_make('misto', $minMood <= -3 ? 3 : 2, $W3[0], $today, $ev, $ctx, $minMood <= -3);
    }

    // ---- CICLAGEM RÁPIDA / INSTABILIDADE
    $pol = []; $series = []; $bigRange = 0;
    foreach ($W14 as $d) {
        $x = $day($d);
        if (($x['mood_mean'] ?? null) === null) continue;
        $p = $x['mood_mean'] >= 1 ? 1 : ($x['mood_mean'] <= -1 ? -1 : 0);
        if ($p !== 0) $pol[] = $p;
        foreach ($x['moods'] as $mv) $series[] = $mv;
        if ($x['range'] >= 3) $bigRange++;
    }
    $switches = 0; for ($i = 1; $i < count($pol); $i++) if ($pol[$i] !== $pol[$i - 1]) $switches++;
    $prevSeries = [];
    foreach ($D as $d => $x) if ($d >= sig_add($today, -42) && $d < $W14[0]) foreach ($x['moods'] as $mv) $prevSeries[] = $mv;
    $mssd = signals_mssd($series); $mssdBase = signals_mssd($prevSeries);
    $crit = []; $needC = 1;
    if ($switches >= 4 - $t('ciclagem')) $crit[] = "$switches viradas entre humor elevado e deprimido em 14 dias";
    if ($mssd !== null && $mssd >= 1.5 && $mssdBase !== null && count($prevSeries) >= 10 && $mssd >= 2 * max($mssdBase, 0.25)) $crit[] = 'variabilidade do humor (MSSD ' . fmt_num($mssd, 1) . ') mais que o dobro da sua habitual (' . fmt_num($mssdBase, 1) . ')';
    if ($bigRange >= 3 - min(0, $t('ciclagem'))) $crit[] = "$bigRange dias com variação de 3 pontos ou mais no mesmo dia";
    if (count($crit) >= $needC) {
        $out[] = signals_make('ciclagem', count($crit) >= 2 ? 2 : 1, $W14[0], $today, $crit, $ctx, false);
    }

    // ---- LACUNAS (dias sem registro depois de um período regular)
    $gap = 0; for ($i = 1; $i <= 14; $i++) { if (!empty($day(sig_add($today, -$i))['n'])) break; $gap++; }
    if ($gap >= 3 - min(0, $t('lacuna')) && empty($day($today)['n'])) {
        $from = sig_add($today, -$gap - 28); $to = sig_add($today, -$gap - 1); $reg = 0;
        foreach ($D as $d => $x) if ($d >= $from && $d <= $to && $x['n'] > 0) $reg++;
        if ($reg >= 20) $out[] = signals_make('lacuna', $gap >= 7 ? 2 : 1, sig_add($today, -$gap), $today, ["$gap dias seguidos sem registro de humor, depois de $reg de 28 dias registrados antes"], [], false);
    }

    // ---- ADESÃO À MEDICAÇÃO
    $missed = 0; $missDays = [];
    foreach ($W7 as $d) { $m = (int)($day($d)['missed'] ?? 0); if ($m > 0) { $missed += $m; $missDays[] = sig_dbr($d); } }
    if ($missed >= 2 - min(0, $t('adesao'))) {
        $out[] = signals_make('adesao', $missed >= 4 ? 2 : 1, $W7[0], $today, ["$missed doses marcadas como não tomadas em " . implode(', ', $missDays)], [], false);
    }

    // ---- SUBSTÂNCIAS
    $ev = []; $sev = 1;
    $above = 0; foreach ($W7 as $d) $above += (int)($day($d)['subst_above'] ?? 0);
    if ($above >= 2) $ev[] = plural_br($above, 'registro', 'registros') . ' de uso acima do habitual na semana';
    foreach ($W7 as $d) if (($day($d)['stim'] ?? 0) > 0) { $ev[] = 'uso de estimulante em ' . sig_dbr($d); if ($sleepDown) $sev = 2; }
    foreach ($W7 as $d) if (($day($d)['alcohol'] ?? 0) >= 5) $ev[] = 'beber pesado (' . $day($d)['alcohol'] . ' doses) em ' . sig_dbr($d);
    foreach ($W3 as $d) {
        $z = signals_z(isset($day($d)['cannabis']) ? (float)$day($d)['cannabis'] : null, signals_baseline($D, 'cannabis', $d, 5), 1);
        if ($z !== null && $z >= 2) { $ev[] = 'cannabis bem acima do seu habitual em ' . sig_dbr($d); if ($sleepDown) $sev = 2; }
    }
    if (count($ev) >= max(1, 1 - $t('substancias'))) $out[] = signals_make('substancias', $sev, $W7[0], $today, $ev, [], false);

    // ---- SINAIS PESSOAIS
    $ps = max((int)($day($today)['signs'] ?? 0), (int)($day(sig_add($today, -1))['signs'] ?? 0));
    if ($ps >= 2 - $t('pessoal')) $out[] = signals_make('pessoal', $ps >= 3 ? 2 : 1, sig_add($today, -1), $today, ["$ps dos seus sinais de alerta pessoais marcados"], [], false);

    return $out;
}

/**
 * @param list<string> $ev
 * @param array<string,string> $ctx
 * @return array{rule:string,severity:int,period_start:string,period_end:string,title:string,explanation:string,evidence:list<string>,crisis:bool}
 */
function signals_make(string $rule, int $sev, string $from, string $to, array $ev, array $ctx, bool $crisis): array
{
    $ev = array_values(array_unique($ev));
    $intro = [
        'mania' => 'Alguns sinais que em muitas pessoas antecedem a hipomania ou a mania apareceram juntos',
        'depressao' => 'Seu humor tem ficado baixo de forma persistente',
        'misto' => 'Apareceram ao mesmo tempo humor para baixo e sinais de aceleração — combinação que merece atenção',
        'ciclagem' => 'Seu humor tem oscilado mais do que o seu normal',
        'lacuna' => 'Você parou de registrar por alguns dias (às vezes isso acontece quando o humor muda)',
        'adesao' => 'Algumas doses de medicação ficaram sem tomar',
        'substancias' => 'O uso de substâncias ficou acima do seu habitual',
        'pessoal' => 'Sinais que você mesmo definiu como alerta foram marcados',
    ][$rule] ?? '';
    $txt = $intro . ': ' . implode('; ', array_slice($ev, 0, 8)) . '.';
    if ($ctx) $txt .= ' Contexto: ' . implode('; ', $ctx) . '.';
    $txt .= ' Isto não é diagnóstico — é um convite para se cuidar e, se fizer sentido, falar com sua equipe.';
    if ($crisis) $txt .= ' Se pensar em se machucar, ligue agora para o CVV 188 (24h) ou SAMU 192.';
    return ['rule' => $rule, 'severity' => max(1, min(3, $sev)), 'period_start' => $from, 'period_end' => $to,
        'title' => ALERT_RULES[$rule][1] ?? $rule, 'explanation' => $txt, 'evidence' => array_merge($ev, array_values($ctx)), 'crisis' => $crisis];
}

/** Rótulo do nível. */
function alert_severity_label(int $s): string { return [1 => 'Atenção', 2 => 'Alerta', 3 => 'Alerta forte'][$s] ?? 'Atenção'; }

/**
 * Deduplicação/cooldown: grava só se não houver alerta da mesma regra nas últimas 72 h com severidade ≥ a nova.
 * @param array{rule:string,severity:int,period_start:string,period_end:string,title:string,explanation:string,evidence:list<string>,crisis:bool} $a
 */
function alert_store(PDO $pdo, int $uid, array $a, bool $pending = false): ?int
{
    $st = $pdo->prepare('SELECT MAX(severity) FROM alerts WHERE user_id = ? AND rule_code = ? AND created_at > UTC_TIMESTAMP() - INTERVAL ' . ALERT_COOLDOWN_HOURS . ' HOUR');
    $st->execute([$uid, $a['rule']]);
    $prev = $st->fetchColumn();
    if ($prev !== null && $prev !== false && (int)$prev >= $a['severity']) return null;
    // V4.2: gravado fora do cron (Histórico, relatório, logo após salvar) fica "pendente" e o cron entrega push/e-mail depois.
    $pdo->prepare('INSERT INTO alerts (user_id, rule_code, kind, severity, period_start, period_end, title, explanation, evidence_json, is_private, pending_delivery) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$uid, $a['rule'], ALERT_RULES[$a['rule']][0] ?? $a['rule'], $a['severity'], $a['period_start'], $a['period_end'], $a['title'], $a['explanation'], json_encode($a['evidence'], JSON_UNESCAPED_UNICODE), $a['rule'] === 'substancias' ? 2 : 0, $pending ? 1 : 0]);
    return (int)$pdo->lastInsertId();
}

/**
 * Lê todos os dados do usuário (últimos 60 dias) e avalia. Módulos privados só entram se estiverem ligados.
 * @param array<string,mixed> $u
 * @return list<array{rule:string,severity:int,period_start:string,period_end:string,title:string,explanation:string,evidence:list<string>,crisis:bool}>
 */
function signals_for_user(PDO $pdo, array $u, ?string $today = null): array
{
    $today = $today ?? (new DateTime('now', new DateTimeZone((string)$u['timezone'])))->format('Y-m-d');
    $from = sig_add($today, -60);
    $prefs = user_prefs($u);
    $q = function (string $sql) use ($pdo, $u, $from, $today): array { $st = $pdo->prepare($sql); $st->execute([$u['id'], $from, $today]); return $st->fetchAll(); };
    $entries = $q('SELECT entry_date, period, mood, sleep_hours, energy, irritability, medication_taken FROM entries WHERE user_id = ? AND entry_date BETWEEN ? AND ?');
    $markers = $q('SELECT * FROM daily_markers WHERE user_id = ? AND entry_date BETWEEN ? AND ?');
    $sex = $prefs['modules']['sexualidade'] ? $q('SELECT entry_date, libido, activities, different_from_usual FROM sexuality_log WHERE user_id = ? AND entry_date BETWEEN ? AND ?') : [];
    $sub = $prefs['modules']['substancias'] ? $q('SELECT entry_date, substance, amount, above_usual FROM substance_log WHERE user_id = ? AND entry_date BETWEEN ? AND ?') : [];
    $media = [];
    foreach ($q('SELECT DATE(created_at) d, COUNT(*) n FROM media WHERE user_id = ? AND created_at BETWEEN ? AND ? + INTERVAL 1 DAY GROUP BY DATE(created_at)') as $r) $media[(string)$r['d']] = (int)$r['n'];
    $out = signals_evaluate(signals_daily($entries, $markers, $sex, $sub, $media), $today, alert_tune($u));
    return signals_merge_indicator($out, mania_indicators($entries, $markers, $sex, count($prefs['signs']), $sub), $today);
}

/**
 * V4.1: o indicador diário de (hipo)mania (Meu dia / Histórico) e os alertas falam a mesma língua. Se o indicador
 * mostra "Alerta" (ou o alerta precoce aparece) nos últimos 3 dias e o motor não gerou alerta de (hipo)mania,
 * cria um com os mesmos motivos — assim o Histórico nunca diz "Nenhum alerta" ao lado de um indicador em Alerta.
 * @param list<array{rule:string,severity:int,period_start:string,period_end:string,title:string,explanation:string,evidence:list<string>,crisis:bool}> $out
 * @param array<string, array{score:int, level:string, reasons:list<string>, sleep:?float, triad:bool}> $ind
 * @return list<array{rule:string,severity:int,period_start:string,period_end:string,title:string,explanation:string,evidence:list<string>,crisis:bool}>
 */
function signals_merge_indicator(array $out, array $ind, string $today): array
{
    foreach ($out as $a) if ($a['rule'] === 'mania') return $out;
    if (early_warning($ind, $today) === null) return $out;
    $from = sig_add($today, -2);
    $ev = []; $sev = 1; $first = $today;
    foreach ($ind as $d => $x) {
        if ($d < $from || $d > $today || $x['level'] === 'ok') continue;
        if ($x['level'] === 'alerta' || $x['triad']) $sev = 2;
        $first = min($first, $d);
        foreach ($x['reasons'] as $r) $ev[] = $r . ' (' . sig_dbr($d) . ')';
    }
    if (!$ev) return $out;
    $out[] = signals_make('mania', $sev, $first, $today, $ev, [], false);
    return $out;
}

/**
 * @param array<string,mixed> $u
 * @return array<string,int>
 */
function alert_tune(array $u): array
{
    $p = json_decode((string)($u['prefs'] ?? ''), true);
    $t = is_array($p) && is_array($p['alert_tune'] ?? null) ? $p['alert_tune'] : [];
    $o = [];
    foreach (ALERT_RULES as $k => $_) $o[$k] = max(-2, min(1, (int)($t[$k] ?? 0)));
    return $o;
}
/** Feedback "faz sentido" (+1, até +1) ou "não faz sentido" (−1, até −2) ajusta a sensibilidade da regra. */
function alert_feedback_tune(int $current, bool $makesSense): int
{
    return $makesSense ? min(1, $current + 1) : max(-2, $current - 1);
}

/**
 * Preferências de alerta.
 * @param array<string,mixed> $u
 * @return array{push:bool,email:bool,engine:bool}
 */
function alert_prefs(array $u): array
{
    $p = json_decode((string)($u['prefs'] ?? ''), true);
    $a = is_array($p) && is_array($p['alerts'] ?? null) ? $p['alerts'] : [];
    return ['engine' => !isset($a['engine']) || !empty($a['engine']), 'push' => !isset($a['push']) || !empty($a['push']), 'email' => !empty($a['email'])];
}

