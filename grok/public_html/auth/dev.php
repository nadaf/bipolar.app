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
    $pdo->prepare('INSERT INTO users (name, email) VALUES (?, ?)')->execute(['Pessoa de Teste', $sub . '@example.com']);
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO oauth_identities (user_id, provider, subject) VALUES (?, 'dev', ?)")->execute([$uid, $sub]);
}
login_user((int)$uid);
$aba = preg_replace('/[^a-z]/', '', (string)($_GET['aba'] ?? ''));
redirect('/' . ($aba ? '?aba=' . $aba : ''));
