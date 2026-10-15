<?php
declare(strict_types=1);
require_once __DIR__ . "/media-range.php";
require_once __DIR__ . "/automation.php";

final class BipolarApiException extends RuntimeException
{
    public function __construct(string $code, int $status = 400) { parent::__construct($code, $status); }
    public function status(): int { return (int) $this->getCode(); }
}
function bipolar_api_run(string $privateDir): void
{
    try {
        $config = bipolar_load_config($privateDir); $GLOBALS['bipolar_storage_path'] = $config['app']['storage_path']; bipolar_headers(); bipolar_start_session($config);
        $action = is_string($_GET['action'] ?? null) ? (string) $_GET['action'] : '';
        if ($action === 'health' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') bipolar_json(['ok' => true]);
        $pdo = bipolar_pdo($config); bipolar_rate_limit_if_needed($pdo, $config, $action);
        switch ($action) {
            case 'session': bipolar_require_method('GET'); bipolar_json(bipolar_session_payload($pdo, $config));
            case 'oauth_start': bipolar_require_method('GET'); bipolar_oauth_start($config);
            case 'oauth_callback': bipolar_require_method('GET'); bipolar_oauth_callback($pdo, $config);
            case 'entries': bipolar_require_method('GET'); $user = bipolar_require_user($pdo); bipolar_json(['entries' => bipolar_fetch_entries($pdo, $config, $user['id'])]);
            case 'logout': bipolar_require_method('POST'); bipolar_require_csrf(); bipolar_destroy_session(); bipolar_json(['ok' => true]);
            case 'entry': bipolar_require_method('POST'); bipolar_require_csrf(); $user = bipolar_require_user($pdo); bipolar_json(['entry' => bipolar_save_entry($pdo, $config, $user['id'], bipolar_json_body())]);
            case 'delete_entry': bipolar_require_method('POST'); bipolar_require_csrf(); $user = bipolar_require_user($pdo); bipolar_delete_entry($pdo, $user['id']); bipolar_json(['ok' => true]);
            case 'media': bipolar_require_method('GET'); $user = bipolar_require_user($pdo); bipolar_stream_media($pdo, $config, $user['id']);
            case 'upload': bipolar_require_method('POST'); bipolar_require_csrf(); $user = bipolar_require_user($pdo); bipolar_json(['attachment' => bipolar_upload($pdo, $config, $user['id'])]);
            case 'delete_media': bipolar_require_method('POST'); bipolar_require_csrf(); $user = bipolar_require_user($pdo); bipolar_delete_media($pdo, $user['id']); bipolar_json(['ok' => true]);
            case 'delete_account': bipolar_require_method('POST'); bipolar_require_csrf(); $user = bipolar_require_user($pdo); bipolar_delete_account($pdo, $user['id']); bipolar_json(['ok' => true]);
            case 'automation':
                $user = bipolar_require_user($pdo);
                if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') bipolar_json(bipolar_aut_response($pdo, $config, $user['id']));
                bipolar_require_method('POST'); bipolar_require_csrf(); bipolar_aut_account_rate_limit($pdo, $config, $user['id'], 'automation'); bipolar_json(bipolar_aut_save_settings($pdo, $config, $user['id'], bipolar_json_body()));
            case 'push_subscribe': bipolar_require_method('POST'); bipolar_require_csrf(); $user = bipolar_require_user($pdo); bipolar_aut_account_rate_limit($pdo, $config, $user['id'], 'push_subscribe'); bipolar_json(bipolar_aut_push_subscribe($pdo, $config, $user['id'], bipolar_json_body()));
            case 'push_unsubscribe': bipolar_require_method('POST'); bipolar_require_csrf(); $user = bipolar_require_user($pdo); bipolar_aut_account_rate_limit($pdo, $config, $user['id'], 'push_unsubscribe'); bipolar_json(bipolar_aut_push_unsubscribe($pdo, $config, $user['id'], bipolar_json_body()));
            case 'send_report': bipolar_require_method('POST'); bipolar_require_csrf(); $user = bipolar_require_user($pdo); bipolar_aut_account_rate_limit($pdo, $config, $user['id'], 'send_report'); bipolar_json(bipolar_aut_send_report($pdo, $config, $user['id'], bipolar_json_body()));
            case 'delivery_history': bipolar_require_method('GET'); $user = bipolar_require_user($pdo); bipolar_json(bipolar_aut_delivery_history($pdo, $config, $user['id']));
            default: throw new BipolarApiException('not_found', 404);
        }
    } catch (BipolarApiException $e) { bipolar_json(['error' => $e->getMessage()], $e->status()); }
    catch (Throwable $e) { error_log('[bipolar] internal error'); bipolar_json(['error' => 'internal_error'], 500); }
}
function bipolar_load_config(string $privateDir): array
{
    $file = $privateDir . '/config.php'; if (!is_file($file)) throw new BipolarApiException('service_unavailable', 503); $config = require $file;
    if (!is_array($config)) throw new BipolarApiException('service_unavailable', 503);
    $keyText = $config['app']['encryption_key_b64'] ?? ''; $key = is_string($keyText) ? base64_decode($keyText, true) : false;
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new BipolarApiException('service_unavailable', 503); $config['app']['encryption_key'] = $key;
    $config['app']['base_url'] = rtrim((string) ($config['app']['base_url'] ?? ''), '/'); $parts = parse_url($config['app']['base_url']);
    if (!filter_var($config['app']['base_url'], FILTER_VALIDATE_URL) || !is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') throw new BipolarApiException('service_unavailable', 503);
    try { date_default_timezone_set((string) ($config['app']['timezone'] ?? 'America/Sao_Paulo')); } catch (Throwable $e) { throw new BipolarApiException('service_unavailable', 503); }
    $storage = (string) ($config['app']['storage_path'] ?? ''); if ($storage === '' || !str_starts_with($storage, DIRECTORY_SEPARATOR)) throw new BipolarApiException('service_unavailable', 503);
    if (!is_dir($storage) && !@mkdir($storage, 0700, true) && !is_dir($storage)) throw new BipolarApiException('service_unavailable', 503); @chmod($storage, 0700); $config['app']['storage_path'] = rtrim($storage, DIRECTORY_SEPARATOR);
    $config['session']['idle_ttl'] = max(300, (int) ($config['session']['idle_ttl'] ?? 1800)); $config['session']['absolute_ttl'] = max($config['session']['idle_ttl'], (int) ($config['session']['absolute_ttl'] ?? 28800)); $config['session']['cookie_secure'] = (bool) ($config['session']['cookie_secure'] ?? true);
    $sameSite = (string) ($config['session']['cookie_samesite'] ?? 'Lax'); if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) throw new BipolarApiException('service_unavailable', 503);
    if ($sameSite === 'None' && (!(bool) ($config['session']['allow_none_secure'] ?? false) || !$config['session']['cookie_secure'])) throw new BipolarApiException('service_unavailable', 503); $config['session']['cookie_samesite'] = $sameSite; return $config;
}
function bipolar_headers(): void
{
    ini_set('display_errors', '0'); ini_set('log_errors', '1'); header('Cache-Control: private, no-store, max-age=0'); header('Pragma: no-cache'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer'); header('X-Frame-Options: DENY'); header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
}
function bipolar_start_session(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return; $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($config['session']['name'] ?? 'bipolar_sid')); session_name($name !== '' ? $name : 'bipolar_sid'); ini_set('session.use_only_cookies', '1'); ini_set('session.use_strict_mode', '1'); ini_set('session.cookie_httponly', '1'); ini_set('session.cookie_secure', $config['session']['cookie_secure'] ? '1' : '0');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $config['session']['cookie_secure'], 'httponly' => true, 'samesite' => $config['session']['cookie_samesite']]); session_start(); $now = time(); $created = (int) ($_SESSION['_created'] ?? $now); $last = (int) ($_SESSION['_last'] ?? $now);
    if (($now - $last) > $config['session']['idle_ttl'] || ($now - $created) > $config['session']['absolute_ttl']) { $_SESSION = []; session_regenerate_id(true); $created = $now; } $_SESSION['_created'] = $created; $_SESSION['_last'] = $now; if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) $_SESSION['csrf'] = bipolar_random_url(32);
}
function bipolar_pdo(array $config): PDO
{
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]; if (isset($config['database']['options']) && is_array($config['database']['options'])) $options = $config['database']['options'] + $options;
    try { return new PDO((string) ($config['database']['dsn'] ?? ''), (string) ($config['database']['user'] ?? ''), (string) ($config['database']['password'] ?? ''), $options); } catch (Throwable $e) { throw new BipolarApiException('service_unavailable', 503); }
}
function bipolar_require_method(string $method): void { if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) { header('Allow: ' . $method); throw new BipolarApiException('method_not_allowed', 405); } }
function bipolar_json(array $payload, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit; }
function bipolar_json_body(): array
{
    $raw = file_get_contents('php://input'); if (!is_string($raw) || trim($raw) === '') throw new BipolarApiException('invalid_json', 400); try { $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new BipolarApiException('invalid_json', 400); } if (!is_array($value)) throw new BipolarApiException('invalid_json', 400); return $value;
}
function bipolar_origin_check(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? ''; if (!is_string($origin) || $origin === '') return; $parsed = parse_url($origin); $originHost = is_array($parsed) ? strtolower((string) ($parsed['host'] ?? '')) : ''; $requestHost = preg_replace('/:\d+$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''))); if ($originHost === '' || $requestHost === '' || $originHost !== $requestHost) throw new BipolarApiException('csrf_failed', 403);
}
function bipolar_require_csrf(): void
{
    bipolar_origin_check(); $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''; if (!is_string($token) || !isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) throw new BipolarApiException('csrf_failed', 403);
}
function bipolar_current_user(PDO $pdo): ?array
{
    $userId = $_SESSION['auth']['user_id'] ?? null; if (!is_string($userId) || !bipolar_is_uuid($userId)) return null; $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ? LIMIT 1'); $stmt->execute([$userId]); $user = $stmt->fetch(); if (!is_array($user)) { unset($_SESSION['auth']); return null; } return $user;
}
function bipolar_require_user(PDO $pdo): array { $user = bipolar_current_user($pdo); if ($user === null) throw new BipolarApiException('auth_required', 401); return $user; }
function bipolar_session_payload(PDO $pdo, array $config): array
{
    $user = bipolar_current_user($pdo); $providers = []; foreach (['google', 'x', 'microsoft'] as $provider) { $p = $config['oauth'][$provider] ?? []; $providers[$provider] = is_array($p) && (string) ($p['client_id'] ?? '') !== '' && (string) ($p['client_secret'] ?? '') !== ''; }
    return ['user' => $user === null ? null : ['id' => (string) $user['id'], 'name' => (string) $user['name'], 'email' => $user['email'] === null ? null : (string) $user['email'],], 'csrf' => (string) $_SESSION['csrf'], 'providers' => $providers];
}

function bipolar_rate_limit_if_needed(PDO $pdo, array $config, string $action): void
{
    $limit = null; $window = max(60, (int) ($config['limits']['rate_window_seconds'] ?? 900)); if ($action === 'oauth_start' || $action === 'oauth_callback') $limit = max(1, (int) ($config['limits']['oauth_rate_limit'] ?? 20)); elseif ($action === 'upload') $limit = max(1, (int) ($config['limits']['upload_rate_limit'] ?? 30));  if ($limit === null) return;
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'); $key = hash_hmac('sha256', $action . '|' . $ip, (string) $config['app']['encryption_key']); $now = time(); $pdo->beginTransaction();
    try { $stmt = $pdo->prepare('SELECT window_start, hit_count FROM rate_limits WHERE rate_key = ? FOR UPDATE'); $stmt->execute([$key]); $row = $stmt->fetch(); if (!is_array($row) || ($now - (int) $row['window_start']) >= $window) { $stmt = $pdo->prepare('INSERT INTO rate_limits (rate_key, window_start, hit_count) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE window_start = VALUES(window_start), hit_count = VALUES(hit_count)'); $stmt->execute([$key, $now]); $count = 1; } else { $count = (int) $row['hit_count'] + 1; $stmt = $pdo->prepare('UPDATE rate_limits SET hit_count = ? WHERE rate_key = ?'); $stmt->execute([$count, $key]); } $pdo->commit(); if ($count > $limit) throw new BipolarApiException('rate_limited', 429); }
    catch (BipolarApiException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw new BipolarApiException('service_unavailable', 503); }
}
function bipolar_oauth_provider(string $provider, array $config): array
{
    if (!in_array($provider, ['google', 'x', 'microsoft'], true)) throw new BipolarApiException('invalid_provider', 400); $item = $config['oauth'][$provider] ?? []; if (!is_array($item) || (string) ($item['client_id'] ?? '') === '' || (string) ($item['client_secret'] ?? '') === '') throw new BipolarApiException('provider_unavailable', 503); return $item;
}
function bipolar_callback_url(string $provider, array $config): string { return (string) $config['app']['base_url'] . '/api/index.php?action=oauth_callback&provider=' . rawurlencode($provider); }
function bipolar_oauth_start(array $config): never
{
    $provider = is_string($_GET['provider'] ?? null) ? (string) $_GET['provider'] : ''; $oauth = bipolar_oauth_provider($provider, $config); $state = bipolar_random_url(32); $verifier = bipolar_random_url(64); $_SESSION['oauth_tx'] = ['provider' => $provider, 'state' => $state, 'verifier' => $verifier, 'expires' => time() + 300];
    $params = ['client_id' => (string) $oauth['client_id'], 'redirect_uri' => bipolar_callback_url($provider, $config), 'response_type' => 'code', 'state' => $state, 'code_challenge' => bipolar_base64url(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256'];
    if ($provider === 'google') { $endpoint = 'https://accounts.google.com/o/oauth2/v2/auth'; $params['scope'] = 'openid email profile'; $params['access_type'] = 'online'; $params['prompt'] = 'select_account'; } elseif ($provider === 'microsoft') { $endpoint = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize'; $params['response_mode'] = 'query'; $params['scope'] = 'openid profile email https://graph.microsoft.com/User.Read'; } else { $endpoint = 'https://x.com/i/oauth2/authorize'; $params['scope'] = 'users.read tweet.read'; }
    bipolar_json(['url' => $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986)]);
}
function bipolar_oauth_callback(PDO $pdo, array $config): never
{
    $provider = is_string($_GET['provider'] ?? null) ? (string) $_GET['provider'] : ''; $tx = $_SESSION['oauth_tx'] ?? null; unset($_SESSION['oauth_tx']); if (!is_array($tx) || (string) ($tx['provider'] ?? '') !== $provider || !is_string($tx['state'] ?? null) || !is_string($tx['verifier'] ?? null) || (int) ($tx['expires'] ?? 0) < time()) throw new BipolarApiException('oauth_state_invalid', 400);
    $state = $_GET['state'] ?? ''; if (!is_string($state) || $state === '' || !hash_equals((string) $tx['state'], $state)) throw new BipolarApiException('oauth_state_invalid', 400); if (isset($_GET['error'])) throw new BipolarApiException('oauth_denied', 400); $code = $_GET['code'] ?? ''; if (!is_string($code) || $code === '') throw new BipolarApiException('oauth_failed', 400);
    $oauth = bipolar_oauth_provider($provider, $config); $callback = bipolar_callback_url($provider, $config); $endpoint = $provider === 'google' ? 'https://oauth2.googleapis.com/token' : ($provider === 'microsoft' ? 'https://login.microsoftonline.com/common/oauth2/v2.0/token' : 'https://api.x.com/2/oauth2/token'); $form = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $callback, 'client_id' => (string) $oauth['client_id'], 'code_verifier' => (string) $tx['verifier']]; $headers = ['Accept: application/json']; if ($provider !== 'x') $form['client_secret'] = (string) $oauth['client_secret']; else $headers[] = 'Authorization: Basic ' . base64_encode((string) $oauth['client_id'] . ':' . (string) $oauth['client_secret']);
    $token = bipolar_http_form($endpoint, $form, $headers); $access = $token['access_token'] ?? null; if (!is_string($access) || $access === '') throw new BipolarApiException('oauth_failed', 400);
    if ($provider === 'google') { $info = bipolar_http_json('https://openidconnect.googleapis.com/v1/userinfo', $access); $subject = $info['sub'] ?? null; $name = $info['name'] ?? null; $email = $info['email'] ?? null; } elseif ($provider === 'microsoft') { $info = bipolar_http_json('https://graph.microsoft.com/v1.0/me', $access); $subject = $info['id'] ?? null; $name = $info['displayName'] ?? null; $email = $info['mail'] ?? ($info['userPrincipalName'] ?? null); } else { $info = bipolar_http_json('https://api.x.com/2/users/me', $access); $data = is_array($info['data'] ?? null) ? $info['data'] : []; $subject = $data['id'] ?? null; $name = $data['name'] ?? ($data['username'] ?? null); $email = null; }
    if (!is_string($subject) || $subject === '' || strlen($subject) > 191) throw new BipolarApiException('oauth_failed', 400); $name = bipolar_clean_text(is_string($name) && $name !== '' ? $name : $provider, 191); $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? bipolar_clean_text($email, 191) : null;
    $pdo->beginTransaction(); try { $stmt = $pdo->prepare('SELECT u.id, u.name, u.email FROM identities i INNER JOIN users u ON u.id = i.user_id WHERE i.provider = ? AND i.provider_subject = ? LIMIT 1 FOR UPDATE'); $stmt->execute([$provider, $subject]); $user = $stmt->fetch(); if (!is_array($user)) { $userId = bipolar_uuid(); $now = bipolar_db_now(); $stmt = $pdo->prepare('INSERT INTO users (id, name, email, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'); $stmt->execute([$userId, $name, $email, $now, $now]); $stmt = $pdo->prepare('INSERT INTO identities (user_id, provider, provider_subject, created_at) VALUES (?, ?, ?, ?)'); $stmt->execute([$userId, $provider, $subject, $now]); $user = ['id' => $userId, 'name' => $name, 'email' => $email]; } $pdo->commit(); } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw new BipolarApiException('oauth_failed', 400); }
    session_regenerate_id(true); $_SESSION['_created'] = time(); $_SESSION['_last'] = time(); $_SESSION['csrf'] = bipolar_random_url(32); $_SESSION['auth'] = ['user_id' => (string) $user['id']]; header('Location: /', true, 302); exit;
}
function bipolar_http_form(string $url, array $form, array $headers = []): array
{
    if (!function_exists('curl_init')) throw new BipolarApiException('service_unavailable', 503); $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form, '', '&', PHP_QUERY_RFC3986), CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]); $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); if (!is_string($body) || $status < 200 || $status >= 300) throw new BipolarApiException('oauth_failed', 400); try { $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new BipolarApiException('oauth_failed', 400); } return is_array($decoded) ? $decoded : [];
}
function bipolar_http_json(string $url, string $accessToken): array
{
    if (!function_exists('curl_init')) throw new BipolarApiException('service_unavailable', 503); $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $accessToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]); $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); if (!is_string($body) || $status < 200 || $status >= 300) throw new BipolarApiException('oauth_failed', 400); try { $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new BipolarApiException('oauth_failed', 400); } return is_array($decoded) ? $decoded : [];
}

function bipolar_save_entry(PDO $pdo, array $config, string $userId, array $input): array
{
    $date = $input['date'] ?? null; $period = $input['period'] ?? null; $mood = $input['mood'] ?? null; if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !bipolar_valid_date($date, (string) $config['app']['timezone'])) throw new BipolarApiException('invalid_entry', 422); $today = (new DateTimeImmutable('now', new DateTimeZone((string) $config['app']['timezone'])))->format('Y-m-d'); if ($date > $today || !is_string($period) || !in_array($period, ['morning', 'afternoon', 'night'], true) || !is_int($mood) || $mood < -3 || $mood > 3) throw new BipolarApiException('invalid_entry', 422);
    $note = $input['note'] ?? ''; if (!is_string($note) || !bipolar_valid_text($note, 20000)) throw new BipolarApiException('invalid_entry', 422); $sleep = $input['sleep'] ?? null; if ($sleep !== null && ((!is_int($sleep) && !is_float($sleep)) || !is_finite((float) $sleep) || (float) $sleep < 0 || (float) $sleep > 24)) throw new BipolarApiException('invalid_entry', 422); $medication = $input['medication'] ?? ''; if (!is_string($medication) || !in_array($medication, ['taken', 'not-taken', 'not-applicable', ''], true)) throw new BipolarApiException('invalid_entry', 422); $symptoms = bipolar_string_list($input['symptoms'] ?? []); $factors = bipolar_string_list($input['factors'] ?? []); $mixed = $input['mixed'] ?? false; if (!is_bool($mixed)) throw new BipolarApiException('invalid_entry', 422);
    $requestedId = $input['id'] ?? null; if ($requestedId !== null && (!is_string($requestedId) || !bipolar_is_uuid($requestedId))) throw new BipolarApiException('invalid_entry', 422); $payload = ['mood' => $mood, 'note' => $note, 'sleep' => $sleep, 'medication' => $medication, 'symptoms' => $symptoms, 'factors' => $factors, 'mixed' => $mixed]; $now = bipolar_db_now(); $pdo->beginTransaction();
    try {
        $row = null; if (is_string($requestedId)) { $stmt = $pdo->prepare('SELECT id, date_value, period, created_at FROM entries WHERE id = ? AND user_id = ? LIMIT 1 FOR UPDATE'); $stmt->execute([$requestedId, $userId]); $row = $stmt->fetch(); if (!is_array($row)) throw new BipolarApiException('not_found', 404); if ((string) $row['date_value'] !== $date || (string) $row['period'] !== $period) { $stmt = $pdo->prepare('SELECT id FROM entries WHERE user_id = ? AND date_value = ? AND period = ? AND id <> ? LIMIT 1'); $stmt->execute([$userId, $date, $period, $requestedId]); if (is_array($stmt->fetch())) throw new BipolarApiException('entry_conflict', 409); } } else { $stmt = $pdo->prepare('SELECT id, date_value, period, created_at FROM entries WHERE user_id = ? AND date_value = ? AND period = ? LIMIT 1 FOR UPDATE'); $stmt->execute([$userId, $date, $period]); $row = $stmt->fetch(); }
        $entryId = is_array($row) ? (string) $row['id'] : bipolar_uuid(); $cipher = bipolar_encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $config['app']['encryption_key']); if (is_array($row)) { $stmt = $pdo->prepare('UPDATE entries SET date_value = ?, period = ?, payload_cipher = ?, updated_at = ? WHERE id = ? AND user_id = ?'); $stmt->execute([$date, $period, $cipher, $now, $entryId, $userId]); $createdAt = (string) $row['created_at']; } else { $stmt = $pdo->prepare('INSERT INTO entries (id, user_id, date_value, period, payload_cipher, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'); $stmt->execute([$entryId, $userId, $date, $period, $cipher, $now, $now]); $createdAt = $now; } $pdo->commit();
    } catch (BipolarApiException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw new BipolarApiException('entry_save_failed', 500); }
    return bipolar_entry_from_row($pdo, $config, $entryId, $userId, $date, $period, $mood, $payload, $createdAt);
}
function bipolar_entry_from_row(PDO $pdo, array $config, string $entryId, string $userId, string $date, string $period, int $mood, array $payload, string $createdAt): array
{
    return ['id' => $entryId, 'date' => $date, 'period' => $period, 'mood' => $mood, 'note' => $payload['note'], 'sleep' => $payload['sleep'], 'medication' => $payload['medication'], 'symptoms' => $payload['symptoms'], 'factors' => $payload['factors'], 'mixed' => $payload['mixed'], 'createdAt' => bipolar_db_to_iso($createdAt), 'attachments' => bipolar_fetch_attachments($pdo, $config, $entryId, $userId)];
}
function bipolar_fetch_entries(PDO $pdo, array $config, string $userId): array
{
    $stmt = $pdo->prepare('SELECT id, date_value, period, payload_cipher, created_at FROM entries WHERE user_id = ? ORDER BY date_value DESC, FIELD(period, "night", "afternoon", "morning") DESC, created_at DESC'); $stmt->execute([$userId]); $out = [];
    while ($row = $stmt->fetch()) { try { $payload = json_decode(bipolar_decrypt((string) $row['payload_cipher'], $config['app']['encryption_key']), true, 64, JSON_THROW_ON_ERROR); } catch (Throwable $e) { throw new BipolarApiException('data_unavailable', 500); } if (!is_array($payload) || !isset($payload['mood']) || !is_int($payload['mood'])) throw new BipolarApiException('data_unavailable', 500); $mood = $payload['mood']; unset($payload['mood']); $out[] = bipolar_entry_from_row($pdo, $config, (string) $row['id'], $userId, (string) $row['date_value'], (string) $row['period'], $mood, $payload, (string) $row['created_at']); }
    return $out;
}
function bipolar_fetch_attachments(PDO $pdo, array $config, string $entryId, string $userId): array
{
    $stmt = $pdo->prepare('SELECT id, metadata_cipher FROM attachments WHERE entry_id = ? AND user_id = ? ORDER BY created_at ASC'); $stmt->execute([$entryId, $userId]); $out = []; while ($row = $stmt->fetch()) { try { $meta = json_decode(bipolar_decrypt((string) $row['metadata_cipher'], $config['app']['encryption_key']), true, 16, JSON_THROW_ON_ERROR); } catch (Throwable $e) { throw new BipolarApiException('data_unavailable', 500); } if (!is_array($meta)) throw new BipolarApiException('data_unavailable', 500); $id = (string) $row['id']; $out[] = ['id' => $id, 'name' => (string) ($meta['name'] ?? 'arquivo'), 'type' => (string) ($meta['type'] ?? 'audio'), 'url' => '/api/index.php?action=media&id=' . rawurlencode($id), 'mime' => (string) ($meta['mime'] ?? 'application/octet-stream')]; } return $out;
}

function bipolar_delete_entry(PDO $pdo, string $userId): void
{
    $id = bipolar_id_parameter(); $files = []; $pdo->beginTransaction();
    try { $stmt = $pdo->prepare('SELECT a.storage_name FROM attachments a INNER JOIN entries e ON e.id = a.entry_id WHERE e.id = ? AND e.user_id = ? FOR UPDATE'); $stmt->execute([$id, $userId]); $files = array_column($stmt->fetchAll(), 'storage_name'); $stmt = $pdo->prepare('DELETE FROM entries WHERE id = ? AND user_id = ?'); $stmt->execute([$id, $userId]); if ($stmt->rowCount() !== 1) throw new BipolarApiException('not_found', 404); $pdo->commit(); }
    catch (BipolarApiException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw new BipolarApiException('delete_failed', 500); }
    bipolar_unlink_storage($files);
}
function bipolar_upload(PDO $pdo, array $config, string $userId): array
{
    $entryId = $_POST['entryId'] ?? null; $consent = $_POST['consent'] ?? null; if (!is_string($entryId) || !bipolar_is_uuid($entryId) || !($consent === '1' || $consent === 1)) throw new BipolarApiException('invalid_upload', 422); $stmt = $pdo->prepare('SELECT id FROM entries WHERE id = ? AND user_id = ? LIMIT 1'); $stmt->execute([$entryId, $userId]); if (!is_array($stmt->fetch())) throw new BipolarApiException('not_found', 404);
    $clientId = $_POST['clientId'] ?? null;
    if ($clientId !== null && (!is_string($clientId) || !bipolar_is_uuid($clientId))) throw new BipolarApiException('invalid_upload', 422);
    if (is_string($clientId)) {
        $stmt = $pdo->prepare('SELECT entry_id FROM attachments WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$clientId, $userId]); $existing = $stmt->fetch();
        if (is_array($existing)) {
            if ((string) $existing['entry_id'] !== $entryId) throw new BipolarApiException('entry_conflict', 409);
            foreach (bipolar_fetch_attachments($pdo, $config, $entryId, $userId) as $item) if ($item['id'] === $clientId) return $item;
        }
    }
    $file = $_FILES['file'] ?? null; if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) throw new BipolarApiException('invalid_upload', 422); $max = max(1, (int) ($config['limits']['max_file_bytes'] ?? 26214400)); $size = (int) ($file['size'] ?? 0); if ($size < 1 || $size > $max) throw new BipolarApiException('file_too_large', 413);
    $name = bipolar_clean_filename((string) ($file['name'] ?? 'arquivo')); $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION)); $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->file((string) $file['tmp_name']); $allowed = ['webm' => ['audio/webm', 'video/webm'], 'mp4' => ['video/mp4', 'audio/mp4'], 'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg'], 'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'], 'mp3' => ['audio/mpeg', 'audio/mp3'], 'm4a' => ['audio/mp4', 'audio/x-m4a']]; if (!is_string($mime) || !isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) throw new BipolarApiException('file_type_not_allowed', 415); $type = str_starts_with($mime, 'video/') ? 'video' : 'audio'; $kind = $_POST['mediaKind'] ?? null; if (in_array($extension, ['webm','ogg','mp4'], true) && in_array($kind, ['audio','video'], true)) $type = $kind;
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS used FROM attachments WHERE user_id = ?'); $stmt->execute([$userId]); $used = (int) (($stmt->fetch()['used'] ?? 0)); $quota = max($max, (int) ($config['limits']['user_media_bytes'] ?? 262144000)); if ($size > ($quota - $used)) throw new BipolarApiException('quota_exceeded', 413); $contents = file_get_contents((string) $file['tmp_name']); if (!is_string($contents) || strlen($contents) !== $size) throw new BipolarApiException('invalid_upload', 422);
    $storageName = bin2hex(random_bytes(32)) . '.bin'; $target = $config['app']['storage_path'] . DIRECTORY_SEPARATOR . $storageName; $temporary = $target . '.tmp-' . bin2hex(random_bytes(8)); $finalPath = null;
    try { if (file_put_contents($temporary, bipolar_encrypt($contents, $config['app']['encryption_key']), LOCK_EX) === false) throw new BipolarApiException('upload_failed', 500); @chmod($temporary, 0600); if (!rename($temporary, $target)) throw new BipolarApiException('upload_failed', 500); $finalPath = $target; $attachmentId = is_string($clientId) ? $clientId : bipolar_uuid(); $meta = bipolar_encrypt(json_encode(['name' => $name, 'type' => $type, 'mime' => $mime], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $config['app']['encryption_key']); $pdo->beginTransaction(); $stmt = $pdo->prepare('INSERT INTO attachments (id, entry_id, user_id, storage_name, metadata_cipher, size_bytes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'); $stmt->execute([$attachmentId, $entryId, $userId, $storageName, $meta, $size, bipolar_db_now()]); $pdo->commit(); return ['id' => $attachmentId, 'name' => $name, 'type' => $type, 'url' => '/api/index.php?action=media&id=' . rawurlencode($attachmentId), 'mime' => $mime]; }
    catch (BipolarApiException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); if (is_file($temporary)) @unlink($temporary); if (is_string($finalPath) && is_file($finalPath)) @unlink($finalPath); throw $e; } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); if (is_file($temporary)) @unlink($temporary); if (is_string($finalPath) && is_file($finalPath)) @unlink($finalPath); throw new BipolarApiException('upload_failed', 500); }
}
function bipolar_stream_media(PDO $pdo, array $config, string $userId): never
{
    $id = bipolar_id_parameter(); $stmt = $pdo->prepare('SELECT storage_name, metadata_cipher FROM attachments WHERE id = ? AND user_id = ? LIMIT 1'); $stmt->execute([$id, $userId]); $row = $stmt->fetch(); if (!is_array($row)) throw new BipolarApiException('not_found', 404); try { $meta = json_decode(bipolar_decrypt((string) $row['metadata_cipher'], $config['app']['encryption_key']), true, 16, JSON_THROW_ON_ERROR); } catch (Throwable $e) { throw new BipolarApiException('data_unavailable', 500); }
    $storage = (string) $row['storage_name']; if (!preg_match('/^[a-f0-9]{64}\.bin$/', $storage)) throw new BipolarApiException('data_unavailable', 500); $path = $config['app']['storage_path'] . DIRECTORY_SEPARATOR . $storage; if (!is_file($path)) throw new BipolarApiException('not_found', 404); $encrypted = file_get_contents($path); if (!is_string($encrypted)) throw new BipolarApiException('data_unavailable', 500); try { $plain = bipolar_decrypt($encrypted, $config['app']['encryption_key']); } catch (Throwable $e) { throw new BipolarApiException('data_unavailable', 500); }
    if (!is_array($meta)) throw new BipolarApiException('data_unavailable', 500); $mime = (string) ($meta['mime'] ?? 'application/octet-stream'); $name = (string) ($meta['name'] ?? 'arquivo'); $safeName = str_replace(["\r", "\n", '"'], '', $name); header('Content-Type: ' . $mime);  header('Content-Disposition: inline; filename="' . addcslashes($safeName, '\\"') . '"; filename*=UTF-8\'\'' . rawurlencode($safeName)); header('Accept-Ranges: bytes'); $length = strlen($plain); try { $range = bipolar_media_range((string) ($_SERVER['HTTP_RANGE'] ?? ''), $length); } catch (BipolarApiException $e) { header('Content-Range: bytes */' . $length); throw $e; } if ($range !== null) { [$start, $end] = $range; http_response_code(206); header('Content-Range: bytes ' . $start . '-' . $end . '/' . $length); $plain = substr($plain, $start, $end - $start + 1); } header('Content-Length: ' . strlen($plain)); session_write_close(); echo $plain; exit;
}
function bipolar_delete_media(PDO $pdo, string $userId): void
{
    $id = bipolar_id_parameter(); $file = null; $pdo->beginTransaction(); try { $stmt = $pdo->prepare('SELECT storage_name FROM attachments WHERE id = ? AND user_id = ? LIMIT 1 FOR UPDATE'); $stmt->execute([$id, $userId]); $row = $stmt->fetch(); if (!is_array($row)) throw new BipolarApiException('not_found', 404); $file = (string) $row['storage_name']; $stmt = $pdo->prepare('DELETE FROM attachments WHERE id = ? AND user_id = ?'); $stmt->execute([$id, $userId]); $pdo->commit(); } catch (BipolarApiException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw new BipolarApiException('delete_failed', 500); } if (is_string($file)) bipolar_unlink_storage([$file]);
}
function bipolar_delete_account(PDO $pdo, string $userId): void
{
    $body = bipolar_json_body(); if (($body['confirmation'] ?? null) !== 'EXCLUIR') throw new BipolarApiException('confirmation_required', 422); $files = []; $pdo->beginTransaction(); try { $stmt = $pdo->prepare('SELECT storage_name FROM attachments WHERE user_id = ? FOR UPDATE'); $stmt->execute([$userId]); $files = array_column($stmt->fetchAll(), 'storage_name'); $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?'); $stmt->execute([$userId]); if ($stmt->rowCount() !== 1) throw new BipolarApiException('not_found', 404); $pdo->commit(); } catch (BipolarApiException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw new BipolarApiException('delete_failed', 500); } bipolar_unlink_storage($files); bipolar_destroy_session();
}
function bipolar_unlink_storage(array $files): void
{
    $storage = $GLOBALS['bipolar_storage_path'] ?? null; if (!is_string($storage)) return; foreach ($files as $file) if (is_string($file) && preg_match('/^[a-f0-9]{64}\.bin$/', $file)) @unlink($storage . DIRECTORY_SEPARATOR . $file);
}

function bipolar_id_parameter(): string
{
    $id = $_GET['id'] ?? null; if (!is_string($id) || $id === '') { $raw = file_get_contents('php://input'); $body = null; if (is_string($raw) && trim($raw) !== '') { try { $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); } catch (JsonException $e) {} } $id = is_array($body) ? ($body['id'] ?? null) : null; } if (!is_string($id) || !bipolar_is_uuid($id)) throw new BipolarApiException('invalid_id', 422); return $id;
}
function bipolar_string_list(mixed $value): array
{
    if (!is_array($value) || count($value) > 50) throw new BipolarApiException('invalid_entry', 422); $out = []; foreach ($value as $item) { if (!is_string($item) || !bipolar_valid_text($item, 200)) throw new BipolarApiException('invalid_entry', 422); $out[] = $item; } return array_values(array_unique($out));
}
function bipolar_valid_text(string $value, int $max): bool { return strlen($value) <= $max && preg_match('//u', $value) === 1; }
function bipolar_valid_date(string $value, string $timezone): bool
{
    try { $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone($timezone)); return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value; } catch (Throwable $e) { return false; }
}
function bipolar_clean_text(string $value, int $max): string { $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? ''); return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max); }
function bipolar_clean_filename(string $value): string { $value = bipolar_clean_text(basename(str_replace('\\', '/', $value)), 180); return $value !== '' ? $value : 'arquivo'; }
function bipolar_db_now(): string { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
function bipolar_db_to_iso(string $value): string { try { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'); } catch (Throwable $e) { return $value; } }
function bipolar_encrypt(string $plain, string $key): string { $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return $nonce . sodium_crypto_secretbox($plain, $nonce, $key); }
function bipolar_decrypt(string $cipher, string $key): string { $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES; if (strlen($cipher) <= $n) throw new RuntimeException('cipher'); $plain = sodium_crypto_secretbox_open(substr($cipher, $n), substr($cipher, 0, $n), $key); if ($plain === false) throw new RuntimeException('cipher'); return $plain; }
function bipolar_uuid(): string
{
    $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); $hex = bin2hex($bytes); return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}
function bipolar_is_uuid(string $value): bool { return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1; }
function bipolar_random_url(int $bytes): string { return bipolar_base64url(random_bytes($bytes)); }
function bipolar_base64url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function bipolar_destroy_session(): void
{
    $_SESSION = []; if (ini_get('session.use_cookies')) { $params = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, (string) ($params['path'] ?? '/'), (string) ($params['domain'] ?? ''), (bool) ($params['secure'] ?? true), (bool) ($params['httponly'] ?? true)); } if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}
