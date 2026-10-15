<?php
/** OAuth2 + PKCE para Google, Microsoft (Entra ID) e X, sem dependências. */
/** @return array<string,array<string,string>> */
function oauth_providers(): array {
    $ms = cfg('oauth.microsoft.tenant', 'common');
    return [
        'google' => [
            'label' => 'Google',
            'auth' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scope' => 'openid email profile',
        ],
        'microsoft' => [
            'label' => 'Microsoft',
            'auth' => "https://login.microsoftonline.com/$ms/oauth2/v2.0/authorize",
            'token' => "https://login.microsoftonline.com/$ms/oauth2/v2.0/token",
            'userinfo' => 'https://graph.microsoft.com/oidc/userinfo',
            'scope' => 'openid email profile User.Read',
        ],
        'x' => [
            'label' => 'X',
            'auth' => 'https://twitter.com/i/oauth2/authorize',
            'token' => 'https://api.twitter.com/2/oauth2/token',
            'userinfo' => 'https://api.twitter.com/2/users/me',
            'scope' => 'users.read tweet.read',
        ],
    ];
}
/**
 * V4.4: valor ainda de exemplo? Vazio, ou com marcadores do config.example.php ("SEU_CLIENT_ID...", "SEU_X_CLIENT_SECRET",
 * "TROQUE...", "...", "<...>", "xxxx"), em qualquer posição e sem diferenciar maiúsculas.
 */
function oauth_placeholder(string $v): bool {
    $v = trim($v);
    if ($v === '') return true;
    if (preg_match('/SEU_|SUA_|YOUR_|TROQUE|PREENCHA|COLE_AQUI|CHANGE_?ME|PLACEHOLDER|\.\.\.|…|[<>]|^x{4,}$/i', $v)) return true;
    return false;
}
/** Um provedor só aparece se tiver credenciais preenchidas (não vazias nem placeholders do exemplo). */
function oauth_configured(string $p): bool {
    $id = (string)cfg("oauth.$p.client_id", '');
    $secret = trim((string)cfg("oauth.$p.client_secret", ''));
    if (oauth_placeholder($id)) return false;
    if ($p === 'x') return $secret === '' || !oauth_placeholder($secret); // X permite cliente público (sem segredo)
    return !oauth_placeholder($secret);
}
/** @return array<string,array<string,string>> */
function oauth_enabled_providers(): array {
    return array_filter(oauth_providers(), function ($k) { return oauth_configured($k); }, ARRAY_FILTER_USE_KEY);
}
/**
 * V4.4: URI de retorno SEM parâmetros (https://bipolar.app.br/auth/callback.php), igual para os 3 provedores.
 * A Microsoft não aceita query string na URI de apps com contas pessoais (tenant 'common'). O provedor vem da sessão (state).
 * 'oauth.<provedor>.redirect_uri' no config.php permite manter uma URI antiga já cadastrada no console.
 */
function oauth_redirect_uri(string $p): string {
    $custom = trim((string)cfg("oauth.$p.redirect_uri", ''));
    return $custom !== '' ? $custom : rtrim((string)cfg('app_url'), '/') . '/auth/callback.php';
}

function pkce_challenge(string $verifier): string { return b64url(hash('sha256', $verifier, true)); }

function oauth_start(string $p): void {
    $url = oauth_auth_url($p);
    if ($url === null) { http_response_code(400); exit('Provedor indisponível.'); }
    redirect($url);
}
/** Gera state + PKCE, guarda na sessão e devolve a URL de autorização (ou null se provedor inválido). */
function oauth_auth_url(string $p): ?string {
    $prov = oauth_providers()[$p] ?? null;
    if (!$prov || !oauth_configured($p)) return null;
    $state = b64url(random_bytes(24));
    $verifier = b64url(random_bytes(48));
    $_SESSION['oauth'] = ['p' => $p, 'state' => $state, 'verifier' => $verifier, 't' => time()];
    $q = [
        'response_type' => 'code',
        'client_id' => cfg("oauth.$p.client_id"),
        'redirect_uri' => oauth_redirect_uri($p),
        'scope' => $prov['scope'],
        'state' => $state,
        'code_challenge' => pkce_challenge($verifier),
        'code_challenge_method' => 'S256',
    ];
    if ($p === 'google') $q['prompt'] = 'select_account';
    if ($p === 'microsoft') $q['response_mode'] = 'query';
    return $prov['auth'] . '?' . http_build_query($q);
}

/** @return array{subject:string,name:string,email:?string} */
function oauth_callback(string $p): array {
    $prov = oauth_providers()[$p] ?? null;
    $s = $_SESSION['oauth'] ?? null;
    unset($_SESSION['oauth']);
    if (!$prov || !$s || $s['p'] !== $p || time() - $s['t'] > 600) throw new RuntimeException('Sessão de login expirada. Tente novamente.');
    if (!is_string($_GET['state'] ?? null) || !hash_equals($s['state'], $_GET['state'])) throw new RuntimeException('Parâmetro state inválido.');
    if (!empty($_GET['error'])) throw new RuntimeException('Login cancelado ou negado pelo provedor.');
    $code = (string)($_GET['code'] ?? '');
    if ($code === '') throw new RuntimeException('Código de autorização ausente.');

    $cid = cfg("oauth.$p.client_id");
    $secret = (string)cfg("oauth.$p.client_secret", '');
    $form = [
        'grant_type' => 'authorization_code', 'code' => $code,
        'redirect_uri' => oauth_redirect_uri($p), 'code_verifier' => $s['verifier'], 'client_id' => $cid,
    ];
    $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
    if ($p === 'x' && $secret !== '') {
        $headers[] = 'Authorization: Basic ' . base64_encode(rawurlencode($cid) . ':' . rawurlencode($secret));
    } elseif ($secret !== '') {
        $form['client_secret'] = $secret;
    }
    $r = bp_http_request('POST', $prov['token'], $headers, http_build_query($form));
    $tok = json_decode($r['body'], true);
    if ($r['status'] !== 200 || empty($tok['access_token'])) throw new RuntimeException('Falha ao obter token do provedor.');

    $r = bp_http_request('GET', $prov['userinfo'], ['Authorization: Bearer ' . $tok['access_token'], 'Accept: application/json']);
    $info = json_decode($r['body'], true);
    if ($r['status'] !== 200 || !is_array($info)) throw new RuntimeException('Falha ao obter dados do perfil.');

    if ($p === 'x') {
        $d = $info['data'] ?? [];
        return ['subject' => (string)($d['id'] ?? ''), 'name' => (string)($d['name'] ?? $d['username'] ?? ''), 'email' => null];
    }
    $email = $info['email'] ?? null;
    if ($p === 'google' && empty($info['email_verified'])) $email = null;
    return ['subject' => (string)($info['sub'] ?? ''), 'name' => (string)($info['name'] ?? ''), 'email' => $email ?: null];
}

/** @param array{subject:string,name:string,email:?string} $id */
function oauth_login_or_register(string $p, array $id): int {
    if ($id['subject'] === '') throw new RuntimeException('Identificador do usuário ausente.');
    $pdo = db();
    $st = $pdo->prepare('SELECT user_id FROM oauth_identities WHERE provider = ? AND subject = ?');
    $st->execute([$p, $id['subject']]);
    $uid = $st->fetchColumn();
    if (!$uid) {
        // Não vincula automaticamente por e-mail (evita sequestro de conta entre provedores).
        $pdo->prepare('INSERT INTO users (name, email, timezone) VALUES (?, ?, ?)')
            ->execute([mb_substr($id['name'], 0, 150), $id['email'], cfg('default_timezone', 'America/Cuiaba')]);
        $uid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO oauth_identities (user_id, provider, subject) VALUES (?, ?, ?)')->execute([$uid, $p, $id['subject']]);
    }
    return (int)$uid;
}
