<?php
// Login de desenvolvimento — só funciona com 'dev_login' => true no config.php E acesso local.
require __DIR__ . '/../_boot.php';
if (!cfg('dev_login') || !in_array(client_ip(), ['127.0.0.1', '::1'], true)) { http_response_code(404); exit; }
$pdo = db();
$sub = 'dev' . substr(preg_replace('/[^a-z0-9]/', '', (string)($_GET['u'] ?? '')), 0, 16);
$st = $pdo->prepare("SELECT user_id FROM oauth_identities WHERE provider = 'dev' AND subject = ?");
$st->execute([$sub]);
$uid = $st->fetchColumn();
if (!$uid) {
    // V5.1: no modo de teste local o e-mail de exemplo conta como comprovado (como um provedor que confirma o e-mail)
    $pdo->prepare('INSERT INTO users (name, email, email_verified_at, email_verified_email) VALUES (?, ?, UTC_TIMESTAMP(), ?)')->execute(['Pessoa de Teste', $sub . '@example.com', $sub . '@example.com']);
    $uid = (int)$pdo->lastInsertId();
    identity_insert($pdo, $uid, 'dev', $sub);
}
login_user((int)$uid);
$aba = preg_replace('/[^a-z]/', '', (string)($_GET['aba'] ?? ''));
redirect('/' . ($aba ? '?aba=' . $aba : ''));
