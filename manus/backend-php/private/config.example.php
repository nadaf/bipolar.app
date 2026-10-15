<?php
/*
 * Copie para /home/SEU_USUARIO/bipolar-private/config.php e substitua os
 * valores. Nunca publique este arquivo nem comite um config.php real.
 * Gere a chave com:
 *   php -r "echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), PHP_EOL;"
 */
return [
    'app' => [
        // URL canônica usada para montar os callbacks fixos dos provedores.
        'base_url' => 'https://bipolar.app.br',
        'timezone' => 'America/Sao_Paulo',
        'encryption_key_b64' => '', // exatamente 32 bytes em base64
        'storage_path' => '/home/SEU_USUARIO/bipolar-private/storage',
    ],
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=bipolar;charset=utf8mb4',
        'user' => 'bipolar_user',
        'password' => 'COLOQUE_A_SENHA_FORA_DO_PUBLIC_HTML',
        'options' => [],
    ],
    'oauth' => [
        'google' => [
            'client_id' => '',
            'client_secret' => '',
        ],
        'x' => [
            'client_id' => '',
            'client_secret' => '',
        ],
        'microsoft' => [
            'client_id' => '',
            'client_secret' => '',
        ],
    ],
    'session' => [
        'name' => 'bipolar_sid',
        'idle_ttl' => 1800,
        'absolute_ttl' => 28800,
        'cookie_secure' => true, // explícito; não derive de NODE_ENV
        'cookie_samesite' => 'Lax',
        'allow_none_secure' => false, // só habilite para preview HTTPS embutido
    ],
    'limits' => [
        'max_file_bytes' => 26214400, // 25 MiB por arquivo
        'user_media_bytes' => 262144000, // 250 MiB por usuário
        'rate_window_seconds' => 900,
        'oauth_rate_limit' => 20,
        'upload_rate_limit' => 30,
    ],
    // Relatórios/reminders por e-mail só funcionam com TLS explícito:
    // cPanel normalmente usa SMTPS/465 ou STARTTLS/587. Deixe disabled até configurar.
    'smtp' => [
        'enabled' => false,
        'host' => '',
        'port' => 587, // somente 465 (SMTPS) ou 587 (STARTTLS)
        'username' => '',
        'password' => '',
        'from_name' => 'Bipolar.app.br',
        'from_email' => '',
    ],
    'push' => [
        'vapid' => [
            'enabled' => false,
            'subject' => 'mailto:admin@example.invalid',
            'public_key' => '',
            'private_key' => '',
        ],
    ],
    'automation' => [
        'max_deliveries_per_run' => 100,
    ],
];
