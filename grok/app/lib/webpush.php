<?php
/**
 * Web Push com VAPID (RFC 8292) e criptografia de payload aes128gcm (RFC 8291 + RFC 8188), só com OpenSSL.
 * O texto das notificações é genérico ("Como está seu humor nesta tarde?") — nunca dados de saúde.
 */
function vapid_jwt(string $audience): string {
    $header = b64url((string)json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = b64url((string)json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => cfg('vapid.subject')]));
    $input = "$header.$claims";
    $key = openssl_pkey_get_private((string)cfg('vapid.private_key_pem'));
    if (!$key || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Chave VAPID inválida');
    return $input . '.' . b64url(der_to_raw_sig($der));
}
function der_to_raw_sig(string $der): string {
    $off = 2; // 0x30 len
    if (ord($der[1]) & 0x80) $off += ord($der[1]) & 0x7f;
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        $off++; // 0x02
        $len = ord($der[$off++]);
        $v = substr($der, $off, $len);
        $off += $len;
        $v = ltrim($v, "\x00");
        $parts[] = str_pad($v, 32, "\x00", STR_PAD_LEFT);
    }
    return $parts[0] . $parts[1];
}

/** HKDF-SHA256 (RFC 5869) com um único bloco de saída (L ≤ 32). */
function hkdf_sha256(string $salt, string $ikm, string $info, int $len): string {
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $len);
}

/** Converte ponto público P-256 não comprimido (65 bytes) em chave PEM SubjectPublicKeyInfo. */
function p256_public_pem(string $point): string {
    if (strlen($point) !== 65 || $point[0] !== "\x04") throw new InvalidArgumentException('Chave P-256 inválida');
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
/** Chave privada P-256 (d de 32 bytes + ponto público) em PEM SEC1. */
function p256_private_pem(string $d, string $point): string {
    $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $point;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}
/** @return array{0:string,1:string} [pem privada, ponto público 65 bytes] */
function p256_generate(): array {
    $args = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
    if (getenv('OPENSSL_CONF')) $args['config'] = (string)getenv('OPENSSL_CONF');
    $k = openssl_pkey_new($args);
    if (!$k) throw new RuntimeException('OpenSSL não gerou a chave: ' . openssl_error_string());
    openssl_pkey_export($k, $pem, null, $args);
    $d = openssl_pkey_get_details($k);
    $pt = "\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    return [(string)$pem, $pt];
}

/**
 * Criptografa o payload (RFC 8291, content-coding aes128gcm de um único registro).
 * $asPrivatePem/$asPublic/$salt só são informados em testes (vetor da RFC 8291 §5).
 */
function webpush_encrypt(string $plaintext, string $uaPublic, string $authSecret, ?string $asPrivatePem = null, ?string $asPublic = null, ?string $salt = null): string {
    if ($asPrivatePem === null || $asPublic === null) [$asPrivatePem, $asPublic] = p256_generate();
    $salt = $salt ?? random_bytes(16);
    $ecdh = openssl_pkey_derive(openssl_pkey_get_public(p256_public_pem($uaPublic)), openssl_pkey_get_private($asPrivatePem), 32);
    if ($ecdh === false) throw new RuntimeException('Falha no ECDH');
    $ikm = hkdf_sha256($authSecret, $ecdh, "WebPush: info\x00" . $uaPublic . $asPublic, 32);
    $cek = hkdf_sha256($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = hkdf_sha256($salt, $ikm, "Content-Encoding: nonce\x00", 12);
    $rs = 4096;
    if (strlen($plaintext) + 1 + 16 > $rs) throw new InvalidArgumentException('Payload grande demais');
    $ct = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) throw new RuntimeException('Falha no AES-GCM');
    return $salt . pack('N', $rs) . chr(strlen($asPublic)) . $asPublic . $ct . $tag;
}

/**
 * Envia um push. Com p256dh/auth e payload, o texto vai criptografado; sem eles, push vazio (notificação genérica).
 * @param array{endpoint:string,p256dh?:?string,auth?:?string} $sub
 * @param array<string,mixed>|null $payload
 * @return int status HTTP (201 = ok; 404/410 = assinatura expirada)
 */
function send_push(array $sub, ?array $payload = null, string $urgency = 'normal'): int {
    $endpoint = $sub['endpoint'];
    $u = parse_url($endpoint);
    if (!is_array($u) || ($u['scheme'] ?? '') !== 'https' || empty($u['host'])) return 0;
    $aud = $u['scheme'] . '://' . $u['host'];
    $headers = ['TTL: 14400', 'Urgency: ' . $urgency, 'Authorization: vapid t=' . vapid_jwt($aud) . ', k=' . cfg('vapid.public_key')];
    $body = '';
    $p256 = b64url_decode((string)($sub['p256dh'] ?? ''));
    $auth = b64url_decode((string)($sub['auth'] ?? ''));
    if ($payload !== null && strlen($p256) === 65 && strlen($auth) === 16) {
        $body = webpush_encrypt((string)json_encode($payload, JSON_UNESCAPED_UNICODE), $p256, $auth);
        $headers[] = 'Content-Encoding: aes128gcm';
        $headers[] = 'Content-Type: application/octet-stream';
        if (!empty($payload['tag'])) $headers[] = 'Topic: ' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$payload['tag']) ?? '', 0, 32);
    }
    $headers[] = 'Content-Length: ' . strlen($body);
    $r = bp_http_request('POST', $endpoint, $headers, $body);
    return $r['status'];
}

/**
 * Envia para todos os aparelhos do usuário; remove assinaturas expiradas. Retorna quantos receberam.
 * @param array<string,mixed> $payload
 */
function push_to_user(int $uid, array $payload, ?int $onlySubId = null, string $urgency = 'normal'): int {
    if (!push_configured()) return 0;
    $pdo = db();
    $st = $pdo->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?' . ($onlySubId ? ' AND id = ' . (int)$onlySubId : ''));
    $st->execute([$uid]);
    $ok = 0;
    foreach ($st->fetchAll() as $s) {
        try {
            $code = send_push($s, $payload, $urgency);
            if ($code >= 200 && $code < 300) { $ok++; $pdo->prepare('UPDATE push_subscriptions SET last_ok_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$s['id']]); }
            elseif (in_array($code, [404, 410], true)) $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$s['id']]);
            else error_log("push: HTTP $code para assinatura {$s['id']}");
        } catch (Throwable $e) { error_log('push: ' . $e->getMessage()); }
    }
    return $ok;
}

/**
 * Texto do lembrete por período do dia (sem dados de saúde).
 * @return array<string,mixed>
 */
function reminder_payload(string $tz): array {
    $h = (int)(new DateTime('now', new DateTimeZone($tz)))->format('G');
    $p = $h < 12 ? 'manha' : ($h < 18 ? 'tarde' : 'noite');
    $txt = ['manha' => 'nesta manhã', 'tarde' => 'nesta tarde', 'noite' => 'nesta noite'][$p];
    return ['title' => 'Bipolar — hora do registro', 'body' => "Como está seu humor $txt?", 'url' => '/registro-rapido.php?p=' . $p,
        'tag' => 'lembrete-humor', 'actions' => [['action' => 'registrar', 'title' => 'Registrar agora'], ['action' => 'snooze', 'title' => 'Lembrar em 30 min']]];
}
