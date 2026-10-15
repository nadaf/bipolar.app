<?php
// Servidor SMTP falso para testes: grava cada mensagem em <dir>/<n>.eml
// Uso: fake_smtp.php <porta> <pasta> [cert.pem]  (com cert.pem: SSL implícito, como a porta 465)
// Endereços com "rejeitar" no RCPT recebem 550 (simula caixa inexistente).
[$_, $port, $dir] = $argv;
$cert = $argv[3] ?? '';
$ctx = stream_context_create($cert ? ['ssl' => ['local_cert' => $cert, 'verify_peer' => false]] : []);
$srv = stream_socket_server(($cert ? 'ssl' : 'tcp') . "://127.0.0.1:$port", $e, $s, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
$n = 0;
while ($c = @stream_socket_accept($srv, -1)) {
    $w = function ($l) use ($c) { fwrite($c, $l . "\r\n"); };
    $w('220 fake');
    $data = false; $buf = ''; $auth = 0;
    while (($line = fgets($c)) !== false) {
        if ($data) {
            if (rtrim($line, "\r\n") === '.') { $data = false; file_put_contents(sprintf('%s/%06d-%d.eml', $dir, ++$n, getmypid()), $buf); $buf = ''; $w('250 queued'); continue; }
            $buf .= $line; continue;
        }
        if ($auth > 0) { $w(--$auth ? '334 UGFzc3dvcmQ6' : '235 ok'); continue; }
        $cmd = strtoupper(substr(trim($line), 0, 4));
        if ($cmd === 'AUTH') { $auth = 2; $w('334 VXNlcm5hbWU6'); continue; }
        if ($cmd === 'DATA') { $data = true; $w('354 go'); }
        elseif ($cmd === 'QUIT') { $w('221 bye'); break; }
        elseif ($cmd === 'RCPT' && stripos($line, 'rejeitar') !== false) { $w('550 5.1.1 <' . trim(substr(trim($line), 8), '<>') . '>: Recipient address rejected: User unknown'); }
        elseif ($cmd === 'RCPT' || $cmd === 'MAIL') { $buf .= 'X-Envelope: ' . trim($line) . "\r\n"; $w('250 ok'); }
        else $w('250 ok');
    }
    fclose($c);
}
