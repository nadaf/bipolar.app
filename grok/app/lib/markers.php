<?php
/**
 * Marcadores diários, módulo Sexualidade (privado) e indicador de sinais de (hipo)mania.
 * Funções puras sempre que possível (testáveis sem banco).
 */

/** Módulos que a pessoa pode ligar/desligar em Conta. [rótulo, ligado por padrão] */
const DAY_MODULES = [
    'sono' => ['Sono (horário de deitar e acordar)', true],
    'sol' => ['Sol e luz do dia', true],
    'habitos' => ['Cafeína, álcool/substâncias e exercício', true],
    'mente' => ['Pensamentos e fala acelerados, gastos impulsivos, irritabilidade', true],
    'estresse' => ['Acontecimentos e estresse do dia', true],
    'medicacao' => ['Medicação por dose', false],
    'sinais' => ['Meus sinais de alerta pessoais', false],
    'ciclo' => ['Ciclo menstrual', false],
    'sexualidade' => ['Sexualidade (privado)', false],
    'substancias' => ['Substâncias (privado, redução de danos)', false],
];

/**
 * Substâncias: código => [rótulo, unidade da quantidade, formas, classe].
 * Classes: estimulante/psicodelico somam sinal de (hipo)mania; cannabis soma quando acima do habitual com sono reduzido.
 */
const SUBSTANCES = [
    'cannabis' => ['Cannabis/maconha', 'baseados/pegas', ['fumada' => 'Fumada', 'vaporizada' => 'Vaporizada', 'comestivel' => 'Comestível', 'oleo_cbd' => 'Óleo/CBD'], 'cannabis'],
    'alcool' => ['Álcool', 'doses', [], 'depressor'],
    'tabaco' => ['Tabaco/nicotina', 'cigarros/sessões', ['cigarro' => 'Cigarro', 'vape_pod' => 'Vape/pod'], 'nicotina'],
    'cafe' => ['Café/cafeína', 'xícaras/doses', ['coado' => 'Café coado', 'expresso' => 'Expresso', 'energetico' => 'Energético', 'cha_mate' => 'Chá, mate ou chimarrão', 'capsula' => 'Pré-treino/cafeína em cápsula'], 'cafeina'],
    'cocaina' => ['Cocaína', 'usos/carreiras', [], 'estimulante'],
    'crack' => ['Crack', 'usos/pedras', [], 'estimulante'],
    'mdma' => ['Ecstasy/MDMA', 'comprimidos/doses', [], 'estimulante'],
    'anfetaminas' => ['Anfetaminas/estimulantes (incl. sem prescrição)', 'comprimidos/doses', [], 'estimulante'],
    'psicodelicos' => ['LSD/cogumelos/psicodélicos', 'doses', [], 'psicodelico'],
    'ketamina' => ['Ketamina', 'doses', [], 'psicodelico'],
    'benzo' => ['Benzodiazepínicos sem prescrição', 'comprimidos', [], 'depressor'],
    'opioides' => ['Opioides sem prescrição', 'doses', [], 'depressor'],
    'inalantes' => ['Inalantes/lança-perfume', 'usos', [], 'outra'],
    'outra' => ['Outra', 'usos', [], 'outra'],
];
const SUBST_REASONS = ['relaxar' => 'Relaxar', 'dormir' => 'Dormir', 'social' => 'Social', 'ansiedade' => 'Ansiedade', 'habito' => 'Hábito', 'outro' => 'Outro'];

/** Atividades do módulo Sexualidade: código => [rótulo, peso no indicador de mania] */
const SEX_ACTIVITIES = [
    'nenhuma' => ['Sem atividade sexual', 0],
    'masturbacao' => ['Masturbação', 0],
    'parceria_fixa' => ['Sexo com parceria fixa (convencional)', 0],
    'exploracao' => ['Sexo não convencional / exploração (fetiches, novas práticas)', 0],
    'nova_parceria' => ['Parceria nova', 1],
    'multiplas' => ['Múltiplas parcerias no período', 1],
    'sem_protecao' => ['Sexo sem proteção', 1],
    'online' => ['Conteúdo/sexo online (pornografia, chats, apps de encontro)', 0],
    'pensamentos' => ['Pensamentos sexuais intrusivos/frequentes', 1],
    'arrependimento' => ['Arrependimento depois', 1],
];

const CYCLE_PHASES = ['menstruacao' => 'Menstruação', 'folicular' => 'Pós-menstruação', 'ovulacao' => 'Ovulação', 'pre_menstrual' => 'Pré-menstrual (TPM)', 'nao_sei' => 'Não sei'];
const SUN_PERIODS = ['manha' => 'Manhã', 'meio_dia' => 'Meio do dia', 'tarde' => 'Tarde', 'varios' => 'Vários momentos'];

/**
 * @param array<string,mixed> $u
 * @return array<string,mixed>
 */
function user_prefs(array $u): array
{
    $p = json_decode((string)($u['prefs'] ?? ''), true);
    $p = is_array($p) ? $p : [];
    $mods = [];
    foreach (DAY_MODULES as $k => [, $def]) $mods[$k] = isset($p['modules'][$k]) ? (bool)$p['modules'][$k] : $def;
    return [
        'modules' => $mods,
        'sex_blur' => isset($p['sex_blur']) ? (bool)$p['sex_blur'] : true,
        'subst_blur' => isset($p['subst_blur']) ? (bool)$p['subst_blur'] : true,
        'subst_favorites' => normalize_subst_favorites((array)($p['subst_favorites'] ?? [])),
        'signs' => array_values(array_filter(array_map('strval', (array)($p['signs'] ?? [])), function (string $s): bool { return $s !== ''; })),
        'action_plan' => (string)($p['action_plan'] ?? ''),
        'meds' => array_values(array_filter((array)($p['meds'] ?? []), 'is_array')),
    ];
}

/**
 * Normaliza preferências vindas da tela Conta.
 * @param array<string,mixed> $in
 * @return array<string,mixed>
 */
function normalize_prefs(array $in): array
{
    $mods = [];
    foreach (array_keys(DAY_MODULES) as $k) $mods[$k] = !empty($in['modules'][$k]);
    $signs = [];
    foreach ((array)($in['signs'] ?? []) as $s) {
        $s = trim(mb_substr(is_scalar($s) ? (string)$s : '', 0, 120));
        if ($s !== '' && count($signs) < 10) $signs[] = $s;
    }
    $meds = []; $i = 0;
    foreach ((array)($in['meds'] ?? []) as $m) {
        if (!is_array($m)) continue;
        $name = trim(mb_substr((string)($m['name'] ?? ''), 0, 80));
        $time = (string)($m['time'] ?? '');
        if ($name === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) || count($meds) >= 12) continue;
        $id = preg_match('/^[a-z0-9]{1,12}$/', (string)($m['id'] ?? '')) ? (string)$m['id'] : 'm' . (++$i) . substr(bin2hex(random_bytes(3)), 0, 4);
        $meds[] = ['id' => $id, 'name' => $name, 'dose' => trim(mb_substr((string)($m['dose'] ?? ''), 0, 40)), 'time' => $time];
    }
    usort($meds, function (array $a, array $b): int { return strcmp($a['time'], $b['time']); });
    return [
        'modules' => $mods,
        'sex_blur' => !isset($in['sex_blur']) || !empty($in['sex_blur']),
        'subst_blur' => !isset($in['subst_blur']) || !empty($in['subst_blur']),
        'subst_favorites' => normalize_subst_favorites((array)($in['subst_favorites'] ?? [])),
        'signs' => $signs,
        'action_plan' => trim(mb_substr((string)($in['action_plan'] ?? ''), 0, 1000)),
        'meds' => $meds,
    ];
}

/** @param mixed $v */
function hhmm_or_null($v): ?string
{
    return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;
}

/**
 * Valida marcadores do dia. Só os campos ENVIADOS entram no resultado (edição sem perda).
 * @param array<string,mixed> $in
 * @return array{0:?string,1:array<string,mixed>}
 */
function validate_day(array $in): array
{
    if (!valid_iso_date($in['entry_date'] ?? null)) return ['Informe uma data válida.', []];
    $v = [];
    $map = [
        'bed_time' => fn ($x) => hhmm_or_null($x), 'wake_time' => fn ($x) => hhmm_or_null($x),
        'sleep_quality' => fn ($x) => int_or_null($x, 0, 3),
        'sun_minutes' => fn ($x) => int_or_null($x, 0, 960),
        'sun_period' => fn ($x) => is_string($x) && isset(SUN_PERIODS[$x]) ? $x : null,
        'caffeine_cups' => fn ($x) => int_or_null($x, 0, 30), 'alcohol_units' => fn ($x) => int_or_null($x, 0, 50),
        'exercise_minutes' => fn ($x) => int_or_null($x, 0, 960),
        'racing_thoughts' => fn ($x) => int_or_null($x, 0, 3), 'fast_speech' => fn ($x) => int_or_null($x, 0, 3),
        'impulsive_spending' => fn ($x) => int_or_null($x, 0, 3),
        'spending_amount' => fn ($x) => is_numeric($x) && (float)$x >= 0 ? round(min((float)$x, 99999999), 2) : null,
        'irritability_day' => fn ($x) => int_or_null($x, 0, 3),
        'cycle_phase' => fn ($x) => is_string($x) && isset(CYCLE_PHASES[$x]) ? $x : null,
        'stress_level' => fn ($x) => int_or_null($x, 0, 3),
        'stress_note' => fn ($x) => is_scalar($x) && trim((string)$x) !== '' ? mb_substr(trim((string)$x), 0, 500) : null,
    ];
    foreach ($map as $k => $fn) if (array_key_exists($k, $in)) $v[$k] = $fn($in[$k]);
    if (array_key_exists('doses', $in)) {
        $d = [];
        foreach ((array)$in['doses'] as $id => $taken) {
            if (preg_match('/^[a-z0-9]{1,12}$/', (string)$id) && ($taken === 0 || $taken === 1 || $taken === true || $taken === false || $taken === '0' || $taken === '1')) $d[(string)$id] = (int)$taken;
        }
        $v['doses_json'] = $d ? json_encode($d) : null;
    }
    if (array_key_exists('signs', $in)) {
        $s = [];
        foreach ((array)$in['signs'] as $i) if (is_numeric($i) && (int)$i >= 0 && (int)$i < 10) $s[(int)$i] = (int)$i;
        $v['signs_json'] = $s ? json_encode(array_values($s)) : null;
    }
    return [null, ['entry_date' => $in['entry_date']] + $v];
}

/**
 * @param array<string,mixed> $in
 * @return array{0:?string,1:array<string,mixed>}
 */
function validate_sexuality(array $in): array
{
    if (!valid_iso_date($in['entry_date'] ?? null)) return ['Informe uma data válida.', []];
    $acts = [];
    foreach ((array)($in['activities'] ?? []) as $a) if (is_string($a) && isset(SEX_ACTIVITIES[$a])) $acts[$a] = $a;
    if (isset($acts['nenhuma']) && count($acts) > 1) unset($acts['nenhuma']);
    $diff = $in['different_from_usual'] ?? null;
    $note = is_scalar($in['note'] ?? null) ? trim(mb_substr((string)$in['note'], 0, 2000)) : '';
    return [null, [
        'entry_date' => $in['entry_date'],
        'libido' => int_or_null($in['libido'] ?? null, -2, 2),
        'activities' => $acts ? implode(',', array_keys($acts)) : null,
        'frequency' => int_or_null($in['frequency'] ?? null, 0, 50),
        'online_minutes' => isset($acts['online']) ? int_or_null($in['online_minutes'] ?? null, 0, 1440) : null,
        'different_from_usual' => $diff === null || $diff === '' ? null : ((int)$diff ? 1 : 0),
        'note' => $note !== '' ? $note : null,
    ]];
}

/**
 * Favoritos para registro em 1 toque (até 6): substância, forma, quantidade, rótulo livre de "Outra".
 * @param array<mixed> $in
 * @return list<array{substance:string, form:?string, amount:?float, other_name:?string}>
 */
function normalize_subst_favorites(array $in): array
{
    $out = [];
    foreach ($in as $f) {
        if (!is_array($f) || !isset(SUBSTANCES[$f['substance'] ?? ''])) continue;
        $sub = (string)$f['substance'];
        $form = isset(SUBSTANCES[$sub][2][$f['form'] ?? '']) ? (string)$f['form'] : null;
        $amt = is_numeric($f['amount'] ?? null) && (float)$f['amount'] > 0 ? round(min((float)$f['amount'], 999), 1) : null;
        $other = $sub === 'outra' && is_scalar($f['other_name'] ?? null) && trim((string)$f['other_name']) !== '' ? mb_substr(trim((string)$f['other_name']), 0, 60) : null;
        $key = "$sub|$form|$other";
        if (isset($out[$key]) || count($out) >= 6) continue;
        $out[$key] = ['substance' => $sub, 'form' => $form, 'amount' => $amt, 'other_name' => $other];
    }
    return array_values($out);
}

/**
 * Um registro de uso de substância.
 * @param array<string,mixed> $in
 * @return array{0:?string,1:array<string,mixed>}
 */
function validate_substance(array $in): array
{
    if (!valid_iso_date($in['entry_date'] ?? null)) return ['Informe uma data válida.', []];
    $sub = (string)($in['substance'] ?? '');
    if (!isset(SUBSTANCES[$sub])) return ['Escolha a substância.', []];
    $other = is_scalar($in['other_name'] ?? null) ? trim(mb_substr((string)$in['other_name'], 0, 200)) : '';
    if ($sub === 'outra' && $other === '') return ['Diga qual substância (campo “Qual?”).', []];
    $amt = $in['amount'] ?? null;
    $eff = $in['mood_effect'] ?? null;
    $above = $in['above_usual'] ?? null;
    return [null, [
        'entry_date' => $in['entry_date'],
        'period' => is_string($in['period'] ?? null) && isset(PERIODS[$in['period']]) ? $in['period'] : null,
        'substance' => $sub,
        'form' => isset(SUBSTANCES[$sub][2][$in['form'] ?? '']) ? (string)$in['form'] : null,
        'other_name' => $sub === 'outra' ? $other : null,
        'amount' => is_numeric($amt) && (float)$amt >= 0 ? round(min((float)$amt, 9999), 1) : null,
        'reason' => is_string($in['reason'] ?? null) && isset(SUBST_REASONS[$in['reason']]) ? $in['reason'] : null,
        'mood_effect' => int_or_null($eff, -2, 2),
        'above_usual' => $above === null || $above === '' ? null : ((int)$above ? 1 : 0),
    ]];
}

/**
 * Resumo privado por substância: dias com uso, humor médio nos dias com e sem uso, e no dia seguinte.
 * @param list<array<string,mixed>> $subst
 * @param list<array<string,mixed>> $entries
 * @return list<array<string,mixed>>
 */
function substance_mood_summary(array $subst, array $entries): array
{
    $mood = [];
    foreach ($entries as $e) $mood[(string)$e['entry_date']][] = (int)$e['mood'];
    $avgDay = [];
    foreach ($mood as $d => $v) $avgDay[$d] = array_sum($v) / count($v);
    $days = [];
    foreach ($subst as $s) {
        $k = (string)$s['substance'];
        $days[$k]['dates'][(string)$s['entry_date']] = true;
        $days[$k]['amount'] = ($days[$k]['amount'] ?? 0) + (float)($s['amount'] ?? 0);
        if ((int)($s['above_usual'] ?? 0) === 1) $days[$k]['above'][(string)$s['entry_date']] = true;
        if ($s['mood_effect'] !== null) $days[$k]['eff'][] = (int)$s['mood_effect'];
    }
    $avg = function (array $v): ?float { return $v ? round(array_sum($v) / count($v), 1) : null; };
    $out = [];
    foreach ($days as $k => $x) {
        $with = []; $without = []; $next = [];
        foreach ($avgDay as $d => $m) { if (isset($x['dates'][$d])) $with[] = $m; else $without[] = $m; }
        foreach (array_keys($x['dates']) as $d) { $n = date('Y-m-d', (int)strtotime("$d +1 day")); if (isset($avgDay[$n])) $next[] = $avgDay[$n]; }
        $out[] = ['substance' => $k, 'label' => SUBSTANCES[$k][0], 'unit' => SUBSTANCES[$k][1], 'days' => count($x['dates']), 'amount' => round($x['amount'], 1),
            'above_days' => count($x['above'] ?? []), 'mood_with' => $avg($with), 'mood_without' => $avg($without), 'mood_next' => $avg($next), 'effect_avg' => $avg($x['eff'] ?? [])];
    }
    usort($out, function (array $a, array $b): int { return $b['days'] <=> $a['days']; });
    return $out;
}

/** Horas de sono a partir de deitar/acordar (atravessa a meia-noite). */
function sleep_hours_from_times(?string $bed, ?string $wake): ?float
{
    if ($bed === null || $wake === null) return null;
    $b = (int)substr($bed, 0, 2) * 60 + (int)substr($bed, 3, 2);
    $w = (int)substr($wake, 0, 2) * 60 + (int)substr($wake, 3, 2);
    $min = ($w - $b + 1440) % 1440;
    return round($min / 60, 1);
}

/**
 * Indicador de sinais de (hipo)mania por dia — NÃO é diagnóstico. Combina sinais descritos no DSM-5 (critério B)
 * e em escalas como YMRS/ASRM com os sinais de alerta pessoais combinados com a equipe.
 * @param list<array<string,mixed>> $entries registros por período (humor, energia, irritabilidade, sono)
 * @param list<array<string,mixed>> $markers marcadores diários
 * @param list<array<string,mixed>> $sex registros de sexualidade (vazio se o módulo estiver desligado)
 * @param list<array<string,mixed>> $subst registros de substâncias (vazio se o módulo estiver desligado)
 * @return array<string, array{score:int, level:string, reasons:list<string>, sleep:?float, triad:bool}>
 */
function mania_indicators(array $entries, array $markers, array $sex, int $signsTotal = 0, array $subst = []): array
{
    $days = [];
    foreach ($entries as $e) $days[(string)$e['entry_date']]['e'][] = $e;
    foreach ($markers as $m) $days[(string)$m['entry_date']]['m'] = $m;
    foreach ($sex as $s) $days[(string)$s['entry_date']]['s'] = $s;
    foreach ($subst as $u) $days[(string)$u['entry_date']]['u'][] = $u;
    ksort($days);
    // sono por dia: deitar/acordar tem prioridade; senão o maior "horas de sono" informado no dia
    $sleepByDay = [];
    foreach ($days as $d => $x) {
        $h = isset($x['m']) ? sleep_hours_from_times($x['m']['bed_time'] ?? null, $x['m']['wake_time'] ?? null) : null;
        if ($h === null) foreach ($x['e'] ?? [] as $e) if ($e['sleep_hours'] !== null) $h = max($h ?? 0, (float)$e['sleep_hours']);
        $sleepByDay[$d] = $h;
    }
    $out = [];
    foreach ($days as $d => $x) {
        $score = 0; $why = [];
        // linha de base pessoal: mediana do sono dos 28 dias anteriores (mín. 5 dias); senão 7 h
        $prev = [];
        foreach ($sleepByDay as $pd => $h) if ($pd < $d && $pd >= date('Y-m-d', (int)strtotime("$d -28 days")) && $h !== null) $prev[] = $h;
        sort($prev);
        $base = count($prev) >= 5 ? $prev[intdiv(count($prev), 2)] : 7.0;
        $sl = $sleepByDay[$d];
        $sleepDown = false;
        if ($sl !== null && $sl <= $base - 2) { $score += 2; $why[] = 'dormiu bem menos que o habitual'; $sleepDown = true; }
        elseif ($sl !== null && $sl <= $base - 1) { $score += 1; $why[] = 'dormiu menos que o habitual'; $sleepDown = true; }
        $moodMax = null; $energy = null; $irrit = null;
        foreach ($x['e'] ?? [] as $e) {
            $moodMax = max($moodMax ?? -9, (int)$e['mood']);
            if ($e['energy'] !== null) $energy = max($energy ?? 0, (int)$e['energy']);
            if ($e['irritability'] !== null) $irrit = max($irrit ?? 0, (int)$e['irritability']);
        }
        if ($moodMax !== null && $moodMax >= 2) { $score += 2; $why[] = 'humor bem elevado'; }
        elseif ($moodMax === 1) { $score += 1; $why[] = 'humor elevado'; }
        if ($energy !== null && $energy >= 3) { $score += 1; $why[] = 'energia intensa'; }
        $m = $x['m'] ?? [];
        if (max($irrit ?? 0, (int)($m['irritability_day'] ?? 0)) >= 2) { $score += 1; $why[] = 'irritabilidade'; }
        if ((int)($m['racing_thoughts'] ?? 0) >= 2) { $score += 1; $why[] = 'pensamentos acelerados'; }
        if ((int)($m['fast_speech'] ?? 0) >= 2) { $score += 1; $why[] = 'fala acelerada'; }
        if ((int)($m['impulsive_spending'] ?? 0) >= 2) { $score += 1; $why[] = 'gastos impulsivos'; }
        $signs = json_decode((string)($m['signs_json'] ?? ''), true);
        if (is_array($signs) && $signs) { $score += min(2, count($signs)); $why[] = (count($signs) === 1 ? '1 sinal de alerta pessoal' : count($signs) . ' sinais de alerta pessoais'); }
        $libUp = false;
        if (isset($x['s'])) {
            $s = $x['s'];
            $lib = $s['libido'] === null ? null : (int)$s['libido'];
            if ($lib !== null && $lib >= 1) { $score += $lib; $why[] = $lib >= 2 ? 'desejo sexual muito acima do habitual' : 'desejo sexual acima do habitual'; $libUp = true; }
            $risk = 0;
            foreach (explode(',', (string)$s['activities']) as $a) $risk += SEX_ACTIVITIES[$a][1] ?? 0;
            if ($risk > 0) { $score += min(2, $risk); $why[] = 'sinais de hipersexualidade/comportamento sexual de risco'; }
            if ((int)$s['different_from_usual'] === 1 && ($lib ?? 0) > 0) { $score += 1; $why[] = 'comportamento sexual diferente do habitual'; }
        }
        if (!empty($x['u'])) {
            $stim = false; $above = false; $cannAbove = false;
            foreach ($x['u'] as $u) {
                $cls = SUBSTANCES[$u['substance']][3] ?? 'outra';
                if ($cls === 'estimulante' || $cls === 'psicodelico') $stim = true;
                if ((int)($u['above_usual'] ?? 0) === 1) { $above = true; if ($cls === 'cannabis') $cannAbove = true; }
            }
            if ($stim) { $score += 2; $why[] = 'uso de estimulante ou psicodélico'; }
            if ($above) { $score += 1; $why[] = 'uso de substância acima do habitual'; }
            if ($cannAbove && $sleepDown) { $score += 1; $why[] = 'cannabis acima do habitual com sono reduzido'; }
        }
        $triad = $sleepDown && ($moodMax ?? -9) >= 1 && $libUp;
        $level = $score >= 5 ? 'alerta' : ($score >= 3 ? 'atencao' : 'ok');
        $out[$d] = ['score' => $score, 'level' => $level, 'reasons' => $why, 'sleep' => $sl, 'triad' => $triad];
    }
    return $out;
}

/**
 * Alerta precoce: tríade (sono ↓ + humor ↑ + desejo ↑) num dos últimos 3 dias, ou ≥ 2 dos últimos 3 dias com nível
 * "atenção"/"alerta", ou um dia em "alerta". Devolve a mensagem gentil ou null.
 * @param array<string, array{score:int, level:string, reasons:list<string>, sleep:?float, triad:bool}> $ind
 */
function early_warning(array $ind, string $today): ?string
{
    $recent = array_filter($ind, function (string $d) use ($today): bool { return $d <= $today && $d > date('Y-m-d', (int)strtotime("$today -3 days")); }, ARRAY_FILTER_USE_KEY);
    if (!$recent) return null;
    $triad = array_filter($recent, function (array $x): bool { return $x['triad']; });
    $warn = array_filter($recent, function (array $x): bool { return $x['level'] !== 'ok'; });
    $alert = array_filter($recent, function (array $x): bool { return $x['level'] === 'alerta'; });
    if (!$triad && count($warn) < 2 && !$alert) return null;
    $reasons = [];
    foreach ($recent as $x) foreach ($x['reasons'] as $r) $reasons[$r] = true;
    return 'Nos últimos dias apareceram alguns sinais que, em muitas pessoas, antecedem a hipomania ou a mania ('
        . implode(', ', array_slice(array_keys($reasons), 0, 4)) . '). Isso não é um diagnóstico — mas é um bom momento para '
        . 'proteger o sono, evitar decisões grandes por impulso e conversar com seu psiquiatra ou psicólogo.';
}

/**
 * Lê o JSON bruto de prefs, aplica $patch nas chaves de 1º nível e salva.
 * @param array<string,mixed> $u
 * @param array<string,mixed> $patch
 * @return array<string,mixed>
 */
function prefs_patch(PDO $pdo, array $u, array $patch): array
{
    $st = $pdo->prepare('SELECT prefs FROM users WHERE id = ?');
    $st->execute([$u['id']]);
    $p = json_decode((string)$st->fetchColumn(), true);
    $p = is_array($p) ? $p : [];
    foreach ($patch as $k => $v) $p[$k] = $v;
    $pdo->prepare('UPDATE users SET prefs = ? WHERE id = ?')->execute([json_encode($p, JSON_UNESCAPED_UNICODE), $u['id']]);
    return $p;
}
