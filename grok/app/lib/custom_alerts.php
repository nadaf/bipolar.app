<?php
/**
 * V4.2: alertas personalizados. A pessoa monta regras combinando condições (E/OU) sobre tudo o que registra.
 * Cada condição: métrica + comparação + valor, avaliada "no dia", "em pelo menos K dos últimos N dias"
 * ou "por N dias seguidos". As funções custom_* sem PDO são puras (testáveis sem banco).
 * Push e e-mail nunca levam detalhes: só um texto genérico. Regras com Sexualidade/Substâncias ficam atrás do desbloqueio.
 */
require_once APP_ROOT . '/lib/signals.php';

const CUSTOM_PRIV_SEX = 1;
const CUSTOM_PRIV_SUBST = 2;
const CUSTOM_MAX_RULES = 30;
const CUSTOM_MAX_CONDITIONS = 6;
const CUSTOM_OPS = ['>=' => '≥', '<=' => '≤', '>' => '>', '<' => '<', '=' => '='];
const CUSTOM_MODES = ['day' => 'no dia', 'count' => 'em pelo menos K dos últimos N dias', 'streak' => 'por N dias seguidos'];
const CUSTOM_SCALE03 = [0 => 'não', 1 => 'leve', 2 => 'moderado', 3 => 'intenso'];

/**
 * Métricas: chave => [rótulo, grupo, mín, máx, passo, tipo, privado (0, 1 = sexualidade, 2 = substâncias), unidade].
 * Tipos: mood (−3..+3 com sinal), scale (0 não … 3 intenso), num, bool (1 sim / 0 não), libido (−2..+2).
 * @return array<string, array{0:string,1:string,2:float,3:float,4:float,5:string,6:int,7:string}>
 */
function custom_metrics(): array
{
    static $m = null;
    if ($m !== null) return $m;
    $m = [
        'mood_max' => ['Humor mais alto do dia', 'Humor', -3, 3, 1, 'mood', 0, ''],
        'mood_min' => ['Humor mais baixo do dia', 'Humor', -3, 3, 1, 'mood', 0, ''],
        'mood_mean' => ['Humor médio do dia', 'Humor', -3, 3, 0.5, 'mood', 0, ''],
        'mood_manha' => ['Humor na Manhã', 'Humor', -3, 3, 1, 'mood', 0, ''],
        'mood_tarde' => ['Humor na Tarde', 'Humor', -3, 3, 1, 'mood', 0, ''],
        'mood_noite' => ['Humor na Noite', 'Humor', -3, 3, 1, 'mood', 0, ''],
        'mood_range' => ['Variação do humor no mesmo dia (pontos)', 'Humor', 0, 6, 1, 'num', 0, 'pontos'],
        'sleep' => ['Horas de sono', 'Sono', 0, 24, 0.5, 'num', 0, 'h'],
        'sleep_quality' => ['Qualidade do sono (0 ruim … 3 ótima)', 'Sono', 0, 3, 1, 'num', 0, ''],
        'energy' => ['Energia', 'Corpo e mente', 0, 3, 1, 'scale', 0, ''],
        'irrit' => ['Irritabilidade', 'Corpo e mente', 0, 3, 1, 'scale', 0, ''],
        'anxiety' => ['Ansiedade', 'Corpo e mente', 0, 3, 1, 'scale', 0, ''],
        'racing' => ['Pensamentos acelerados', 'Corpo e mente', 0, 3, 1, 'scale', 0, ''],
        'speech' => ['Fala acelerada', 'Corpo e mente', 0, 3, 1, 'scale', 0, ''],
        'spending' => ['Gastos por impulso', 'Corpo e mente', 0, 3, 1, 'scale', 0, ''],
        'spend_amount' => ['Valor gasto por impulso', 'Corpo e mente', 0, 99999, 10, 'num', 0, 'R$'],
        'stress' => ['Estresse', 'Corpo e mente', 0, 3, 1, 'scale', 0, ''],
        'signs' => ['Sinais de alerta pessoais marcados', 'Corpo e mente', 0, 10, 1, 'num', 0, 'sinais'],
        'caffeine' => ['Café / cafeína', 'Hábitos', 0, 30, 1, 'num', 0, 'xícaras'],
        'alcohol' => ['Álcool', 'Hábitos', 0, 50, 1, 'num', 0, 'doses'],
        'sun' => ['Sol / luz natural', 'Hábitos', 0, 960, 5, 'num', 0, 'min'],
        'exercise' => ['Exercício', 'Hábitos', 0, 960, 5, 'num', 0, 'min'],
        'med_missed' => ['Doses de medicação não tomadas', 'Medicação', 0, 20, 1, 'num', 0, 'doses'],
        'med_pct' => ['Adesão à medicação no dia', 'Medicação', 0, 100, 5, 'num', 0, '%'],
        'logged' => ['Registrou o humor no dia', 'Registros', 0, 1, 1, 'bool', 0, ''],
        'media' => ['Notas em áudio/vídeo/imagem', 'Registros', 0, 50, 1, 'num', 0, 'arquivos'],
        'libido' => ['Desejo sexual (vs. o seu habitual)', 'Sexualidade (privado)', -2, 2, 1, 'libido', CUSTOM_PRIV_SEX, ''],
        'sex_risk' => ['Sinais de comportamento sexual de risco', 'Sexualidade (privado)', 0, 10, 1, 'num', CUSTOM_PRIV_SEX, 'sinais'],
        'sex_freq' => ['Frequência de atividade sexual no dia', 'Sexualidade (privado)', 0, 50, 1, 'num', CUSTOM_PRIV_SEX, 'vezes'],
        'sex_online' => ['Tempo em conteúdo/sexo online', 'Sexualidade (privado)', 0, 1440, 10, 'num', CUSTOM_PRIV_SEX, 'min'],
        'sex_diff' => ['Comportamento sexual diferente do habitual', 'Sexualidade (privado)', 0, 1, 1, 'bool', CUSTOM_PRIV_SEX, ''],
        'subst_uses' => ['Registros de uso de qualquer substância', 'Substâncias (privado)', 0, 50, 1, 'num', CUSTOM_PRIV_SUBST, 'registros'],
        'subst_above' => ['Usos acima do habitual', 'Substâncias (privado)', 0, 50, 1, 'num', CUSTOM_PRIV_SUBST, 'registros'],
        'stim' => ['Usos de estimulantes', 'Substâncias (privado)', 0, 50, 1, 'num', CUSTOM_PRIV_SUBST, 'registros'],
    ];
    foreach (SUBSTANCES as $k => $s) $m['sub_' . $k] = [$s[0] . ' (quantidade)', 'Substâncias (privado)', 0, 9999, 1, 'num', CUSTOM_PRIV_SUBST, $s[1]];
    return $m;
}

/**
 * Modelos prontos.
 * @return array<string, array{name:string, logic:string, conditions:list<array<string,mixed>>, severity:int, description:string}>
 */
function custom_templates(): array
{
    $c = function (string $metric, string $op, float $value, string $mode = 'day', int $days = 1, int $min = 1): array {
        return ['metric' => $metric, 'op' => $op, 'value' => $value, 'mode' => $mode, 'days' => $days, 'min' => $min];
    };
    return [
        'humor_alto_desejo' => ['name' => 'Humor alto com desejo sexual alto', 'logic' => 'and', 'severity' => 2, 'description' => 'Humor +2 ou mais junto com desejo sexual acima do habitual.',
            'conditions' => [$c('mood_max', '>=', 2), $c('libido', '>=', 1)]],
        'sono_curto' => ['name' => 'Sono curto duas noites seguidas', 'logic' => 'and', 'severity' => 2, 'description' => 'Dormiu 5 h ou menos por 2 dias seguidos.',
            'conditions' => [$c('sleep', '<=', 5, 'streak', 2)]],
        'humor_baixo' => ['name' => 'Humor baixo na maior parte da semana', 'logic' => 'and', 'severity' => 2, 'description' => 'Humor médio −1 ou menos em pelo menos 5 dos últimos 7 dias.',
            'conditions' => [$c('mood_mean', '<=', -1, 'count', 7, 5)]],
        'aceleracao' => ['name' => 'Pensamentos acelerados e pouco sono', 'logic' => 'and', 'severity' => 2, 'description' => 'Pensamentos acelerados moderados ou mais com 6 h de sono ou menos.',
            'conditions' => [$c('racing', '>=', 2), $c('sleep', '<=', 6)]],
        'gastos' => ['name' => 'Gastos por impulso repetidos', 'logic' => 'and', 'severity' => 1, 'description' => 'Gastos por impulso moderados ou mais em pelo menos 2 dos últimos 7 dias.',
            'conditions' => [$c('spending', '>=', 2, 'count', 7, 2)]],
        'doses' => ['name' => 'Doses esquecidas', 'logic' => 'and', 'severity' => 1, 'description' => 'Alguma dose não tomada em pelo menos 2 dos últimos 7 dias.',
            'conditions' => [$c('med_missed', '>=', 1, 'count', 7, 2)]],
        'sem_registro' => ['name' => 'Três dias sem registrar', 'logic' => 'and', 'severity' => 1, 'description' => 'Nenhum registro de humor por 3 dias seguidos.',
            'conditions' => [$c('logged', '=', 0, 'streak', 3)]],
        'irritacao' => ['name' => 'Irritação ou ansiedade intensas', 'logic' => 'or', 'severity' => 1, 'description' => 'Irritabilidade intensa ou ansiedade intensa no dia.',
            'conditions' => [$c('irrit', '>=', 3), $c('anxiety', '>=', 3)]],
        'cafe' => ['name' => 'Muito café', 'logic' => 'and', 'severity' => 1, 'description' => '5 xícaras de café ou mais no dia.',
            'conditions' => [$c('caffeine', '>=', 5)]],
        'substancia_acima' => ['name' => 'Substâncias acima do habitual', 'logic' => 'and', 'severity' => 1, 'description' => 'Uso acima do habitual em pelo menos 2 dos últimos 7 dias.',
            'conditions' => [$c('subst_above', '>=', 1, 'count', 7, 2)]],
    ];
}

/**
 * Bits de privacidade (1 sexualidade, 2 substâncias) das condições.
 * @param list<array<string,mixed>> $conds
 */
function custom_private_mask(array $conds): int
{
    $m = custom_metrics();
    $mask = 0;
    foreach ($conds as $c) $mask |= (int)($m[(string)($c['metric'] ?? '')][6] ?? 0);
    return $mask;
}

/**
 * Valida uma regra vinda da tela. $modules diz quais módulos privados estão ligados.
 * @param array<string,mixed> $in
 * @param array<string,bool> $modules
 * @return array{0:?string,1:array<string,mixed>}
 */
function custom_rule_validate(array $in, array $modules = []): array
{
    $name = is_scalar($in['name'] ?? null) ? trim(mb_substr((string)$in['name'], 0, 100)) : '';
    if ($name === '') return ['Dê um nome para o alerta.', []];
    $logic = ($in['logic'] ?? 'and') === 'or' ? 'or' : 'and';
    $raw = is_array($in['conditions'] ?? null) ? array_values($in['conditions']) : [];
    if (!$raw) return ['Adicione pelo menos uma condição.', []];
    if (count($raw) > CUSTOM_MAX_CONDITIONS) return ['Use no máximo ' . CUSTOM_MAX_CONDITIONS . ' condições por alerta.', []];
    $metrics = custom_metrics();
    $conds = [];
    foreach ($raw as $i => $c) {
        $n = $i + 1;
        if (!is_array($c)) return ["Condição $n inválida.", []];
        $k = (string)($c['metric'] ?? '');
        if (!isset($metrics[$k])) return ["Condição $n: escolha o que medir.", []];
        $mt = $metrics[$k];
        if ($mt[6] === CUSTOM_PRIV_SEX && empty($modules['sexualidade'])) return ["Condição $n: ative o módulo Sexualidade em Conta para usar esse dado.", []];
        if ($mt[6] === CUSTOM_PRIV_SUBST && empty($modules['substancias'])) return ["Condição $n: ative o módulo Substâncias em Conta para usar esse dado.", []];
        $op = (string)($c['op'] ?? '');
        if (!isset(CUSTOM_OPS[$op])) return ["Condição $n: escolha a comparação.", []];
        $v = $c['value'] ?? null;
        if (is_string($v)) $v = str_replace(['−', ','], ['-', '.'], trim($v));
        if (!is_numeric($v)) return ["Condição $n: informe um número.", []];
        $v = (float)$v;
        if ($v < $mt[2] || $v > $mt[3]) return ["Condição $n: o valor deve ficar entre " . custom_fmt_value($k, $mt[2]) . ' e ' . custom_fmt_value($k, $mt[3]) . '.', []];
        $mode = (string)($c['mode'] ?? 'day');
        if (!isset(CUSTOM_MODES[$mode])) $mode = 'day';
        $days = 1; $min = 1;
        if ($mode === 'count') {
            $days = (int)($c['days'] ?? 7); $min = (int)($c['min'] ?? 1);
            if ($days < 2 || $days > 30) return ["Condição $n: o período deve ter de 2 a 30 dias.", []];
            if ($min < 1 || $min > $days) return ["Condição $n: o número de dias deve ficar entre 1 e $days.", []];
        } elseif ($mode === 'streak') {
            $days = (int)($c['days'] ?? 2);
            if ($days < 2 || $days > 14) return ["Condição $n: dias seguidos devem ficar entre 2 e 14.", []];
            $min = $days;
        }
        $conds[] = ['metric' => $k, 'op' => $op, 'value' => $v, 'mode' => $mode, 'days' => $days, 'min' => $min];
    }
    $sev = (int)($in['severity'] ?? 1) === 2 ? 2 : 1;
    $cool = max(1, min(14, (int)($in['cooldown_days'] ?? 3)));
    $tpl = is_string($in['template'] ?? null) && isset(custom_templates()[$in['template']]) ? $in['template'] : null;
    return [null, ['name' => $name, 'logic' => $logic, 'conditions' => $conds, 'severity' => $sev,
        'notify_push' => !empty($in['notify_push']), 'notify_email' => !empty($in['notify_email']), 'cooldown_days' => $cool,
        'template' => $tpl, 'is_private' => custom_private_mask($conds)]];
}

/** Valor formatado em pt-BR conforme o tipo da métrica. */
function custom_fmt_value(string $metric, float $v): string
{
    $mt = custom_metrics()[$metric] ?? null;
    $type = $mt[5] ?? 'num';
    $num = fmt_num($v, fmod($v, 1.0) === 0.0 ? 0 : 1, true);
    if ($type === 'mood' || $type === 'libido') return ($v > 0 ? '+' : '') . $num;
    if ($type === 'scale' && fmod($v, 1.0) === 0.0 && isset(CUSTOM_SCALE03[(int)$v])) return CUSTOM_SCALE03[(int)$v] . ' (' . $num . ')';
    if ($type === 'bool') return $v >= 1 ? 'sim' : 'não';
    $unit = $mt[7] ?? '';
    if ($unit === 'R$') return 'R$ ' . fmt_num($v, 2);
    return $num . ($unit !== '' ? ' ' . $unit : '');
}

/** @param array<string,mixed> $c */
function custom_describe_condition(array $c): string
{
    $mt = custom_metrics()[(string)$c['metric']] ?? null;
    $label = mb_strtolower(mb_substr($mt[0] ?? (string)$c['metric'], 0, 1)) . mb_substr($mt[0] ?? (string)$c['metric'], 1);
    if (($mt[5] ?? '') === 'bool' && $c['op'] === '=') $txt = ((float)$c['value'] >= 1 ? '' : 'não ') . $label;
    else $txt = $label . ' ' . (CUSTOM_OPS[$c['op']] ?? $c['op']) . ' ' . custom_fmt_value((string)$c['metric'], (float)$c['value']);
    if ($c['mode'] === 'count') $txt .= ' em pelo menos ' . plural_br((int)$c['min'], 'dia', 'dias') . ' dos últimos ' . (int)$c['days'];
    elseif ($c['mode'] === 'streak') $txt .= ' por ' . (int)$c['days'] . ' dias seguidos';
    return $txt;
}

/** @param array<string,mixed> $r regra validada (conditions + logic) */
function custom_describe_rule(array $r): string
{
    $parts = array_map('custom_describe_condition', (array)$r['conditions']);
    return implode($r['logic'] === 'or' ? ' OU ' : ' E ', $parts);
}

/**
 * Série diária para as regras: signals_daily + campos extras (ansiedade, qualidade do sono, exercício, sexualidade e substâncias).
 * @param list<array<string,mixed>> $entries
 * @param list<array<string,mixed>> $markers
 * @param list<array<string,mixed>> $sex
 * @param list<array<string,mixed>> $subst
 * @param array<string,int> $media
 * @return array<string, array<string,mixed>>
 */
function custom_daily(array $entries, array $markers, array $sex = [], array $subst = [], array $media = []): array
{
    $D = signals_daily($entries, $markers, $sex, $subst, $media);
    foreach ($D as &$x) { $x['anxiety'] = null; $x['sleep_quality'] = null; $x['exercise'] = null; $x['sex_freq'] = null; $x['sex_online'] = null; $x['sex_diff'] = null; $x['subst_uses'] = 0; $x['subs'] = []; }
    unset($x);
    foreach ($entries as $e) {
        if (isset($e['anxiety'])) { $d = (string)$e['entry_date']; $D[$d]['anxiety'] = max($D[$d]['anxiety'] ?? 0, (int)$e['anxiety']); }
    }
    foreach ($markers as $m) {
        $d = (string)$m['entry_date'];
        if (isset($m['sleep_quality'])) $D[$d]['sleep_quality'] = (int)$m['sleep_quality'];
        if (isset($m['exercise_minutes'])) $D[$d]['exercise'] = (int)$m['exercise_minutes'];
    }
    foreach ($sex as $s) {
        $d = (string)$s['entry_date'];
        if (isset($s['frequency'])) $D[$d]['sex_freq'] = (int)$s['frequency'];
        if (isset($s['online_minutes'])) $D[$d]['sex_online'] = (int)$s['online_minutes'];
        if (isset($s['different_from_usual'])) $D[$d]['sex_diff'] = (int)$s['different_from_usual'];
    }
    foreach ($subst as $u) {
        $d = (string)$u['entry_date'];
        $D[$d]['subst_uses']++;
        $k = (string)$u['substance'];
        $D[$d]['subs'][$k] = ($D[$d]['subs'][$k] ?? 0) + (isset($u['amount']) ? (float)$u['amount'] : 1.0);
    }
    // café registrado em Substâncias vale como cafeína quando o marcador do dia está vazio
    foreach ($D as &$x) if ($x['caffeine'] === null && isset($x['subs']['cafe'])) $x['caffeine'] = (int)round($x['subs']['cafe']);
    unset($x);
    return $D;
}

/**
 * Valor de uma métrica num dia (null = não informado).
 * @param array<string, array<string,mixed>> $D
 */
function custom_value(array $D, string $metric, string $day, ?string $today = null): ?float
{
    $x = $D[$day] ?? null;
    if ($metric === 'logged') {
        $n = (int)($x['n'] ?? 0);
        if ($n > 0) return 1.0;
        return $today !== null && $day >= $today ? null : 0.0; // o dia de hoje ainda não terminou
    }
    if ($x === null) return null;
    switch ($metric) {
        case 'mood_manha': case 'mood_tarde': case 'mood_noite':
            $p = substr($metric, 5);
            return isset($x['moods'][$p]) ? (float)$x['moods'][$p] : null;
        case 'mood_range': return $x['range'] === null ? null : (float)$x['range'];
        case 'med_missed': return (int)($x['doses'] ?? 0) > 0 ? (float)$x['missed'] : null;
        case 'med_pct': return (int)($x['doses'] ?? 0) > 0 ? round(100 * ((int)$x['doses'] - (int)$x['missed']) / (int)$x['doses'], 1) : null;
        case 'signs': case 'media': case 'subst_uses': case 'subst_above': case 'stim': case 'sex_risk':
            return (float)($x[$metric] ?? 0);
    }
    if (strpos($metric, 'sub_') === 0) return (float)($x['subs'][substr($metric, 4)] ?? 0);
    $v = $x[$metric] ?? null;
    return $v === null ? null : (float)$v;
}

function custom_compare(float $v, string $op, float $ref): bool
{
    $eps = 1e-9;
    switch ($op) {
        case '>=': return $v >= $ref - $eps;
        case '<=': return $v <= $ref + $eps;
        case '>': return $v > $ref + $eps;
        case '<': return $v < $ref - $eps;
        default: return abs($v - $ref) < $eps;
    }
}

/**
 * A condição vale no dia $day? Devolve [vale, dias que atenderam].
 * @param array<string, array<string,mixed>> $D
 * @param array<string,mixed> $c
 * @return array{0:bool,1:list<string>}
 */
function custom_condition_on(array $D, array $c, string $day, ?string $today = null): array
{
    $hit = function (string $d) use ($D, $c, $today): bool {
        $v = custom_value($D, (string)$c['metric'], $d, $today);
        return $v !== null && custom_compare($v, (string)$c['op'], (float)$c['value']);
    };
    if ($c['mode'] === 'day') return $hit($day) ? [true, [$day]] : [false, []];
    $n = (int)$c['days'];
    $days = [];
    for ($i = 0; $i < $n; $i++) { $d = sig_add($day, -$i); if ($hit($d)) $days[] = $d; elseif ($c['mode'] === 'streak') return [false, []]; }
    sort($days);
    if ($c['mode'] === 'streak') return [count($days) === $n, $days];
    return [count($days) >= (int)$c['min'], $days];
}

/**
 * A regra dispara no dia? Devolve [dispara, evidências em texto].
 * @param array<string, array<string,mixed>> $D
 * @param array<string,mixed> $rule
 * @return array{0:bool,1:list<string>}
 */
function custom_rule_on(array $D, array $rule, string $day, ?string $today = null): array
{
    $ok = $rule['logic'] === 'and';
    $ev = [];
    foreach ((array)$rule['conditions'] as $c) {
        [$v, $days] = custom_condition_on($D, $c, $day, $today);
        if ($v) $ev[] = custom_describe_condition($c) . ' (' . implode(', ', array_map('sig_dbr', array_slice($days, -7))) . ')';
        if ($rule['logic'] === 'and' && !$v) return [false, []];
        if ($rule['logic'] === 'or' && $v) $ok = true;
    }
    return [$ok, $ok ? $ev : []];
}

/**
 * Prévia: dias em que a regra teria valido (matched) e dias em que teria avisado (fired), respeitando o
 * intervalo mínimo entre avisos. V4.3: "skipped" lista os dias atendidos que NÃO avisariam e o aviso que os segurou.
 * @param array<string, array<string,mixed>> $D
 * @param array<string,mixed> $rule
 * @return array{matched:list<string>, fired:list<string>, skipped:list<array{day:string, after:string, until:string}>, cooldown_days:int}
 */
function custom_preview(array $D, array $rule, string $from, string $to): array
{
    $matched = []; $fired = []; $skipped = []; $last = null;
    $cool = max(1, (int)($rule['cooldown_days'] ?? 3));
    for ($d = $from; $d <= $to; $d = sig_add($d, 1)) {
        if (!custom_rule_on($D, $rule, $d, $to)[0]) continue;
        $matched[] = $d;
        if ($last === null || $d >= sig_add($last, $cool)) { $fired[] = $d; $last = $d; }
        else $skipped[] = ['day' => $d, 'after' => $last, 'until' => sig_add($last, $cool - 1)];
    }
    return ['matched' => $matched, 'fired' => $fired, 'skipped' => $skipped, 'cooldown_days' => $cool];
}

/**
 * V4.3: assinatura das condições de uma regra (ignora nome, ordem das condições, gravidade e canais).
 * Duas regras com a mesma assinatura avisariam exatamente nos mesmos dias.
 * @param array<string,mixed> $rule
 */
function custom_rule_signature(array $rule): string
{
    $parts = [];
    foreach ((array)($rule['conditions'] ?? []) as $c) {
        if (!is_array($c)) continue;
        $mode = (string)($c['mode'] ?? 'day');
        $parts[] = implode('|', [(string)($c['metric'] ?? ''), (string)($c['op'] ?? ''), rtrim(rtrim(sprintf('%.4F', (float)($c['value'] ?? 0)), '0'), '.'),
            $mode, $mode === 'day' ? 1 : (int)($c['days'] ?? 1), $mode === 'count' ? (int)($c['min'] ?? 1) : 0]);
    }
    $parts = array_values(array_unique($parts));
    sort($parts);
    $logic = count($parts) > 1 && ($rule['logic'] ?? 'and') === 'or' ? 'or' : 'and';
    return $logic . '#' . implode(';', $parts);
}

/**
 * V4.3: regra já existente com as mesmas condições (exceto a própria, ao editar).
 * @param list<array<string,mixed>> $existing
 * @param array<string,mixed> $rule
 * @return array<string,mixed>|null
 */
function custom_find_duplicate(array $existing, array $rule, int $exceptId = 0): ?array
{
    $sig = custom_rule_signature($rule);
    foreach ($existing as $r) {
        if ((int)($r['id'] ?? 0) === $exceptId && $exceptId > 0) continue;
        if (custom_rule_signature($r) === $sig) return $r;
    }
    return null;
}

/**
 * Pode gravar um novo aviso da regra no dia $day? (um por dia e respeitando o intervalo de N dias).
 * @param list<string> $previous datas (period_end) dos avisos anteriores da regra
 */
function custom_can_fire(array $previous, string $day, int $cooldownDays): bool
{
    foreach ($previous as $p) {
        if ($p === $day) return false;
        if ($p < $day && $day < sig_add($p, max(1, $cooldownDays))) return false;
        if ($p > $day) return false; // já avisou num dia mais recente
    }
    return true;
}

/* ------------------------------------------------------------------ banco */

/**
 * Regra do banco → array normalizado.
 * @param array<string,mixed> $r
 * @return array<string,mixed>
 */
function custom_rule_from_row(array $r): array
{
    $conds = json_decode((string)$r['conditions_json'], true);
    return ['id' => (int)$r['id'], 'version' => (int)($r['version'] ?? 1), 'name' => (string)$r['name'], 'logic' => $r['logic'] === 'or' ? 'or' : 'and', 'conditions' => is_array($conds) ? $conds : [],
        'severity' => (int)$r['severity'], 'notify_push' => (bool)$r['notify_push'], 'notify_email' => (bool)$r['notify_email'],
        'cooldown_days' => (int)$r['cooldown_days'], 'is_private' => (int)$r['is_private'], 'active' => (bool)$r['active'],
        'template' => $r['template'], 'last_fired_on' => $r['last_fired_on']];
}

/**
 * @param array<string,mixed> $u
 * @return list<array<string,mixed>>
 */
function custom_rules(PDO $pdo, array $u, bool $onlyActive = false): array
{
    $st = $pdo->prepare('SELECT * FROM alert_rules WHERE user_id = ?' . ($onlyActive ? ' AND active = 1' : '') . ' ORDER BY id');
    $st->execute([$u['id']]);
    return array_map('custom_rule_from_row', $st->fetchAll());
}

/**
 * Carrega a série do usuário entre $from e $to (inclui 30 dias antes para contagens e sequências).
 * Sexualidade e Substâncias só entram com o módulo ligado.
 * @param array<string,mixed> $u
 * @return array<string, array<string,mixed>>
 */
function custom_load(PDO $pdo, array $u, string $from, string $to): array
{
    $base = sig_add($from, -30);
    $prefs = user_prefs($u);
    $q = function (string $sql) use ($pdo, $u, $base, $to): array { $st = $pdo->prepare($sql); $st->execute([$u['id'], $base, $to]); return $st->fetchAll(); };
    $entries = $q('SELECT entry_date, period, mood, sleep_hours, energy, irritability, anxiety, medication_taken FROM entries WHERE user_id = ? AND entry_date BETWEEN ? AND ?');
    $markers = $q('SELECT * FROM daily_markers WHERE user_id = ? AND entry_date BETWEEN ? AND ?');
    $sex = $prefs['modules']['sexualidade'] ? $q('SELECT entry_date, libido, activities, frequency, online_minutes, different_from_usual FROM sexuality_log WHERE user_id = ? AND entry_date BETWEEN ? AND ?') : [];
    $sub = $prefs['modules']['substancias'] ? $q('SELECT entry_date, substance, amount, above_usual FROM substance_log WHERE user_id = ? AND entry_date BETWEEN ? AND ?') : [];
    $media = [];
    foreach ($q('SELECT DATE(created_at) d, COUNT(*) n FROM media WHERE user_id = ? AND created_at BETWEEN ? AND ? + INTERVAL 1 DAY GROUP BY DATE(created_at)') as $r) $media[(string)$r['d']] = (int)$r['n'];
    return custom_daily($entries, $markers, $sex, $sub, $media);
}

/** @param array<string,mixed> $u */
function custom_today(array $u): string
{
    try { $tz = new DateTimeZone((string)$u['timezone']); } catch (Exception $e) { $tz = new DateTimeZone('America/Sao_Paulo'); }
    return (new DateTime('now', $tz))->format('Y-m-d');
}

/**
 * Avalia as regras ativas nos dias pedidos (padrão: hoje e ontem) e grava os avisos novos.
 * Com $deliver = true entrega na hora (push/e-mail genéricos); senão ficam pendentes para o cron.
 * Regras de um módulo privado desligado ficam sem efeito.
 * @param array<string,mixed> $u
 * @param list<string>|null $days
 * @return list<int> ids dos avisos novos
 */
function custom_eval_user(PDO $pdo, array $u, bool $deliver = true, ?string $today = null, ?array $days = null): array
{
    $rules = custom_rules($pdo, $u, true);
    if (!$rules) return [];
    $today = $today ?? custom_today($u);
    $days = $days ?? [sig_add($today, -1), $today];
    $days = array_values(array_unique(array_filter($days, function (string $d) use ($today): bool { return valid_iso_date($d) && $d <= $today && $d >= sig_add($today, -7); })));
    if (!$days) return [];
    sort($days);
    $mods = user_prefs($u)['modules'];
    $D = custom_load($pdo, $u, $days[0], $today);
    $ids = [];
    foreach ($rules as $r) {
        if (($r['is_private'] & CUSTOM_PRIV_SEX) && !$mods['sexualidade']) continue;
        if (($r['is_private'] & CUSTOM_PRIV_SUBST) && !$mods['substancias']) continue;
        $st = $pdo->prepare('SELECT period_end FROM alerts WHERE user_id = ? AND custom_rule_id = ? AND period_end >= ?');
        $st->execute([$u['id'], $r['id'], sig_add($days[0], -15)]);
        $prev = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        foreach ($days as $d) {
            [$on, $ev] = custom_rule_on($D, $r, $d, $today);
            if (!$on || !custom_can_fire($prev, $d, (int)$r['cooldown_days'])) continue;
            $id = custom_store($pdo, (int)$u['id'], $r, $d, $ev, !$deliver);
            $prev[] = $d;
            $ids[] = $id;
            if ($deliver) custom_deliver($pdo, $u, $id, $r);
        }
    }
    return $ids;
}

/**
 * @param array<string,mixed> $r
 * @param list<string> $ev
 */
function custom_store(PDO $pdo, int $uid, array $r, string $day, array $ev, bool $pending): int
{
    $from = $day;
    foreach ($r['conditions'] as $c) if ($c['mode'] !== 'day') $from = min($from, sig_add($day, -((int)$c['days'] - 1)));
    $txt = 'O alerta que você criou, “' . $r['name'] . '”, foi atendido: ' . implode('; ', $ev) . '. Isto não é diagnóstico — é o lembrete que você mesmo(a) pediu. Se fizer sentido, siga seu plano e fale com sua equipe.';
    $pdo->prepare('INSERT INTO alerts (user_id, rule_code, kind, severity, period_start, period_end, title, explanation, evidence_json, custom_rule_id, is_private, pending_delivery) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$uid, 'personalizado', 'personalizado', $r['severity'], $from, $day, 'Alerta personalizado: ' . $r['name'], $txt, json_encode($ev, JSON_UNESCAPED_UNICODE), $r['id'], $r['is_private'], ($pending && ($r['notify_push'] || $r['notify_email'])) ? 1 : 0]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE alert_rules SET last_fired_on = ? WHERE id = ? AND (last_fired_on IS NULL OR last_fired_on < ?)')->execute([$day, $r['id'], $day]);
    return $id;
}

/** Texto genérico (nunca com detalhes): aparece na tela bloqueada e na caixa de entrada. */
const CUSTOM_PUSH_TEXT = 'Um dos alertas que você criou foi atendido. Toque para ver no app.';

/**
 * Entrega push/e-mail de um aviso personalizado. Pessoas de apoio NUNCA recebem esses avisos (só contagem no relatório, com opt-in).
 * @param array<string,mixed> $u
 * @param array<string,mixed> $r
 */
function custom_deliver(PDO $pdo, array $u, int $alertId, array $r): void
{
    require_once APP_ROOT . '/lib/webpush.php';
    require_once APP_ROOT . '/lib/smtp.php';
    $app = rtrim((string)cfg('app_url'), '/');
    if (!empty($r['notify_push'])) {
        $n = push_to_user((int)$u['id'], ['title' => 'Bipolar', 'body' => CUSTOM_PUSH_TEXT, 'url' => '/?aba=historico#alertas', 'tag' => 'alerta-personalizado']);
        if ($n > 0) $pdo->prepare('UPDATE alerts SET notified_push = 1 WHERE id = ?')->execute([$alertId]);
    }
    if (!empty($r['notify_email']) && !empty($u['email'])) {
        $ok = send_mail((string)$u['email'], 'Um alerta que você criou foi atendido', email_layout('Um alerta que você criou foi atendido',
            '<p>Um dos alertas personalizados do seu diário foi atendido. Por privacidade, os detalhes ficam só no aplicativo.</p>'
            . '<p><a href="' . e($app) . '/?aba=historico#alertas" style="display:inline-block;background:#2f6f73;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none">Ver no aplicativo</a></p>'
            . '<p style="font-size:12px;color:#50606c">Você pode pausar ou mudar este alerta em Histórico → Meus alertas personalizados.</p>'), '', 'alerta_personalizado');
        if ($ok) $pdo->prepare('UPDATE alerts SET notified_email = 1 WHERE id = ?')->execute([$alertId]);
    }
    $pdo->prepare('UPDATE alerts SET pending_delivery = 0 WHERE id = ?')->execute([$alertId]);
}

/**
 * Logo depois de salvar dados de $date: avalia as regras (esse dia e hoje). Os avisos ficam gravados na hora;
 * push/e-mail saem depois da resposta (quando o servidor permite) para não atrasar o salvamento.
 * @param array<string,mixed> $u
 */
function custom_after_save(PDO $pdo, array $u, string $date): int
{
    try {
        $ids = custom_eval_user($pdo, $u, false, null, [$date, custom_today($u)]);
    } catch (Throwable $e) { error_log('alertas personalizados (após salvar): ' . $e->getMessage()); return 0; }
    if ($ids) {
        register_shutdown_function(function () use ($pdo, $u, $ids): void {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
            try { custom_deliver_pending($pdo, $u, $ids); } catch (Throwable $e) { error_log('alertas personalizados (entrega): ' . $e->getMessage()); }
        });
    }
    return count($ids);
}

/**
 * Entrega avisos personalizados pendentes (do usuário; opcionalmente só os ids dados).
 * @param array<string,mixed> $u
 * @param list<int>|null $ids
 */
function custom_deliver_pending(PDO $pdo, array $u, ?array $ids = null): int
{
    $st = $pdo->prepare('SELECT a.id AS alert_id, r.* FROM alerts a JOIN alert_rules r ON r.id = a.custom_rule_id WHERE a.user_id = ? AND a.pending_delivery = 1 AND a.created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY');
    $st->execute([$u['id']]);
    $n = 0;
    foreach ($st->fetchAll() as $row) {
        $alertId = (int)$row['alert_id'];
        if ($ids !== null && !in_array($alertId, $ids, true)) continue;
        // marca antes de enviar: duas execuções simultâneas não mandam em dobro
        $up = $pdo->prepare('UPDATE alerts SET pending_delivery = 0 WHERE id = ? AND pending_delivery = 1');
        $up->execute([$alertId]);
        if ($up->rowCount() === 0) continue;
        custom_deliver($pdo, $u, $alertId, custom_rule_from_row($row));
        $n++;
    }
    return $n;
}

/**
 * Contagem de avisos personalizados no período para o relatório de uma pessoa de apoio (só número, nunca nomes ou motivos).
 * $allowedMask: quais privados podem entrar na conta (1 sexualidade, 2 substâncias), conforme o que o destinatário recebe.
 */
function custom_count_for_report(PDO $pdo, int $uid, string $from, string $to, int $allowedMask): int
{
    $st = $pdo->prepare('SELECT is_private FROM alerts WHERE user_id = ? AND custom_rule_id IS NOT NULL AND period_end BETWEEN ? AND ?');
    $st->execute([$uid, $from, $to]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) if (((int)$p & ~$allowedMask) === 0) $n++;
    return $n;
}

/** Linha do relatório por e-mail: só a contagem (V4.2). */
function custom_count_html(int $n): string
{
    return '<h2 style="font-size:16px;margin:28px 0 8px">Alertas personalizados</h2><p style="font-size:14px">'
        . ($n ? e(plural_br($n, 'alerta personalizado foi atendido', 'alertas personalizados foram atendidos')) . ' no período.' : 'Nenhum alerta personalizado foi atendido no período.')
        . ' <span style="color:#50606c">Por privacidade, enviamos só a quantidade — nunca quais são os alertas ou os motivos.</span></p>';
}
