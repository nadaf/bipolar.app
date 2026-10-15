<?php
/**
 * Gera as chaves VAPID das notificações push — sem SSH:
 *   cPanel → Cron Jobs → "Uma vez" (ou a cada minuto e apague depois) →  php /home/USUARIO/tools/generate_vapid.php
 * O script grava storage/vapid.php (permissão 600). O app passa a usar essas chaves automaticamente quando
 * 'vapid' no config.php estiver vazio. Se preferir, copie o trecho de storage/vapid-config.txt para o config.php.
 * Nunca sobrescreve chaves existentes (trocar as chaves invalida as assinaturas dos aparelhos): use --forcar para isso.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
foreach ([__DIR__ . '/../app', __DIR__ . '/../bipolar_app'] as $d) {
    if (is_file($d . '/bootstrap.php')) { require_once $d . '/bootstrap.php'; break; }
}
if (!defined('APP_ROOT')) { fwrite(STDERR, "Pasta app não encontrada\n"); exit(1); }
require_once APP_ROOT . '/lib/webpush.php';

$file = STORAGE_DIR . '/vapid.php';
$force = in_array('--forcar', $argv ?? [], true);
if (is_file($file) && !$force) { echo "Já existe $file — nada foi alterado. (Use --forcar para gerar novas chaves.)\n"; exit(0); }
if (push_configured() && !$force) { echo "As chaves VAPID já estão no config.php — nada foi alterado.\n"; exit(0); }
try { [$pem, $pub] = p256_generate(); } catch (Throwable $e) { fwrite(STDERR, 'Falha: ' . $e->getMessage() . "\n"); exit(1); }
$subject = (string)cfg('vapid.subject', '') ?: 'mailto:contato@' . (parse_url((string)cfg('app_url'), PHP_URL_HOST) ?: 'bipolar.app.br');
$keys = ['subject' => $subject, 'public_key' => b64url($pub), 'private_key_pem' => $pem];
if (!is_dir(STORAGE_DIR)) @mkdir(STORAGE_DIR, 0700, true);
$php = "<?php\n// Gerado por tools/generate_vapid.php em " . gmdate('c') . " (UTC). NÃO publique este arquivo.\nreturn " . var_export($keys, true) . ";\n";
if (file_put_contents($file, $php, LOCK_EX) === false) { fwrite(STDERR, "Não consegui gravar $file\n"); exit(1); }
@chmod($file, 0600);
$snippet = "'vapid' => " . var_export($keys, true) . ",\n";
file_put_contents(STORAGE_DIR . '/vapid-config.txt', $snippet, LOCK_EX);
@chmod(STORAGE_DIR . '/vapid-config.txt', 0600);
echo "Chaves VAPID gravadas em $file (o app já usa automaticamente).\nTrecho opcional para o config.php (também em storage/vapid-config.txt):\n\n$snippet";
