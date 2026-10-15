<?php
/** Cliente SMTP mínimo (SSL implícito ou STARTTLS, AUTH LOGIN), sem dependências. */
function html_to_text(string $html): string {
    return trim(html_entity_decode(strip_tags((string)preg_replace('/<(br|\/p|\/tr|\/h\d|\/li)\s*\/?>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
}
function mime_header(string $s): string { return '=?UTF-8?B?' . base64_encode($s) . '?='; }
/** Monta a mensagem MIME (multipart/alternative). Linhas com "." inicial são duplicadas (dot-stuffing). */
function build_mime_message(string $to, string $subject, string $html, string $text = ''): string {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) throw new InvalidArgumentException('Destinatário inválido');
    if ($text === '') $text = html_to_text($html);
    $c = cfg('smtp');
    $host = parse_url((string)cfg('app_url'), PHP_URL_HOST) ?: 'localhost';
    $b = 'b' . bin2hex(random_bytes(12));
    $msg = 'Date: ' . date('r') . "\r\n"
        . 'From: ' . mime_header((string)$c['from_name']) . ' <' . $c['from_email'] . ">\r\n"
        . "To: <$to>\r\n"
        . 'Subject: ' . mime_header($subject) . "\r\n"
        . 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $host . ">\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n"
        . "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text))
        . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . "--$b--\r\n";
    return (string)preg_replace('/^\./m', '..', $msg);
}
/** Mascara o e-mail para o log (LGPD): "jo***@dominio.com". */
function mask_email(string $e): string {
    $at = strrpos($e, '@');
    if ($at === false) return '***';
    return mb_substr($e, 0, min(2, $at)) . '***' . substr($e, $at);
}
function send_mail(string $to, string $subject, string $html, string $text = '', string $context = 'email'): bool {
    return send_mail_ex($to, $subject, $html, $text, $context)['ok'];
}
/**
 * Envia e-mail e devolve o resultado detalhado. Toda falha é registrada no error_log do PHP
 * com a etapa (connect, tls, auth, mail_from, rcpt, data...) e a resposta do servidor.
 * @return array{ok:bool, stage:string, code:int, reply:string, recipient_rejected:bool}
 */
function send_mail_ex(string $to, string $subject, string $html, string $text = '', string $context = 'email'): array {
    $res = ['ok' => false, 'stage' => 'validate', 'code' => 0, 'reply' => '', 'recipient_rejected' => false];
    $c = cfg('smtp');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) {
        $res['recipient_rejected'] = true; $res['reply'] = 'endereço inválido';
        error_log("SMTP [$context] destinatário inválido: " . mask_email($to));
        return $res;
    }
    $secure = (string)($c['secure'] ?? '');
    $host = (string)$c['host'];
    $port = (int)$c['port'];
    // SSL implícito (465): TLS desde a conexão; SNI e verificação do certificado pelo nome do host.
    $ctx = stream_context_create(['ssl' => [
        'peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true,
        'allow_self_signed' => !empty($c['allow_self_signed']),
    ]]);
    $res['stage'] = 'connect';
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $res['reply'] = "$errno $errstr";
        $hint = ($secure === 'ssl' && $port === 587) ? ' (dica: porta 587 usa secure=tls)' : (($secure === 'tls' && $port === 465) ? ' (dica: porta 465 usa secure=ssl)' : '');
        error_log("SMTP [$context] falha na conexão com $host:$port ($secure): $errno $errstr$hint");
        return $res;
    }
    stream_set_timeout($fp, 30);
    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break; // última linha: "250 ..." (multilinha usa "250-")
        }
        if ($data === '' && stream_get_meta_data($fp)['timed_out']) return '000 tempo esgotado aguardando o servidor';
        return $data;
    };
    $write = function (string $data) use ($fp): void {
        // fwrite em fluxo SSL pode gravar só parte dos bytes: repete até enviar tudo
        $len = strlen($data);
        $off = 0;
        while ($off < $len) {
            $w = fwrite($fp, substr($data, $off, 8192));
            if ($w === false || $w === 0) throw new RuntimeException('conexão encerrada durante o envio');
            $off += $w;
        }
    };
    $cmd = function (string $stage, string $line, array $ok) use ($write, $read, &$res): string {
        $res['stage'] = $stage;
        if ($line !== '') $write($line . "\r\n");
        $r = $read();
        $res['code'] = (int)substr($r, 0, 3);
        $res['reply'] = trim(preg_replace('/\s+/', ' ', $r) ?? '');
        if (!in_array($res['code'], $ok, true)) throw new RuntimeException('resposta inesperada');
        return $r;
    };
    try {
        $cmd('greeting', '', [220]);
        $ehlo = 'EHLO ' . (parse_url((string)cfg('app_url'), PHP_URL_HOST) ?: 'localhost');
        $cmd('ehlo', $ehlo, [250]);
        if ($secure === 'tls') {
            $cmd('starttls', 'STARTTLS', [220]);
            $res['stage'] = 'tls';
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('falha no STARTTLS');
            $cmd('ehlo', $ehlo, [250]);
        }
        if (!empty($c['user'])) {
            $cmd('auth', 'AUTH LOGIN', [334]);
            $cmd('auth', base64_encode((string)$c['user']), [334]);
            $cmd('auth', base64_encode((string)$c['pass']), [235]);
        }
        $cmd('mail_from', 'MAIL FROM:<' . $c['from_email'] . '>', [250]);
        $cmd('rcpt', 'RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('data', 'DATA', [354]);
        $write(build_mime_message($to, $subject, $html, $text) . "\r\n.\r\n");
        $cmd('data_end', '', [250]);
        $res['ok'] = true;
        try { $cmd('quit', 'QUIT', [221]); } catch (Throwable $q) { /* entregue; ignorar QUIT */ }
    } catch (Throwable $ex) {
        $res['reply'] = str_ireplace($to, mask_email($to), $res['reply']); // não grava o e-mail de terceiros completo no log
        if ($res['stage'] === 'rcpt' && $res['code'] >= 500) {
            $res['recipient_rejected'] = true;
            error_log("SMTP [$context] DESTINATÁRIO RECUSADO " . mask_email($to) . ' pelo servidor ' . $host . ': ' . $res['reply'] . ' (o endereço pode não existir)');
        } else {
            error_log("SMTP [$context] falha na etapa {$res['stage']} ($host:$port $secure) para " . mask_email($to) . ': ' . ($res['reply'] !== '' ? $res['reply'] : $ex->getMessage()));
        }
    }
    @fclose($fp);
    return $res;
}
function email_layout(string $title, string $inner): string {
    return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>' . e($title) . '</title></head>'
        . '<body style="margin:0;background:#f3f6f8;font-family:Segoe UI,Roboto,Arial,sans-serif;color:#1d2b36">'
        . '<div style="max-width:640px;margin:0 auto;padding:24px"><div style="background:#fff;border-radius:14px;padding:24px">'
        . '<h1 style="font-size:20px;margin:0 0 16px;color:#2f6f73">' . e($title) . '</h1>' . $inner
        . '</div><p style="font-size:12px;color:#50606c;margin-top:16px">Este aplicativo não substitui acompanhamento profissional. '
        . 'Em crise, ligue CVV 188 (24h, gratuito) ou SAMU 192.</p></div></body></html>';
}
