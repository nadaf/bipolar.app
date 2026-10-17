<?php
require_once __DIR__ . '/ai_providers.php';
/**
 * "Reflexões com IA" — companheiro de reflexão opcional. V4.3: provedor à escolha da pessoa, com a chave dela (BYOK),
 * ver app/lib/ai_providers.php. Desligado sem chave. Por pessoa: consentimento explícito (LGPD art. 11, dado sensível de saúde) e escolha das
 * categorias enviadas. Sexualidade, substâncias e anotações ficam FORA por padrão.
 * Segurança: prompt proíbe diagnóstico e mudança de medicação; frases de crise são detectadas localmente
 * (sem chamar a API) e mostram CVV 188 / SAMU 192; limite por hora e teto de custo mensal por pessoa.
 */

const AI_CATEGORIES = [
    'humor' => ['Humor por período', true],
    'sono' => ['Sono (horas e horários)', true],
    'energia' => ['Energia, irritabilidade e ansiedade', true],
    'marcadores' => ['Marcadores do dia (sol, cafeína, álcool, pensamentos/fala acelerados, gastos, estresse)', true],
    'medicacao' => ['Medicação tomada / doses esquecidas (sem nomes de remédios)', true],
    'alertas' => ['Alertas automáticos do app', true],
    'anotacoes' => ['Minhas anotações e etiquetas (texto livre)', false],
    'sexualidade' => ['Sexualidade (resumo)', false],
    'substancias' => ['Substâncias (resumo)', false],
    'alimentacao' => ['Alimentação por período (doces, ultraprocessados, comer fora de hora)', false],
];
const AI_CRISIS_MESSAGE = 'Percebi que você pode estar passando por um momento muito difícil. Você não precisa enfrentar isso sozinho(a). '
    . 'Ligue agora para o CVV — 188 (24 horas, gratuito, também por chat em cvv.org.br) ou, se houver risco imediato, SAMU — 192. '
    . 'Se puder, chame alguém de confiança para ficar com você. Por segurança, esta conversa com a IA foi pausada.';

/** A IA pode ser oferecida neste servidor? (chave do servidor para cifrar as chaves das pessoas, ou chave do operador) */
function ai_configured(): bool
{
    return ai_byok_available() || ai_operator_fallback() !== null;
}
/**
 * A pessoa já pode usar a IA? (tem chave própria válida ou há chave do operador)
 * @param array<string,mixed> $u
 */
function ai_ready(array $u): bool { return ai_credential($u) !== null; }

/**
 * Lê 'ai.<k>' e, se ausente, o bloco antigo 'xai.<k>' (V4–V4.2).
 * @param mixed $default
 * @return mixed
 */
function ai_cfg(string $k, $default): mixed
{
    $v = cfg('ai.' . $k, null);
    return $v !== null ? $v : cfg('xai.' . $k, $default);
}
/** @return array{price_in:float,price_out:float,cap:float,per_hour:int,max_tokens:int,reasoning_effort:string,timeout:int} */
function ai_config(): array
{
    $effort = strtolower(trim((string)ai_cfg('reasoning_effort', 'low')));
    return [
        // modelos de raciocínio pensam antes de responder; "high" pode levar dezenas de segundos
        'reasoning_effort' => in_array($effort, ['low', 'medium', 'high', 'xhigh', 'none', ''], true) ? $effort : 'low',
        'timeout' => max(10, min(120, (int)ai_cfg('timeout_seconds', 60))),
        // preços e teto mensal só valem para a chave compartilhada do operador; com chave própria quem paga é a pessoa
        'price_in' => (float)ai_cfg('price_in_per_mtok', 2.00),
        'price_out' => (float)ai_cfg('price_out_per_mtok', 6.00),
        'cap' => (float)ai_cfg('monthly_cap_usd', 2.00),
        'per_hour' => max(1, (int)ai_cfg('requests_per_hour', 20)),
        'max_tokens' => max(100, min(4000, (int)ai_cfg('max_output_tokens', 900))),
    ];
}
/**
 * @param array<string,mixed> $u
 * @return array{consent:bool,consent_at:?string,categories:list<string>}
 */
function ai_settings(array $u): array
{
    $p = json_decode((string)($u['prefs'] ?? ''), true);
    $a = is_array($p) && is_array($p['ai'] ?? null) ? $p['ai'] : [];
    $cats = isset($a['categories']) && is_array($a['categories'])
        ? array_values(array_intersect(array_keys(AI_CATEGORIES), array_map('strval', $a['categories'])))
        : array_keys(array_filter(AI_CATEGORIES, function (array $c): bool { return $c[1]; }));
    return ['consent' => !empty($a['consent']), 'consent_at' => isset($a['consent_at']) ? (string)$a['consent_at'] : null, 'categories' => $cats];
}

/** Remove acentos e baixa a caixa para a detecção de crise. */
function ai_fold(string $s): string
{
    $s = mb_strtolower($s);
    return strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
}
/** Detecção local (antes de qualquer chamada) de linguagem de crise/risco de suicídio ou autolesão. */
function ai_detect_crisis(string $text): bool
{
    $t = ai_fold($text);
    $p = [
        '/\b(me|vou me|quero me|pensando em me|penso em me) (matar|suicidar|enforcar|jogar|cortar|machucar|ferir)\b/',
        '/\bsuicid/', '/\bautoles/', '/\bautomutila/',
        '/\b(tirar|acabar com|dar fim (a|na)) (a )?(minha )?(propria )?vida\b/',
        '/\bnao (quero|aguento) mais (viver|existir)\b/', '/\bquero (morrer|sumir para sempre|desaparecer para sempre)\b/',
        '/\bmelhor (seria )?(eu )?(morto|morta|sem mim)\b/', '/\b(tomar|tomei) (todos os|todas as|a cartela|o vidro)/', '/\boverdose\b/',
        '/\bnao vale a pena (viver|continuar)\b/', '/\bkill myself\b/', '/\bsuicide\b/',
    ];
    foreach ($p as $re) if (preg_match($re, $t)) return true;
    return false;
}

function ai_system_prompt(string $name): string
{
    return "Você é \"Reflexões com IA\", um companheiro de reflexão dentro de um diário de humor para pessoas com transtorno bipolar, no Brasil. "
        . "Fale em português do Brasil, com acolhimento, frases curtas e linguagem simples, sem julgamento. Trate a pessoa por " . ($name !== '' ? $name : 'você') . ".\n"
        . "REGRAS OBRIGATÓRIAS:\n"
        . "1. Nunca faça diagnóstico, nem diga que a pessoa está ou não em mania, hipomania, depressão ou estado misto; fale em \"sinais\" e \"padrões\".\n"
        . "2. Nunca sugira iniciar, parar, trocar, aumentar ou reduzir medicação ou dose — isso é sempre decisão com o psiquiatra. Se perguntarem, diga isso com gentileza.\n"
        . "3. Se houver qualquer menção a suicídio, autolesão ou risco imediato, responda só com acolhimento e os contatos CVV 188 (24h) e SAMU 192.\n"
        . "4. Use só os dados fornecidos; não invente números. Se faltar dado, diga que falta.\n"
        . "5. Você não substitui psiquiatra, psicólogo ou CAPS. Incentive levar as observações para a equipe.\n"
        . "6. Não peça nem repita dados de identificação (nome completo, documentos, endereço).";
}

/**
 * Resume os dados das categorias autorizadas (últimos $days dias) num texto compacto, sem identificação.
 * @param array<string,mixed> $u
 * @param list<string> $cats
 */
function ai_context(PDO $pdo, array $u, array $cats, int $days = 14): string
{
    $tz = new DateTimeZone((string)$u['timezone']);
    $to = (new DateTime('now', $tz))->format('Y-m-d');
    // V4.2: "últimos N dias" = N dias contando hoje (antes eram N + 1)
    $from = (new DateTime('now', $tz))->modify('-' . max(0, $days - 1) . ' days')->format('Y-m-d');
    $q = function (string $sql) use ($pdo, $u, $from, $to): array { $st = $pdo->prepare($sql); $st->execute([$u['id'], $from, $to]); return $st->fetchAll(); };
    $in = function (string $c) use ($cats): bool { return in_array($c, $cats, true); };
    $lines = ["Período: $from a $to (escala de humor de −3 depressão grave a +3 mania; 0 estável; energia/irritabilidade/ansiedade 0–3)."];
    $byDay = [];
    foreach ($q('SELECT entry_date, period, mood, sleep_hours, energy, irritability, anxiety, medication_taken, note, tags FROM entries WHERE user_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date, period') as $e) {
        $p = [];
        if ($in('humor')) $p[] = $e['period'] . ' humor ' . $e['mood'];
        if ($in('sono') && $e['sleep_hours'] !== null) $p[] = 'sono ' . $e['sleep_hours'] . 'h';
        if ($in('energia')) foreach (['energy' => 'energia', 'irritability' => 'irrit', 'anxiety' => 'ansied'] as $k => $l) if ($e[$k] !== null) $p[] = "$l {$e[$k]}";
        if ($in('medicacao') && $e['medication_taken'] !== null) $p[] = 'medicação ' . ((int)$e['medication_taken'] ? 'tomada' : 'NÃO tomada');
        if ($in('anotacoes') && trim((string)$e['note'] . (string)$e['tags']) !== '') $p[] = 'nota: "' . mb_substr(trim((string)$e['note'] . ' ' . (string)$e['tags']), 0, 200) . '"';
        if ($p) $byDay[(string)$e['entry_date']][] = implode(', ', $p);
    }
    if ($in('sono') || $in('marcadores') || $in('medicacao')) {
        foreach ($q('SELECT * FROM daily_markers WHERE user_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date') as $m) {
            $p = [];
            if ($in('sono') && $m['bed_time'] && $m['wake_time']) $p[] = "deitou {$m['bed_time']} acordou {$m['wake_time']}";
            if ($in('marcadores')) {
                foreach (['sun_minutes' => 'sol min', 'caffeine_cups' => 'cafeína', 'alcohol_units' => 'álcool doses', 'racing_thoughts' => 'pensamentos acelerados', 'fast_speech' => 'fala acelerada', 'impulsive_spending' => 'gastos impulsivos', 'irritability_day' => 'irritabilidade', 'stress_level' => 'estresse'] as $k => $l) if ($m[$k] !== null) $p[] = "$l {$m[$k]}";
            }
            if ($in('medicacao')) { $d = json_decode((string)$m['doses_json'], true); if (is_array($d) && $d) $p[] = 'doses tomadas ' . array_sum($d) . '/' . count($d); }
            if ($p) $byDay[(string)$m['entry_date']][] = implode(', ', $p);
        }
    }
    $prefs = user_prefs($u);
    if ($in('alimentacao') && $prefs['modules']['alimentacao']) { // V4.7: categoria própria, fora por padrão
        $lvl = ['nenhum', 'pouco', 'moderado', 'muito'];
        try {
            foreach ($q('SELECT entry_date, period, food_sweets, food_processed, food_binge FROM period_markers WHERE user_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date, period') as $f) {
                $p = [];
                if ($f['food_sweets'] !== null) $p[] = 'doces ' . ($lvl[(int)$f['food_sweets']] ?? $f['food_sweets']);
                if ($f['food_processed'] !== null) $p[] = 'ultraprocessados ' . ($lvl[(int)$f['food_processed']] ?? $f['food_processed']);
                if ((int)$f['food_binge'] === 1) $p[] = 'comeu fora de hora/compulsão';
                if ($p) $byDay[(string)$f['entry_date']][] = $f['period'] . ' alimentação: ' . implode(', ', $p);
            }
        } catch (PDOException $e) { /* migração V4.7 pendente */ }
    }
    if ($in('sexualidade') && $prefs['modules']['sexualidade']) {
        foreach ($q('SELECT entry_date, libido, different_from_usual FROM sexuality_log WHERE user_id = ? AND entry_date BETWEEN ? AND ?') as $s) {
            $byDay[(string)$s['entry_date']][] = 'desejo sexual ' . ($s['libido'] ?? '?') . ' (−2..+2 vs habitual)' . ((int)$s['different_from_usual'] ? ', diferente do habitual' : '');
        }
    }
    if ($in('substancias') && $prefs['modules']['substancias']) {
        foreach ($q('SELECT entry_date, substance, amount, above_usual FROM substance_log WHERE user_id = ? AND entry_date BETWEEN ? AND ?') as $s) {
            $byDay[(string)$s['entry_date']][] = (SUBSTANCES[$s['substance']][0] ?? $s['substance']) . ' ' . ($s['amount'] ?? '') . ((int)$s['above_usual'] ? ' (acima do habitual)' : '');
        }
    }
    ksort($byDay);
    foreach ($byDay as $d => $p) $lines[] = "$d: " . implode(' | ', $p);
    if ($in('alertas')) {
        $st = $pdo->prepare('SELECT created_at, title, severity, feedback, is_private FROM alerts WHERE user_id = ? AND created_at >= ? ORDER BY created_at');
        $st->execute([$u['id'], $from]);
        $okMask = ($in('sexualidade') ? 1 : 0) | ($in('substancias') ? 2 : 0);
        foreach ($st->fetchAll() as $a) if (((int)($a['is_private'] ?? 0) & ~$okMask) === 0) $lines[] = 'Alerta do app em ' . substr((string)$a['created_at'], 0, 10) . ': ' . $a['title'] . ' (nível ' . $a['severity'] . ')' . ($a['feedback'] ? ', a pessoa disse que ' . ($a['feedback'] === 'sim' ? 'faz sentido' : 'não faz sentido') : '');
    }
    if (count($lines) === 1) $lines[] = 'Sem registros nas categorias autorizadas.';
    if ($in('humor')) {
        $f = ai_facts($pdo, $u, $from, $to);
        $lines[] = 'FATOS (contagens exatas; use só estes números): ' . plural_br($f['days'], 'dia', 'dias') . ' com registro de humor'
            . ($f['dates'] ? ' (' . implode(', ', $f['dates']) . ')' : '') . '; ' . plural_br($f['entries'], 'registro', 'registros') . ' de humor no total.';
    }
    return implode("\n", $lines);
}

/**
 * V4.2: contagens exatas do período (para o resumo da semana bater com a lista de dias).
 * @param array<string,mixed> $u
 * @return array{days:int, entries:int, dates:list<string>}
 */
function ai_facts(PDO $pdo, array $u, string $from, string $to): array
{
    $st = $pdo->prepare('SELECT entry_date, COUNT(*) n FROM entries WHERE user_id = ? AND entry_date BETWEEN ? AND ? GROUP BY entry_date ORDER BY entry_date');
    $st->execute([$u['id'], $from, $to]);
    $dates = []; $n = 0;
    foreach ($st->fetchAll() as $r) { $dates[] = substr((string)$r['entry_date'], 8, 2) . '/' . substr((string)$r['entry_date'], 5, 2); $n += (int)$r['n']; }
    return ['days' => count($dates), 'entries' => $n, 'dates' => $dates];
}

/**
 * Linha fixa no fim do resumo da semana: o número sempre bate com a lista.
 * @param array{days:int, entries:int, dates:list<string>} $f
 */
function ai_facts_line(array $f): string
{
    if (!$f['days']) return 'Nesta semana não há registros de humor.';
    return 'Dias com registro nesta semana (' . $f['days'] . '): ' . implode(', ', $f['dates']) . ' · ' . plural_br($f['entries'], 'registro', 'registros') . ' de humor.';
}

/**
 * Custo em US$ a partir do uso informado pela API.
 * @param array<string,mixed> $usage
 */
function ai_cost(array $usage, bool $operator = true): float
{
    if (!$operator) return 0.0; // chave própria: quem paga é a pessoa, direto ao provedor
    $c = ai_config();
    return ((int)($usage['prompt_tokens'] ?? 0) * $c['price_in'] + (int)($usage['completion_tokens'] ?? 0) * $c['price_out']) / 1e6;
}
function ai_month_cost(PDO $pdo, int $uid): float
{
    $st = $pdo->prepare('SELECT cost_usd FROM ai_usage WHERE user_id = ? AND month = ?');
    $st->execute([$uid, gmdate('Y-m')]);
    return (float)$st->fetchColumn();
}
/** @param array<string,mixed> $usage */
function ai_record_usage(PDO $pdo, int $uid, array $usage, bool $operator = true): void
{
    $pdo->prepare('INSERT INTO ai_usage (user_id, month, requests, input_tokens, output_tokens, cost_usd) VALUES (?,?,1,?,?,?)
        ON DUPLICATE KEY UPDATE requests = requests + 1, input_tokens = input_tokens + VALUES(input_tokens), output_tokens = output_tokens + VALUES(output_tokens), cost_usd = cost_usd + VALUES(cost_usd)')
        ->execute([$uid, gmdate('Y-m'), (int)($usage['prompt_tokens'] ?? 0), (int)($usage['completion_tokens'] ?? 0), round(ai_cost($usage, $operator), 4)]);
}

/**
 * Chama o provedor da credencial (padrão: chave do operador). $transport (para testes) recebe (url, headers, body)
 * e devolve {status, body, error}.
 * @param list<array{role:string,content:string}> $messages
 * @param array{provider:string,key:string,model:string,base_url:string,operator?:bool}|null $cred
 * @return array{text:string,usage:array<string,mixed>,model:string,provider:string}
 */
function ai_chat(array $messages, ?callable $transport = null, ?array $cred = null, ?int $maxTokens = null): array
{
    $c = ai_config();
    $cred = $cred ?? ai_operator_fallback();
    if ($cred === null) throw new AiError(ai_user_error('chave', true), 'chave', 0);
    $own = empty($cred['operator']);
    if ($cred['provider'] === 'custom' || $cred['base_url'] !== '') {
        $prob = $own ? ai_base_url_problem($cred['base_url']) : null;
        if ($prob !== null) throw new AiError($prob, 'rede', 0);
    }
    $req = ai_build_request($cred, $messages, $maxTokens ?? $c['max_tokens'], $c['reasoning_effort']);
    $timeout = $c['timeout'];
    if (function_exists('set_time_limit')) @set_time_limit($timeout + 15);
    $transport = $transport ?? ($GLOBALS['AI_TRANSPORT'] ?? null) ?? function (string $url, array $h, string $b) use ($timeout): array { return bp_http_request('POST', $url, $h, $b, $timeout); };
    $r = $transport($req['url'], $req['headers'], $req['body']);
    $j = json_decode((string)($r['body'] ?? ''), true);
    $status = (int)($r['status'] ?? 0);
    $out = ai_parse_response($cred['provider'], is_array($j) ? $j : null);
    if ($status !== 200 || !is_array($j) || $out['text'] === '') {
        $kind = ai_error_kind_full($status, (string)($r['body'] ?? ''), (string)($r['error'] ?? ''), (int)($r['errno'] ?? 0), is_array($j), $out['text'] === '');
        ai_log_error($cred, $r, $kind, $out['finish']);
        throw new AiError(ai_user_error($kind, $own), $kind, $status);
    }
    return ['text' => $out['text'], 'usage' => $out['usage'], 'model' => $cred['model'], 'provider' => $cred['provider']];
}

/** Compatibilidade: tipo do erro só pelo status (sem corpo). */
function ai_error_kind(int $status, string $curlError, int $errno, bool $json, bool $empty): string
{
    return ai_error_kind_full($status, '', $curlError, $errno, $json, $empty);
}
final class AiError extends RuntimeException
{
    public string $kind;
    public int $httpStatus;
    public function __construct(string $msg, string $kind, int $httpStatus)
    {
        parent::__construct($msg);
        $this->kind = $kind;
        $this->httpStatus = $httpStatus;
    }
}
/**
 * Registra a falha em storage/logs/ai.log (e no error_log do PHP) sem segredos: provedor, status, tipo, tempo,
 * erro do cURL e o início do corpo devolvido pelo provedor, com qualquer chave removida.
 * Nunca registra a chave, as mensagens enviadas nem dados de saúde.
 * @param array<string,mixed> $cred
 * @param array<string,mixed> $r
 */
function ai_log_error(array $cred, array $r, string $kind, string $finish): void
{
    $key = (string)($cred['key'] ?? '');
    $bodyTxt = ai_redact(preg_replace('/\s+/', ' ', substr((string)($r['body'] ?? ''), 0, 600)) ?? '', $key);
    $line = gmdate('c') . ' IA provedor=' . preg_replace('/[^a-z]/', '', (string)($cred['provider'] ?? '')) . (empty($cred['operator']) ? ' chave=propria' : ' chave=operador')
        . ' erro=' . $kind . ' http=' . (int)($r['status'] ?? 0) . ' ms=' . (int)($r['ms'] ?? 0)
        . ' modelo=' . preg_replace('/[^A-Za-z0-9._:\/+@-]/', '', (string)($cred['model'] ?? ''))
        . ($finish !== '' ? ' finish=' . preg_replace('/[^A-Za-z_]/', '', $finish) : '')
        . ((string)($r['error'] ?? '') !== '' ? ' curl=' . ai_redact((string)$r['error'], $key) : '') . ' corpo=' . $bodyTxt;
    if (PHP_SAPI !== 'cli') error_log($line);
    $dir = STORAGE_DIR . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    @file_put_contents($dir . '/ai.log', $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * "Testar chave": pergunta curtíssima. Devolve [ok, mensagem].
 * @param array{provider:string,key:string,model:string,base_url:string} $cred
 * @return array{0:bool,1:string}
 */
function ai_test_key(array $cred, ?callable $transport = null): array
{
    try {
        $r = ai_chat([['role' => 'user', 'content' => 'Responda apenas: ok']], $transport, $cred + ['operator' => false], 16);
        return [true, 'Chave funcionando com ' . (ai_providers()[$cred['provider']]['label'] ?? $cred['provider']) . ' (modelo ' . $r['model'] . ').'];
    } catch (AiError $e) {
        return [false, $e->getMessage()];
    }
}

/**
 * Extrai a proposta de registro que a IA anexa no check-in: <<REGISTRO {...}>>. Valida faixas.
 * @return array{0:string,1:?array<string,mixed>} [texto sem o bloco, proposta]
 */
function ai_extract_entry(string $text): array
{
    if (!preg_match('/<<REGISTRO\s*(\{.*?\})\s*>>/s', $text, $m)) return [$text, null];
    $clean = trim(str_replace($m[0], '', $text));
    $j = json_decode($m[1], true);
    if (!is_array($j) || !isset($j['mood']) || !is_numeric($j['mood']) || (int)$j['mood'] < -3 || (int)$j['mood'] > 3) return [$clean, null];
    $e = ['mood' => (int)$j['mood']];
    if (isset($j['period']) && isset(PERIODS[$j['period']])) $e['period'] = (string)$j['period'];
    if (isset($j['sleep_hours']) && is_numeric($j['sleep_hours']) && $j['sleep_hours'] >= 0 && $j['sleep_hours'] <= 24) $e['sleep_hours'] = round((float)$j['sleep_hours'] * 2) / 2;
    foreach (['energy', 'irritability', 'anxiety'] as $k) if (isset($j[$k]) && is_numeric($j[$k]) && $j[$k] >= 0 && $j[$k] <= 3) $e[$k] = (int)$j[$k];
    if (isset($j['note']) && is_string($j['note']) && trim($j['note']) !== '') $e['note'] = mb_substr(trim($j['note']), 0, 500);
    return [$clean, $e];
}

/** Instruções de cada recurso. */
function ai_task_prompt(string $task): string
{
    switch ($task) {
        case 'resumo': return 'Escreva um resumo reflexivo da semana (últimos 7 dias) em até 180 palavras: 1) como o humor, o sono e a energia andaram; 2) um ou dois padrões que chamam atenção (com os dados); 3) algo que foi bem; 4) uma pergunta gentil para a pessoa refletir. Sem diagnóstico, sem conselhos de medicação. '
            . 'Ao dizer quantos dias ou registros houve, use exatamente os números da linha FATOS. Se listar dias, liste exatamente os dias da linha FATOS, sem acrescentar nem tirar nenhum; prefira não repetir a lista (o app já mostra a lista no final).';
        case 'explicar': return 'Explique em linguagem simples, em até 150 palavras, por que o app gerou o alerta abaixo, quais dados pesaram e o que costuma ajudar em geral (sono regular, rotina, falar com a equipe, plano de crise). Deixe claro que é um sinal, não um diagnóstico.';
        case 'consulta': return 'Ajude a preparar a próxima consulta: liste de 4 a 7 perguntas ou observações objetivas que a pessoa pode levar ao psiquiatra/psicólogo, baseadas nos dados (com datas e números), mais um parágrafo curto "o que eu quero contar". Não sugira mudanças de medicação; se houver dúvidas sobre remédios, transforme em pergunta para o médico.';
        default: return 'Faça um check-in curto e acolhedor (no máximo 3 frases por resposta, uma pergunta por vez) sobre como a pessoa está agora: humor, sono da última noite, energia, irritabilidade e ansiedade. '
            . 'Quando tiver informação suficiente, proponha um registro e anexe no FINAL da resposta exatamente uma linha no formato <<REGISTRO {"mood":-3..3,"period":"manha|tarde|noite","sleep_hours":n,"energy":0..3,"irritability":0..3,"anxiety":0..3,"note":"resumo curto nas palavras da pessoa"}>> '
            . '(omita campos que não souber). Explique que a pessoa vai confirmar antes de salvar.';
    }
}
