<?php
/**
 * V4.3: IA com chave própria (BYOK) e provedor à escolha da pessoa.
 * - Cada pessoa escolhe o provedor e cola a própria chave em Conta → IA.
 * - A chave é cifrada em repouso com libsodium (secretbox) usando 'ai.secret_key' do config.php.
 * - A chave nunca volta ao navegador (só os 4 últimos caracteres) e nunca vai para log.
 * - Adaptadores: formato OpenAI (xAI, OpenAI, DeepSeek, Mistral, OpenRouter, URL própria), Anthropic (Messages) e Google Gemini.
 * - Chave compartilhada do operador: OPCIONAL ('ai.operator_fallback'), desligada por padrão.
 */

/**
 * Provedores: rótulo, formato, URL base, modelos sugeridos (conferidos em out/2026 nas páginas oficiais), site da chave e termos.
 * @return array<string, array{label:string, format:string, base:string, models:list<string>, keys_url:string, terms_url:string}>
 */
function ai_providers(): array
{
    return [
        'xai' => ['label' => 'xAI (Grok)', 'format' => 'openai', 'base' => 'https://api.x.ai/v1',
            'models' => ['grok-4.7', 'grok-4.6', 'grok-4.3'], 'keys_url' => 'https://console.x.ai', 'terms_url' => 'https://x.ai/legal'],
        'openai' => ['label' => 'OpenAI (GPT)', 'format' => 'openai', 'base' => 'https://api.openai.com/v1',
            'models' => ['gpt-6.1-sol', 'gpt-6-luna', 'gpt-6-astra'], 'keys_url' => 'https://platform.openai.com/api-keys', 'terms_url' => 'https://openai.com/policies'],
        'anthropic' => ['label' => 'Anthropic (Claude)', 'format' => 'anthropic', 'base' => 'https://api.anthropic.com/v1',
            'models' => ['claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-opus-5-5'], 'keys_url' => 'https://console.anthropic.com/settings/keys', 'terms_url' => 'https://www.anthropic.com/legal'],
        'google' => ['label' => 'Google (Gemini)', 'format' => 'gemini', 'base' => 'https://generativelanguage.googleapis.com/v1beta',
            'models' => ['gemini-3.8-flash', 'gemini-4-argon', 'gemini-3.5-flash-lite'], 'keys_url' => 'https://aistudio.google.com/apikey', 'terms_url' => 'https://ai.google.dev/gemini-api/terms'],
        'deepseek' => ['label' => 'DeepSeek', 'format' => 'openai', 'base' => 'https://api.deepseek.com/v1',
            'models' => ['deepseek-flash', 'deepseek-v4-pro'], 'keys_url' => 'https://platform.deepseek.com/api_keys', 'terms_url' => 'https://www.deepseek.com'],
        'mistral' => ['label' => 'Mistral', 'format' => 'openai', 'base' => 'https://api.mistral.ai/v1',
            'models' => ['mistral-medium-latest', 'mistral-large-latest', 'mistral-small-latest'], 'keys_url' => 'https://console.mistral.ai/api-keys', 'terms_url' => 'https://mistral.ai/terms'],
        'openrouter' => ['label' => 'OpenRouter (vários modelos)', 'format' => 'openai', 'base' => 'https://openrouter.ai/api/v1',
            'models' => ['openrouter/auto'], 'keys_url' => 'https://openrouter.ai/keys', 'terms_url' => 'https://openrouter.ai/terms'],
        'custom' => ['label' => 'Outro compatível com OpenAI (URL própria)', 'format' => 'openai', 'base' => '',
            'models' => [], 'keys_url' => '', 'terms_url' => ''],
    ];
}

/* ------------------------------------------------------------------ cifra da chave */

/** Chave do servidor (32 bytes) a partir de 'ai.secret_key' (base64 ou hex). null = BYOK indisponível. */
function ai_server_key(): ?string
{
    if (!function_exists('sodium_crypto_secretbox')) return null;
    $raw = trim((string)cfg('ai.secret_key', ''));
    if ($raw === '') return null;
    $k = ctype_xdigit($raw) && strlen($raw) === 64 ? hex2bin($raw) : base64_decode($raw, true);
    return is_string($k) && strlen($k) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $k : null;
}
function ai_byok_available(): bool { return ai_server_key() !== null; }

/** Cifra (secretbox, nonce aleatório) → "v1:" + base64(nonce || cifra). */
function ai_encrypt_key(string $plain): string
{
    $k = ai_server_key();
    if ($k === null) throw new RuntimeException('ai.secret_key ausente ou inválida no config.php');
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $k));
}
function ai_decrypt_key(string $enc): ?string
{
    $k = ai_server_key();
    if ($k === null || strpos($enc, 'v1:') !== 0) return null;
    $bin = base64_decode(substr($enc, 3), true);
    if (!is_string($bin) || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $p = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $k);
    return is_string($p) ? $p : null;
}

/* ------------------------------------------------------------------ escolha da pessoa */

/**
 * Configuração da IA da pessoa (sem a chave em claro).
 * @param array<string,mixed> $u
 * @return array{provider:string, model:string, base_url:string, has_key:bool, last4:string, updated_at:?string}
 */
function ai_user_choice(array $u): array
{
    $p = (string)($u['ai_provider'] ?? '');
    return ['provider' => isset(ai_providers()[$p]) ? $p : '', 'model' => (string)($u['ai_model'] ?? ''), 'base_url' => (string)($u['ai_base_url'] ?? ''),
        'has_key' => (string)($u['ai_key_enc'] ?? '') !== '', 'last4' => (string)($u['ai_key_last4'] ?? ''), 'updated_at' => isset($u['ai_key_updated_at']) ? (string)$u['ai_key_updated_at'] : null];
}

/**
 * Chave compartilhada do operador (opcional). Também lê o bloco antigo 'xai' (V4–V4.2) para não quebrar instalações existentes.
 * @return array{provider:string, key:string, model:string, base_url:string, operator:bool}|null
 */
function ai_operator_fallback(): ?array
{
    $f = cfg('ai.operator_fallback', null);
    if (is_array($f) && trim((string)($f['api_key'] ?? '')) !== '') {
        $prov = isset(ai_providers()[(string)($f['provider'] ?? '')]) ? (string)$f['provider'] : 'xai';
        return ['provider' => $prov, 'key' => trim((string)$f['api_key']), 'model' => trim((string)($f['model'] ?? '')) ?: ai_providers()[$prov]['models'][0] ?? '', 'base_url' => (string)($f['base_url'] ?? ''), 'operator' => true];
    }
    $k = trim((string)cfg('xai.api_key', ''));
    if ($k !== '' && strpos($k, 'SUA_') !== 0 && strpos($k, 'xai-COLE') !== 0) {
        $e = (string)cfg('xai.endpoint', '');
        return ['provider' => 'xai', 'key' => $k, 'model' => trim((string)cfg('xai.model', 'grok-4.7')) ?: 'grok-4.7', 'base_url' => $e !== '' && $e !== 'https://api.x.ai/v1/chat/completions' ? $e : '', 'operator' => true];
    }
    return null;
}

/**
 * Credencial efetiva: a chave da pessoa; se não houver, a do operador (se existir).
 * @param array<string,mixed> $u
 * @return array{provider:string, key:string, model:string, base_url:string, operator:bool}|null
 */
function ai_credential(array $u): ?array
{
    $c = ai_user_choice($u);
    if ($c['provider'] !== '' && $c['has_key']) {
        $k = ai_decrypt_key((string)$u['ai_key_enc']);
        if ($k !== null && $k !== '') {
            $model = $c['model'] !== '' ? $c['model'] : (ai_providers()[$c['provider']]['models'][0] ?? '');
            return ['provider' => $c['provider'], 'key' => $k, 'model' => $model, 'base_url' => $c['base_url'], 'operator' => false];
        }
    }
    return ai_operator_fallback();
}

/* ------------------------------------------------------------------ validação */

function ai_valid_model(string $m): bool { return $m !== '' && strlen($m) <= 100 && preg_match('~^[A-Za-z0-9][A-Za-z0-9._:/+@-]*$~', $m) === 1; }
function ai_valid_key(string $k): bool { return strlen($k) >= 8 && strlen($k) <= 400 && preg_match('/^[\x21-\x7E]+$/', $k) === 1; }

/**
 * URL base própria: só https e só hosts públicos (evita que o servidor seja usado para acessar a rede interna).
 * 'ai.allow_private_urls' => true libera http/localhost (apenas para testes locais).
 */
function ai_base_url_problem(string $url): ?string
{
    $p = parse_url($url);
    if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) return 'Informe a URL completa, por exemplo https://exemplo.com/v1.';
    if (isset($p['user']) || isset($p['pass'])) return 'A URL não pode conter usuário ou senha.';
    if (cfg('ai.allow_private_urls', false)) return in_array($p['scheme'], ['http', 'https'], true) ? null : 'A URL deve começar com https://.';
    if ($p['scheme'] !== 'https') return 'A URL deve começar com https://.';
    $host = (string)$p['host'];
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return 'Não foi possível encontrar esse endereço.';
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return 'Esse endereço aponta para uma rede interna e não é permitido.';
    }
    return null;
}

/* ------------------------------------------------------------------ adaptadores */

/**
 * Monta a requisição HTTP para o provedor.
 * @param array{provider:string, key:string, model:string, base_url:string} $cred
 * @param list<array{role:string,content:string}> $messages
 * @return array{url:string, headers:list<string>, body:string}
 */
function ai_build_request(array $cred, array $messages, int $maxTokens, string $effort = ''): array
{
    $P = ai_providers()[$cred['provider']] ?? ai_providers()['custom'];
    $base = rtrim($cred['base_url'] !== '' ? $cred['base_url'] : $P['base'], '/');
    $base = (string)preg_replace('~/chat/completions$~', '', $base);
    $system = []; $conv = [];
    foreach ($messages as $m) {
        if ($m['role'] === 'system') { $system[] = $m['content']; continue; }
        $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
        $n = count($conv);
        if ($n && $conv[$n - 1]['role'] === $role) $conv[$n - 1]['content'] .= "\n\n" . $m['content']; // Anthropic/Gemini exigem alternância
        else $conv[] = ['role' => $role, 'content' => $m['content']];
    }
    if (!$conv || $conv[0]['role'] !== 'user') array_unshift($conv, ['role' => 'user', 'content' => 'Olá.']);
    if ($P['format'] === 'anthropic') {
        $b = ['model' => $cred['model'], 'max_tokens' => $maxTokens, 'system' => implode("\n\n", $system), 'messages' => $conv];
        return ['url' => $base . '/messages', 'headers' => ['Content-Type: application/json', 'x-api-key: ' . $cred['key'], 'anthropic-version: 2023-06-01'],
            'body' => (string)json_encode($b, JSON_UNESCAPED_UNICODE)];
    }
    if ($P['format'] === 'gemini') {
        $b = ['contents' => array_map(function (array $m): array { return ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]]; }, $conv),
            'generationConfig' => ['maxOutputTokens' => $maxTokens]];
        if ($system) $b['systemInstruction'] = ['parts' => [['text' => implode("\n\n", $system)]]];
        return ['url' => $base . '/models/' . rawurlencode($cred['model']) . ':generateContent', 'headers' => ['Content-Type: application/json', 'x-goog-api-key: ' . $cred['key']],
            'body' => (string)json_encode($b, JSON_UNESCAPED_UNICODE)];
    }
    // formato OpenAI (Chat Completions)
    $msgs = [];
    foreach ($messages as $m) $msgs[] = ['role' => in_array($m['role'], ['system', 'assistant'], true) ? $m['role'] : 'user', 'content' => $m['content']];
    $b = ['model' => $cred['model'], 'messages' => $msgs, 'stream' => false];
    if (in_array($cred['provider'], ['openai', 'xai'], true)) $b['max_completion_tokens'] = $maxTokens; else $b['max_tokens'] = $maxTokens;
    if ($effort !== '' && in_array($cred['provider'], ['xai', 'openai'], true)) $b['reasoning_effort'] = $effort;
    $h = ['Content-Type: application/json', 'Authorization: Bearer ' . $cred['key']];
    if ($cred['provider'] === 'openrouter') { $h[] = 'HTTP-Referer: ' . (string)cfg('app_url', ''); $h[] = 'X-Title: Bipolar'; }
    return ['url' => $base . '/chat/completions', 'headers' => $h, 'body' => (string)json_encode($b, JSON_UNESCAPED_UNICODE)];
}

/**
 * Lê a resposta de qualquer formato → texto e uso normalizado (prompt_tokens/completion_tokens).
 * @param array<string,mixed>|null $j
 * @return array{text:string, usage:array{prompt_tokens:int, completion_tokens:int}, finish:string}
 */
function ai_parse_response(string $provider, ?array $j): array
{
    $fmt = (ai_providers()[$provider] ?? ai_providers()['custom'])['format'];
    $text = ''; $in = 0; $out = 0; $finish = '';
    if (is_array($j)) {
        if ($fmt === 'anthropic') {
            foreach ((array)($j['content'] ?? []) as $c) if (is_array($c) && ($c['type'] ?? '') === 'text') $text .= (string)($c['text'] ?? '');
            $in = (int)($j['usage']['input_tokens'] ?? 0); $out = (int)($j['usage']['output_tokens'] ?? 0); $finish = (string)($j['stop_reason'] ?? '');
        } elseif ($fmt === 'gemini') {
            foreach ((array)($j['candidates'][0]['content']['parts'] ?? []) as $p) if (is_array($p) && empty($p['thought'])) $text .= (string)($p['text'] ?? '');
            $in = (int)($j['usageMetadata']['promptTokenCount'] ?? 0); $out = (int)($j['usageMetadata']['candidatesTokenCount'] ?? 0); $finish = (string)($j['candidates'][0]['finishReason'] ?? '');
        } else {
            $text = (string)($j['choices'][0]['message']['content'] ?? '');
            $in = (int)($j['usage']['prompt_tokens'] ?? 0); $out = (int)($j['usage']['completion_tokens'] ?? 0); $finish = (string)($j['choices'][0]['finish_reason'] ?? '');
        }
    }
    return ['text' => trim($text), 'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out], 'finish' => $finish];
}

/**
 * Tipo do erro a partir do status e do corpo (cada provedor descreve "sem crédito" de um jeito).
 */
function ai_error_kind_full(int $status, string $body, string $curlError, int $errno, bool $json, bool $empty): string
{
    if ($status === 0) return $errno === 28 || stripos($curlError, 'timed out') !== false ? 'timeout' : 'rede';
    $b = strtolower($body);
    $noCredit = ['insufficient_quota', 'credit balance', 'insufficient balance', 'exceeded your current quota', 'billing', 'payment required', 'out of credits', 'no credits', 'insufficient credits', 'spending limit'];
    foreach ($noCredit as $n) if (strpos($b, $n) !== false) return 'credito';
    if ($status === 402) return 'credito';
    if ($status === 401 || $status === 403) return 'chave';
    if ($status === 400 && (strpos($b, 'api key') !== false || strpos($b, 'api_key') !== false || strpos($b, 'invalid x-api-key') !== false)) return 'chave';
    if ($status === 404 || ($status === 400 && strpos($b, 'model') !== false)) return 'modelo';
    if ($status === 408 || $status === 504) return 'timeout';
    if ($status === 429) return 'limite';
    if ($status === 400 && $json) return 'modelo';
    if ($status === 200 && $empty) return 'vazio';
    return 'outro';
}

/** Mensagens claras em pt-BR. $own = a pessoa usa a própria chave. */
function ai_user_error(string $kind, bool $own): string
{
    $m = [
        'timeout' => 'A IA demorou demais para responder. Tente de novo em instantes.',
        'rede' => 'Não foi possível conectar ao provedor de IA agora. Confira a URL ou tente mais tarde.',
        'chave' => $own ? 'Chave inválida ou sem permissão. Confira se você colou a chave inteira, do provedor certo, e se ela não foi revogada.' : 'O serviço de IA está indisponível no momento (configuração).',
        'credito' => $own ? 'Sua conta no provedor está sem crédito ou atingiu o limite de gastos. Adicione crédito no site do provedor e tente de novo.' : 'O crédito da IA compartilhada acabou. Tente mais tarde ou use a sua própria chave.',
        'modelo' => $own ? 'O provedor recusou o modelo escolhido. Escolha outro modelo da lista ou confira o nome digitado.' : 'O serviço de IA está indisponível no momento (configuração).',
        'limite' => 'Limite de uso do provedor atingido (muitas requisições). Espere alguns minutos e tente de novo.',
        'vazio' => 'A IA não respondeu desta vez. Tente de novo.',
        'outro' => 'Não foi possível falar com a IA agora. Tente mais tarde.',
    ];
    return $m[$kind] ?? $m['outro'];
}

/** Remove a chave (e partes dela) de qualquer texto antes de registrar. */
function ai_redact(string $s, string $key): string
{
    if ($key !== '') { $s = str_replace($key, '[chave]', $s); if (strlen($key) > 12) $s = str_replace(substr($key, 0, 12), '[chave]', $s); }
    return (string)preg_replace(['/(sk|xai|sk-ant|AIza)[A-Za-z0-9_\-]{12,}/', '/Bearer\s+\S+/i'], ['[chave]', 'Bearer [chave]'], $s);
}
