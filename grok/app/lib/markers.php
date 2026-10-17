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
    'mente' => ['Fala acelerada, gastos impulsivos, irritabilidade', true],
    'pensamentos' => ['Pensamentos por período (positivos, negativos, acelerados, grandiosidade, ruminação)', true],
    'estresse' => ['Acontecimentos e estresse (por período)', true],
    'alimentacao' => ['Alimentação: doces, ultraprocessados e comer fora de hora (por período)', true],
    'medicacao' => ['Medicação por dose', false],
    'sinais' => ['Meus sinais de alerta pessoais', false],
    'ciclo' => ['Ciclo menstrual', false],
    'sexualidade' => ['Sexualidade (privado)', false],
    'substancias' => ['Substâncias (privado, redução de danos)', false],
    'pensamentos_morte' => ['Pensamentos de morte ou suicídio (privado)', false],
];
/** V4.8: módulos atrás do PIN/biometria (seções privadas). */
const PRIVATE_MODULES = ['sexualidade', 'substancias', 'pensamentos_morte'];

/**
 * V4.8: pensamentos por período — coluna => [rótulo, ajuda]. Mesma escala de 4 níveis (null = não informado).
 * th_racing alimenta daily_markers.racing_thoughts (maior valor do dia), que continua sendo usado por alertas e relatórios.
 * th_death é privado (módulo pensamentos_morte, atrás do PIN) e nunca vai para a IA sem a categoria própria.
 */
const THOUGHT_FIELDS = [
    'th_positive' => ['Positivos / esperançosos', ''],
    'th_negative' => ['Negativos / pessimistas', ''],
    'th_racing' => ['Acelerados / muitas ideias', ''],
    'th_grandiose' => ['Grandiosidade / missão especial', 'pensar que tem um papel único ou uma mensagem para espalhar'],
    'th_rumination' => ['Ruminação / culpa', ''],
    'th_death' => ['Pensamentos de morte ou suicídio', ''],
];
const THOUGHT_LEVELS = [0 => 'Nenhum', 1 => 'Leve', 2 => 'Moderado', 3 => 'Intenso'];
/** V4.8: outra forma de nicotina (além de cigarros), no dia, junto da cafeína. */
const NICOTINE_OTHER = ['nenhuma' => 'Nenhuma', 'vape_pod' => 'Vape / pod', 'palha' => 'Cigarro de palha', 'charuto_cachimbo' => 'Charuto / cachimbo', 'narguile' => 'Narguilé', 'adesivo_chiclete' => 'Adesivo / chiclete'];

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

/** V4.6: o operador da instalação pode desligar um módulo para todos ('modules' => ['sexualidade' => false]). */
function tenant_module_available(string $k): bool
{
    return cfg('modules.' . $k, true) !== false;
}

/**
 * @param array<string,mixed> $u
 * @return array<string,mixed>
 */
function user_prefs(array $u): array
{
    $p = json_decode((string)($u['prefs'] ?? ''), true);
    $p = is_array($p) ? $p : [];
    $mods = [];
    foreach (DAY_MODULES as $k => [, $def]) $mods[$k] = tenant_module_available($k) && (isset($p['modules'][$k]) ? (bool)$p['modules'][$k] : $def);
    return [
        'modules' => $mods,
        'sex_blur' => isset($p['sex_blur']) ? (bool)$p['sex_blur'] : true,
        'subst_blur' => isset($p['subst_blur']) ? (bool)$p['subst_blur'] : true,
        'death_blur' => isset($p['death_blur']) ? (bool)$p['death_blur'] : true,
        'trusted_contact' => normalize_trusted_contact((array)($p['trusted_contact'] ?? [])),
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
    foreach (array_keys(DAY_MODULES) as $k) $mods[$k] = tenant_module_available($k) && !empty($in['modules'][$k]);
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
        'death_blur' => !isset($in['death_blur']) || !empty($in['death_blur']),
        'trusted_contact' => normalize_trusted_contact((array)($in['trusted_contact'] ?? [])),
        'subst_favorites' => normalize_subst_favorites((array)($in['subst_favorites'] ?? [])),
        'signs' => $signs,
        'action_plan' => trim(mb_substr((string)($in['action_plan'] ?? ''), 0, 1000)),
        'meds' => $meds,
    ];
}

/**
 * V4.8: contato de confiança (aparece no painel de apoio como link de telefone). Nunca é avisado automaticamente.
 * @param array<mixed> $in
 * @return array{name:string, phone:string}
 */
function normalize_trusted_contact(array $in): array
{
    $name = is_scalar($in['name'] ?? null) ? trim(mb_substr((string)$in['name'], 0, 80)) : '';
    $raw = is_scalar($in['phone'] ?? null) ? trim((string)$in['phone']) : '';
    $digits = preg_replace('/\D/', '', $raw);
    $phone = $digits !== '' && strlen((string)$digits) >= 8 && strlen((string)$digits) <= 15 ? (strpos($raw, '+') === 0 ? '+' : '') . $digits : '';
    return ['name' => $phone === '' ? '' : $name, 'phone' => $phone];
}

/** @param mixed $v */
function hhmm_or_null($v): ?string
{
    return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;
}

/**
 * V4.7.1: valor ENVIADO (não vazio) que o validador transformou em null = fora da faixa ou inválido → erro 422.
 * @param array<string,mixed> $in
 * @param array<string,mixed> $v
 * @param array<string,string> $labels
 */
function markers_invalid_field(array $in, array $v, array $labels): ?string
{
    foreach ($labels as $k => $label) {
        if (!array_key_exists($k, $in) || !array_key_exists($k, $v)) continue;
        $raw = $in[$k];
        if ($raw === null || $raw === '' || $v[$k] !== null) continue;
        return 'Valor inválido em “' . $label . '”. Escolha uma das opções da escala ou deixe em branco.';
    }
    return null;
}
/** Campos numéricos/escalas de Meu dia que são conferidos estritamente (V4.7.1). */
const DAY_STRICT_LABELS = ['sleep_quality' => 'Qualidade do sono', 'sun_minutes' => 'Minutos de sol', 'sun_period' => 'Período do sol',
    'caffeine_cups' => 'Cafeína', 'alcohol_units' => 'Álcool', 'exercise_minutes' => 'Exercício', 'racing_thoughts' => 'Pensamentos acelerados',
    'fast_speech' => 'Fala acelerada', 'impulsive_spending' => 'Gastos por impulso', 'spending_amount' => 'Quanto gastei por impulso',
    'irritability_day' => 'Irritabilidade no dia', 'cycle_phase' => 'Ciclo menstrual', 'cigarettes' => 'Cigarros', 'nicotine_other' => 'Outra forma de nicotina'];
/** Colunas de dados de daily_markers (sem id/usuário/data/updated_at): todas nulas = linha vazia, apagada (V4.7.1). */
const DAY_DATA_COLS = ['bed_time', 'wake_time', 'sleep_quality', 'sun_minutes', 'sun_period', 'caffeine_cups', 'alcohol_units', 'substances',
    'exercise_minutes', 'racing_thoughts', 'fast_speech', 'impulsive_spending', 'spending_amount', 'irritability_day', 'cycle_phase',
    'stress_level', 'stress_note', 'food_sweets', 'food_processed', 'food_binge', 'doses_json', 'signs_json',
    'th_positive', 'th_negative', 'th_grandiose', 'th_rumination', 'th_death', 'cigarettes', 'nicotine_other'];

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
        'cigarettes' => fn ($x) => (is_int($x) || (is_string($x) && ctype_digit($x)) || (is_float($x) && floor($x) === $x)) ? int_or_null($x, 0, 100) : null, // inteiro 0–100
        'nicotine_other' => fn ($x) => is_string($x) && isset(NICOTINE_OTHER[$x]) ? $x : null,
    ];
    foreach ($map as $k => $fn) if (array_key_exists($k, $in)) $v[$k] = $fn($in[$k]);
    $bad = markers_invalid_field($in, $v, DAY_STRICT_LABELS);
    if ($bad !== null) return [$bad, []];
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

/** V4.7: campos registrados POR PERÍODO (manhã, tarde, noite) — os demais marcadores valem para o dia. */
const PERIOD_MARKER_FIELDS = ['stress_level', 'stress_note', 'food_sweets', 'food_processed', 'food_binge',
    'th_positive', 'th_negative', 'th_racing', 'th_grandiose', 'th_rumination', 'th_death'];
/** Escala da Alimentação (mesmos 4 níveis dos outros itens; null = não informado). */
const FOOD_LEVELS = [0 => 'Nenhum', 1 => 'Pouco', 2 => 'Moderado', 3 => 'Muito'];
const PERIODS_ALL = ['manha', 'tarde', 'noite'];

/** Período do relógio da pessoa (mesma regra do app: antes de 12 h manhã, antes de 18 h tarde). */
function period_now(string $tz): string
{
    try { $h = (int)(new DateTime('now', new DateTimeZone($tz)))->format('G'); } catch (Exception $e) { $h = (int)date('G'); }
    return $h < 12 ? 'manha' : ($h < 18 ? 'tarde' : 'noite');
}

/**
 * Valida os campos por período. Só os ENVIADOS entram. Sem 'period' (app antigo em cache) usa o período do relógio.
 * @param array<string,mixed> $in
 * @return array{0:?string,1:array<string,mixed>} [erro, campos] — campos vazio = nada por período neste envio
 */
function validate_period_markers(array $in, string $tz = 'America/Sao_Paulo'): array
{
    $sent = array_intersect(PERIOD_MARKER_FIELDS, array_keys($in));
    if (!$sent) return [null, []];
    $p = $in['period'] ?? null;
    if ($p === null || $p === '') $p = period_now($tz);
    if (!is_string($p) || !in_array($p, PERIODS_ALL, true)) return ['Escolha o período (Manhã, Tarde ou Noite).', []];
    $map = [
        'stress_level' => fn ($x) => int_or_null($x, 0, 3),
        'stress_note' => fn ($x) => is_scalar($x) && trim((string)$x) !== '' ? mb_substr(trim((string)$x), 0, 500) : null,
        'food_sweets' => fn ($x) => int_or_null($x, 0, 3),
        'food_processed' => fn ($x) => int_or_null($x, 0, 3),
        'food_binge' => fn ($x) => in_array($x, [true, 1, '1'], true) ? 1 : (in_array($x, [false, 0, '0'], true) ? 0 : null),
    ];
    foreach (array_keys(THOUGHT_FIELDS) as $k) $map[$k] = fn ($x) => int_or_null($x, 0, 3);
    $v = ['period' => $p];
    foreach ($sent as $k) $v[$k] = $map[$k]($in[$k]);
    $bad = markers_invalid_field($in, $v, ['stress_level' => 'Nível de estresse', 'food_sweets' => 'Doces',
        'food_processed' => 'Industrializados / ultraprocessados', 'food_binge' => 'Comi fora de hora / compulsão']
        + array_map(function (array $t): string { return strpos($t[0], 'Pensamentos') === 0 ? $t[0] : 'Pensamentos ' . mb_strtolower($t[0]); }, THOUGHT_FIELDS));
    if ($bad !== null) return [$bad, []];
    return [null, $v];
}

/**
 * Totais do dia a partir dos períodos (maior nível; anotações juntas com o nome do período). Puro.
 * @param list<array<string,mixed>> $rows linhas de period_markers de UM dia
 * @return array<string,int|string|null> stress_level, stress_note, food_*, th_* (maior nível do dia)
 */
function period_markers_day_totals(array $rows): array
{
    $names = ['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite'];
    usort($rows, function (array $a, array $b): int { return array_search($a['period'], PERIODS_ALL, true) <=> array_search($b['period'], PERIODS_ALL, true); });
    $max = function (string $k) use ($rows): ?int { $v = array_filter(array_column($rows, $k), function ($x) { return $x !== null; }); return $v ? (int)max($v) : null; };
    $withNote = array_values(array_filter($rows, function (array $r): bool { return $r['stress_note'] !== null && trim((string)$r['stress_note']) !== ''; }));
    $notes = [];
    foreach ($withNote as $r) $notes[] = (count($withNote) > 1 ? ($names[$r['period']] ?? $r['period']) . ': ' : '') . trim((string)$r['stress_note']);
    $out = ['stress_level' => $max('stress_level'), 'stress_note' => $notes ? mb_substr(implode(' · ', $notes), 0, 500) : null,
        'food_sweets' => $max('food_sweets'), 'food_processed' => $max('food_processed'), 'food_binge' => $max('food_binge')];
    foreach (array_keys(THOUGHT_FIELDS) as $k) $out[$k] = $rows && array_key_exists($k, $rows[0]) ? $max($k) : null;
    return $out;
}

/**
 * Grava um período e recalcula os totais do dia em daily_markers (usados por alertas, relatórios e IA).
 * @param array<string,mixed> $v resultado de validate_period_markers()
 */
function period_markers_save(PDO $pdo, int $uid, string $date, array $v): void
{
    $cols = array_values(array_intersect(PERIOD_MARKER_FIELDS, array_keys($v)));
    $set = array_map(function (string $c): string { return "$c=VALUES($c)"; }, $cols);
    $pdo->prepare('INSERT INTO period_markers (user_id, entry_date, period' . ($cols ? ', ' . implode(', ', $cols) : '') . ') VALUES (?,?,?' . str_repeat(',?', count($cols)) . ')'
        . ' ON DUPLICATE KEY UPDATE ' . ($set ? implode(', ', $set) . ', migrated = 0' : 'period = period'))
        ->execute(array_merge([$uid, $date, $v['period']], array_map(function (string $c) use ($v) { return $v[$c]; }, $cols)));
    // V4.7.1: período sem nada preenchido não fica como linha vazia
    $pdo->prepare('DELETE FROM period_markers WHERE user_id = ? AND entry_date = ? AND period = ? AND ' . implode(' IS NULL AND ', PERIOD_MARKER_FIELDS) . ' IS NULL')
        ->execute([$uid, $date, $v['period']]);
    $st = $pdo->prepare('SELECT period, ' . implode(', ', PERIOD_MARKER_FIELDS) . ' FROM period_markers WHERE user_id = ? AND entry_date = ?');
    $st->execute([$uid, $date]);
    $rows = $st->fetchAll();
    $t = period_markers_day_totals($rows);
    $dayCols = ['stress_level', 'stress_note', 'food_sweets', 'food_processed', 'food_binge', 'th_positive', 'th_negative', 'th_grandiose', 'th_rumination', 'th_death'];
    $vals = array_map(function (string $c) use ($t) { return $t[$c] ?? null; }, $dayCols);
    // V4.8: pensamentos acelerados do dia = maior valor dos períodos (só quando este envio mexeu nesse item;
    // quem desligou "Pensamentos por período" continua usando o campo do dia)
    if (array_key_exists('th_racing', $v)) { $dayCols[] = 'racing_thoughts'; $vals[] = $t['th_racing'] ?? null; }
    $pdo->prepare('INSERT INTO daily_markers (user_id, entry_date, ' . implode(', ', $dayCols) . ') VALUES (?,?' . str_repeat(',?', count($dayCols)) . ')
        ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(function (string $c): string { return "$c=VALUES($c)"; }, $dayCols)))
        ->execute(array_merge([$uid, $date], $vals));
}

/** V4.7.1: apaga a linha de daily_markers do dia se todas as colunas de dados ficaram vazias. */
function daily_markers_prune(PDO $pdo, int $uid, string $date): void
{
    $pdo->prepare('DELETE FROM daily_markers WHERE user_id = ? AND entry_date = ? AND ' . implode(' IS NULL AND ', DAY_DATA_COLS) . ' IS NULL')->execute([$uid, $date]);
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
        if ((int)($m['th_grandiose'] ?? 0) >= 2) { $score += 1; $why[] = 'grandiosidade'; }
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
