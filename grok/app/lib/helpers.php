<?php
/**
 * @param mixed $default
 * @return mixed
 */
function cfg(string $path, $default = null) {
    $v = $GLOBALS['CONFIG'];
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return $default;
        $v = $v[$k];
    }
    return $v;
}
/** V4.3.1: mensagem do conflito de edição (duas abas/aparelhos alterando o mesmo registro). */
const VERSION_CONFLICT_MSG = 'Este registro foi alterado em outra aba ou aparelho. Recarregue para ver a versão mais recente.';
/** @return never */
function version_conflict(?int $current = null): void
{
    json_out(['erro' => VERSION_CONFLICT_MSG, 'mensagem' => VERSION_CONFLICT_MSG, 'versao_conflito' => true, 'versao_atual' => $current], 409);
}
/**
 * Versão que o cliente diz ter visto. null = cliente antigo/fila offline: sem checagem (comportamento anterior).
 * 0 = "estou criando, não deve existir ainda".
 * @param array<string,mixed> $in
 */
function client_version(array $in): ?int
{
    if (!array_key_exists('version', $in) || $in['version'] === null || $in['version'] === '') return null;
    $v = filter_var($in['version'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    return $v === false ? null : (int)$v;
}
/**
 * Concorrência otimista. Incrementa a versão de forma atômica só se ela ainda for a esperada.
 * Devolve true se a versão foi "reservada" (o chamador não deve incrementar de novo),
 * false se não há checagem ou se o registro não existe. Em caso de divergência responde 409 e encerra.
 * @param array<string,mixed> $where coluna => valor
 */
function version_guard(PDO $pdo, string $table, array $where, ?int $expected): bool
{
    if ($expected === null) return false;
    if (!in_array($table, ['entries', 'alert_rules', 'recipients', 'sexuality_log'], true)) throw new InvalidArgumentException('tabela sem versão');
    $cond = implode(' AND ', array_map(function (string $c): string { return "`$c` = ?"; }, array_keys($where)));
    $vals = array_values($where);
    if ($expected > 0) {
        $st = $pdo->prepare("UPDATE `$table` SET version = version + 1 WHERE $cond AND version = ?");
        $st->execute(array_merge($vals, [$expected]));
        if ($st->rowCount() > 0) return true;
    }
    $st = $pdo->prepare("SELECT version FROM `$table` WHERE $cond");
    $st->execute($vals);
    $cur = $st->fetchColumn();
    if ($cur !== false) version_conflict((int)$cur);
    return false;
}
/** @param mixed $s */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function b64url(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function b64url_decode(string $d): string { return base64_decode(strtr($d, '-_', '+/') . str_repeat('=', (4 - strlen($d) % 4) % 4)); }
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function send_security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(self), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self' https://accounts.google.com https://login.microsoftonline.com https://twitter.com https://x.com");
    if (is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
/**
 * @param mixed $data
 * @return never
 */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
/** @return array<string,mixed> */
function json_input(): array {
    $d = json_decode((string)file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}
/** @return never */
function redirect(string $to): void { header('Location: ' . $to); exit; }
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

/** Limite simples por janela fixa, armazenado no banco. */
function rate_limit(string $bucket, int $max, int $windowSec, bool $perIp = true): bool {
    if (loadtest_bypass()) return true;
    $key = hash('sha256', $bucket . '|' . ($perIp ? client_ip() : '*'));
    $now = time();
    $win = $now - ($now % $windowSec);
    $pdo = db();
    $pdo->prepare('INSERT INTO rate_limits (rl_key, window_start, hits) VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE hits = IF(window_start = VALUES(window_start), hits + 1, 1), window_start = VALUES(window_start)')
        ->execute([$key, $win]);
    $st = $pdo->prepare('SELECT hits FROM rate_limits WHERE rl_key = ?');
    $st->execute([$key]);
    return (int)$st->fetchColumn() <= $max;
}
/**
 * @param list<string> $headers
 * @param string|null $body
 * @return array{status:int,body:string,error:string,errno:int,ms:int}
 */
function bp_http_request(string $method, string $url, array $headers = [], $body = null, int $timeout = 20): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $t0 = microtime(true);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    return ['status' => $code, 'body' => $resp === false ? '' : $resp, 'error' => $err, 'errno' => $errno, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
}
function valid_timezone(string $tz): bool { return in_array($tz, timezone_identifiers_list(), true); }

/**
 * Teste de carga: quando 'loadtest_bypass_ratelimit_token' está preenchido (>= 32 caracteres) e a requisição
 * traz o cabeçalho X-Loadtest-Bypass com o mesmo valor, os limites de taxa são ignorados.
 * Vazio = desativado (padrão). REMOVA o valor do config.php logo após o teste.
 */
function loadtest_bypass(): bool {
    $t = (string)cfg('loadtest_bypass_ratelimit_token', '');
    if (strlen($t) < 32) return false;
    $h = $_SERVER['HTTP_X_LOADTEST_BYPASS'] ?? '';
    return is_string($h) && $h !== '' && hash_equals($t, $h);
}
/** Push só fica disponível com as duas chaves VAPID preenchidas. */
function push_configured(): bool {
    $pub = trim((string)cfg('vapid.public_key', ''));
    $priv = trim((string)cfg('vapid.private_key_pem', ''));
    return $pub !== '' && $priv !== '' && strpos($pub, 'SEU_') !== 0 && strpos($priv, 'BEGIN') !== false;
}
/**
 * Número no formato brasileiro (vírgula decimal, ponto de milhar, sinal de menos tipográfico opcional).
 * @param int|float|string|null $v
 */
function fmt_num($v, int $dec = 1, bool $minusSign = false): string {
    if ($v === null || $v === '' || !is_numeric($v)) return '';
    $s = number_format((float)$v, $dec, ',', '.');
    if ($s === '-0' || preg_match('/^-0,0+$/', $s)) $s = ltrim($s, '-');
    return $minusSign ? str_replace('-', '−', $s) : $s;
}
/** Fusos do Brasil, na ordem de exibição, com descrição amigável. */
const BRAZIL_TIMEZONES = [
    'America/Sao_Paulo' => 'Brasília — SP, RJ, MG, ES, PR, SC, RS, GO, DF',
    'America/Cuiaba' => 'Cuiabá — MT',
    'America/Manaus' => 'Manaus — AM',
    'America/Belem' => 'Belém — PA (leste), AP',
    'America/Fortaleza' => 'Fortaleza — CE, MA, PI, RN, PB',
    'America/Recife' => 'Recife — PE',
    'America/Bahia' => 'Salvador — BA',
    'America/Noronha' => 'Fernando de Noronha',
    'America/Rio_Branco' => 'Rio Branco — AC',
    'America/Porto_Velho' => 'Porto Velho — RO',
    'America/Boa_Vista' => 'Boa Vista — RR',
    'America/Campo_Grande' => 'Campo Grande — MS',
    'America/Araguaina' => 'Araguaína — TO',
    'America/Maceio' => 'Maceió — AL, SE',
    'America/Santarem' => 'Santarém — PA (oeste)',
    'America/Eirunepe' => 'Eirunepé — AM (oeste)',
];
/** Nome legível em pt-BR para um fuso IANA: "Europe/Lisbon" → "Europa · Lisboa". */
function tz_label_br(string $z): string {
    static $reg = ['Africa' => 'África', 'America' => 'Américas', 'Antarctica' => 'Antártida', 'Arctic' => 'Ártico', 'Asia' => 'Ásia', 'Atlantic' => 'Atlântico',
        'Australia' => 'Austrália', 'Europe' => 'Europa', 'Indian' => 'Índico', 'Pacific' => 'Pacífico', 'UTC' => 'UTC'];
    static $city = ['Lisbon' => 'Lisboa', 'London' => 'Londres', 'Madrid' => 'Madri', 'Paris' => 'Paris', 'Rome' => 'Roma', 'Berlin' => 'Berlim', 'Brussels' => 'Bruxelas',
        'Amsterdam' => 'Amsterdã', 'Zurich' => 'Zurique', 'Vienna' => 'Viena', 'Athens' => 'Atenas', 'Moscow' => 'Moscou', 'Warsaw' => 'Varsóvia', 'Prague' => 'Praga',
        'Dublin' => 'Dublin', 'Stockholm' => 'Estocolmo', 'Copenhagen' => 'Copenhague', 'Istanbul' => 'Istambul', 'New_York' => 'Nova York', 'Chicago' => 'Chicago',
        'Los_Angeles' => 'Los Angeles', 'Denver' => 'Denver', 'Mexico_City' => 'Cidade do México', 'Bogota' => 'Bogotá', 'Lima' => 'Lima', 'Santiago' => 'Santiago',
        'Asuncion' => 'Assunção', 'Montevideo' => 'Montevidéu', 'Caracas' => 'Caracas', 'La_Paz' => 'La Paz', 'Havana' => 'Havana', 'Toronto' => 'Toronto',
        'Argentina/Buenos_Aires' => 'Buenos Aires', 'Tokyo' => 'Tóquio', 'Shanghai' => 'Xangai', 'Hong_Kong' => 'Hong Kong', 'Singapore' => 'Singapura',
        'Kolkata' => 'Calcutá', 'Dubai' => 'Dubai', 'Jerusalem' => 'Jerusalém', 'Seoul' => 'Seul', 'Bangkok' => 'Bangcoc', 'Luanda' => 'Luanda', 'Maputo' => 'Maputo',
        'Cairo' => 'Cairo', 'Johannesburg' => 'Joanesburgo', 'Azores' => 'Açores', 'Madeira' => 'Madeira', 'Cape_Verde' => 'Cabo Verde', 'Canary' => 'Canárias',
        'Sydney' => 'Sydney', 'Auckland' => 'Auckland', 'Honolulu' => 'Honolulu',
        // V4.2: mais nomes em português
        'Anchorage' => 'Anchorage', 'Phoenix' => 'Phoenix', 'Vancouver' => 'Vancouver', 'Montreal' => 'Montreal', 'Halifax' => 'Halifax', 'St_Johns' => 'Saint John’s',
        'Panama' => 'Panamá', 'Guatemala' => 'Guatemala', 'Costa_Rica' => 'Costa Rica', 'El_Salvador' => 'El Salvador', 'Tegucigalpa' => 'Tegucigalpa', 'Managua' => 'Manágua',
        'Santo_Domingo' => 'Santo Domingo', 'Puerto_Rico' => 'Porto Rico', 'Jamaica' => 'Jamaica', 'Guayaquil' => 'Guayaquil', 'Cayenne' => 'Caiena (Guiana Francesa)',
        'Paramaribo' => 'Paramaribo', 'Guyana' => 'Guiana', 'Argentina/Cordoba' => 'Córdoba (Argentina)', 'Argentina/Mendoza' => 'Mendoza', 'Punta_Arenas' => 'Punta Arenas',
        'Tijuana' => 'Tijuana', 'Cancun' => 'Cancún', 'Monterrey' => 'Monterrey',
        'Oslo' => 'Oslo', 'Helsinki' => 'Helsinque', 'Budapest' => 'Budapeste', 'Bucharest' => 'Bucareste', 'Sofia' => 'Sófia', 'Kiev' => 'Kiev', 'Kyiv' => 'Kiev',
        'Belgrade' => 'Belgrado', 'Zagreb' => 'Zagreb', 'Ljubljana' => 'Liubliana', 'Luxembourg' => 'Luxemburgo', 'Monaco' => 'Mônaco', 'Malta' => 'Malta', 'Vatican' => 'Vaticano',
        'Andorra' => 'Andorra', 'Gibraltar' => 'Gibraltar', 'Minsk' => 'Minsk', 'Riga' => 'Riga', 'Tallinn' => 'Tallinn', 'Vilnius' => 'Vilnius', 'Edinburgh' => 'Edimburgo',
        'Reykjavik' => 'Reykjavík', 'Tbilisi' => 'Tbilisi', 'Yerevan' => 'Ierevã', 'Baku' => 'Baku', 'Tehran' => 'Teerã', 'Baghdad' => 'Bagdá', 'Riyadh' => 'Riad', 'Qatar' => 'Catar',
        'Kabul' => 'Cabul', 'Karachi' => 'Karachi', 'Kathmandu' => 'Katmandu', 'Dhaka' => 'Daca', 'Jakarta' => 'Jacarta', 'Manila' => 'Manila', 'Taipei' => 'Taipé',
        'Beirut' => 'Beirute', 'Damascus' => 'Damasco', 'Amman' => 'Amã', 'Gaza' => 'Gaza', 'Hebron' => 'Hebron', 'Macau' => 'Macau', 'Dili' => 'Díli', 'Ho_Chi_Minh' => 'Ho Chi Minh',
        'Kuala_Lumpur' => 'Kuala Lumpur', 'Yangon' => 'Rangum', 'Tashkent' => 'Tashkent', 'Almaty' => 'Almaty', 'Ulaanbaatar' => 'Ulan Bator', 'Pyongyang' => 'Pyongyang',
        'Vladivostok' => 'Vladivostok', 'Novosibirsk' => 'Novosibirsk', 'Yekaterinburg' => 'Ecaterimburgo',
        'Lagos' => 'Lagos', 'Nairobi' => 'Nairóbi', 'Casablanca' => 'Casablanca', 'Algiers' => 'Argel', 'Tunis' => 'Túnis', 'Tripoli' => 'Trípoli', 'Khartoum' => 'Cartum',
        'Addis_Ababa' => 'Adis Abeba', 'Accra' => 'Acra', 'Dakar' => 'Dacar', 'Kinshasa' => 'Kinshasa', 'Harare' => 'Harare', 'Bissau' => 'Bissau', 'Sao_Tome' => 'São Tomé',
        'Windhoek' => 'Windhoek', 'Abidjan' => 'Abidjan', 'Mauritius' => 'Maurício', 'Reunion' => 'Reunião', 'Maldives' => 'Maldivas', 'Seychelles' => 'Seicheles',
        'Melbourne' => 'Melbourne', 'Brisbane' => 'Brisbane', 'Perth' => 'Perth', 'Adelaide' => 'Adelaide', 'Darwin' => 'Darwin', 'Hobart' => 'Hobart', 'Fiji' => 'Fiji',
        'Tahiti' => 'Taiti', 'Easter' => 'Ilha de Páscoa', 'Galapagos' => 'Galápagos', 'Bermuda' => 'Bermudas', 'Faroe' => 'Ilhas Faroé', 'South_Georgia' => 'Geórgia do Sul',
        'Stanley' => 'Stanley (Malvinas)', 'Noumea' => 'Nouméa', 'Guam' => 'Guam'];
    if (isset(BRAZIL_TIMEZONES[$z])) return 'Brasil · ' . BRAZIL_TIMEZONES[$z];
    if ($z === 'UTC') return 'UTC (tempo universal)';
    $parts = explode('/', $z, 2);
    if (count($parts) < 2) return $reg[$z] ?? $z;
    $c = $city[$parts[1]] ?? str_replace(['_', '/'], [' ', ' · '], $parts[1]);
    return ($reg[$parts[0]] ?? $parts[0]) . ' · ' . $c;
}

/** @return array{brasil: array<string,string>, outros: list<string>} */
function timezone_options(): array {
    $all = timezone_identifiers_list();
    $br = array_filter(BRAZIL_TIMEZONES, function (string $z) use ($all): bool { return in_array($z, $all, true); }, ARRAY_FILTER_USE_KEY);
    $others = array_values(array_filter($all, function (string $z) use ($br): bool { return !isset($br[$z]); }));
    return ['brasil' => $br, 'outros' => $others];
}

/** Plural simples em pt-BR: plural_br(1, 'dia', 'dias') → "1 dia". */
function plural_br(int $n, string $one, string $many): string { return $n . ' ' . ($n === 1 ? $one : $many); }


/**
 * Batimento dos crons: grava só data/hora e contadores (nada de dados pessoais) em storage/heartbeat-<nome>.json
 * e uma linha em storage/logs/cron.log.
 *
 * @param array<string, int> $info
 */
function cron_heartbeat(string $name, array $info = []): void {
    $n = (string)preg_replace('/[^a-z_]/', '', $name);
    $f = STORAGE_DIR . '/heartbeat-' . $n . '.json';
    @file_put_contents($f, (string)json_encode(['at' => gmdate('c')] + $info), LOCK_EX);
    // V4.3: a situação dos crons existe só no servidor (heartbeat + este log), sem tela nem API.
    // Veja com: php /home1/USUARIO/tools/cron_status.php
    @mkdir(STORAGE_DIR . '/logs', 0700, true);
    $line = gmdate('c') . ' ' . $n;
    foreach ($info as $k => $v) $line .= ' ' . preg_replace('/[^a-z_]/', '', (string)$k) . '=' . (int)$v;
    @file_put_contents(STORAGE_DIR . '/logs/cron.log', $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Lê o último batimento de um cron. null se nunca rodou.
 *
 * @return array<string, mixed>|null
 */
function cron_heartbeat_read(string $name): ?array {
    $f = STORAGE_DIR . '/heartbeat-' . preg_replace('/[^a-z_]/', '', $name) . '.json';
    if (!is_file($f)) return null;
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) && isset($d['at']) ? $d : null;
}

/** V4.6: cor principal da marca da instalação ('brand' => ['color' => '#RRGGBB']) ou null para a cor padrão. */
function brand_color(): ?string {
    $c = strtolower(trim((string)cfg('brand.color', '')));
    return preg_match('/^#[0-9a-f]{6}$/', $c) ? $c : null;
}
