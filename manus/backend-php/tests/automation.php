<?php
declare(strict_types=1);

// Run only with a disposable database whose name contains bipolar_test.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/private/bootstrap.php';
$dsn = getenv('BIPOLAR_TEST_DSN');
if (!$dsn || !str_contains($dsn, 'bipolar_test')) { fwrite(STDERR, "Use BIPOLAR_TEST_DSN com um banco exclusivo bipolar_test.\n"); exit(1); }
$pdo = new PDO($dsn, getenv('BIPOLAR_TEST_USER') ?: 'root', getenv('BIPOLAR_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec(file_get_contents(dirname(__DIR__) . '/schema.sql'));
$count = 0;
function ok(bool $condition, string $label): void { global $count; if (!$condition) throw new RuntimeException('Falhou: ' . $label); $count++; echo "OK: $label\n"; }
function expect_code(callable $fn, string $code): void { try { $fn(); } catch (BipolarApiException $e) { ok($e->getMessage() === $code, $code); return; } throw new RuntimeException('Esperado: ' . $code); }
$key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES); $user = bipolar_uuid(); $other = bipolar_uuid(); $now = bipolar_db_now();
$config = ['app' => ['encryption_key' => $key, 'timezone' => 'America/Sao_Paulo', 'base_url' => 'https://bipolar.app.br'], 'smtp' => ['enabled' => false], 'push' => ['vapid' => ['enabled' => false]], 'automation' => []];
$pdo->prepare('INSERT INTO users(id,name,email,created_at,updated_at) VALUES(?,?,?,?,?)')->execute([$user, 'Teste automação', null, $now, $now]);
$pdo->prepare('INSERT INTO users(id,name,email,created_at,updated_at) VALUES(?,?,?,?,?)')->execute([$other, 'Outro usuário', null, $now, $now]);
try {
    $settings = bipolar_aut_defaults(); $recipientId = bipolar_uuid(); $settings['reports']['recipients'] = [['id' => $recipientId, 'name' => 'Pessoa <teste>', 'email' => 'destino@example.invalid', 'role' => 'other']];
    $saved = bipolar_aut_save_settings($pdo, $config, $user, ['settings' => $settings]); ok($saved['settings']['reports']['recipients'][0]['email'] === 'destino@example.invalid', 'preferência devolvida após salvar');
    $raw = $pdo->query('SELECT settings_cipher FROM automation_settings')->fetchColumn(); ok(!str_contains((string)$raw, 'America/Sao_Paulo') && !str_contains((string)$raw, 'destino@example.invalid'), 'preferência e destinatário não ficam em claro');
    $rawRecipient = $pdo->query('SELECT name_cipher, email_cipher FROM automation_recipients')->fetch(); ok(!str_contains((string)$rawRecipient['name_cipher'], 'Pessoa') && !str_contains((string)$rawRecipient['email_cipher'], 'destino@example.invalid'), 'nome e e-mail do destinatário cifrados');
    expect_code(fn() => bipolar_aut_send_report($pdo, $config, $user, ['from' => '2026-10-01', 'to' => '2026-10-02', 'recipientIds' => [$recipientId], 'includeNotes' => false, 'includeDetails' => false, 'consent' => true, 'idempotencyKey' => bipolar_uuid()]), 'automation_not_configured');
    expect_code(fn() => bipolar_aut_push_endpoint('http://127.0.0.1/unsafe'), 'invalid_push_subscription');
    $p256dh = bipolar_base64url(str_repeat('P', 65)); $auth = bipolar_base64url(str_repeat('A', 16)); bipolar_aut_push_subscribe($pdo, $config, $user, ['subscription' => ['endpoint' => 'https://fcm.googleapis.com/test/synthetic', 'keys' => ['p256dh' => $p256dh, 'auth' => $auth]]]); ok((int)$pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn() === 1, 'subscription push sintética salva');
    bipolar_aut_push_unsubscribe($pdo, $config, $other, ['endpoint' => 'https://fcm.googleapis.com/test/synthetic']); ok((int)$pdo->query('SELECT COUNT(*) FROM push_subscriptions WHERE disabled_at IS NULL')->fetchColumn() === 1, 'unsubscribe de outro usuário não remove subscription');
    $config['automation']['send_callback'] = static function (array $job, array $payload): void { if (($payload['synthetic'] ?? false) !== true) throw new RuntimeException('payload'); };
    $pdo->beginTransaction(); ok(bipolar_aut_queue($pdo, $config, $user, 'reminder', 'email', 'synthetic-event', null, null, 'Você', ['synthetic' => true, 'recipientEmail' => 'never@example.invalid'], false), 'outbox sintético enfileirado'); $pdo->commit();
    ok(!bipolar_aut_queue($pdo, $config, $user, 'reminder', 'email', 'synthetic-event', null, null, 'Você', ['synthetic' => true], false), 'idempotência impede duplicata');
    $worker = bipolar_aut_worker($pdo, $config, 100); ok($worker['sent'] === 1 && $worker['failed'] === 0, 'worker usa callback sem rede e marca sent'); ok(bipolar_aut_delivery_history($pdo, $config, $user)['deliveries'][0]['status'] === 'sent', 'histórico limitado devolve status genérico');
    $config['automation']['clock'] = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-14 18:00:00', new DateTimeZone('UTC')); $cron = bipolar_aut_cron_run($pdo, $config); ok($cron['locked'] === false && $cron['queued'] === 0 && $pdo->query('SELECT last_run_at FROM automation_cron_heartbeat WHERE id = 1')->fetchColumn() !== false, 'cron usa relógio injetado e grava heartbeat UTC');
    $local = new DateTimeImmutable('2026-10-14 18:00:00', new DateTimeZone('America/Sao_Paulo')); $reports = bipolar_aut_defaults()['reports']; $reports['enabled'] = true; $reports['consent'] = true; $reports['frequency'] = 'daily'; $reports['time'] = '18:00'; ok(bipolar_aut_report_period($reports, $local) === ['2026-10-13', '2026-10-13'], 'período diário usa dia anterior'); ok(bipolar_aut_in_quiet('23:00', '22:00', '07:00') && !bipolar_aut_in_quiet('12:00', '22:00', '07:00'), 'silêncio que cruza meia-noite'); ok(!bipolar_aut_in_quiet('12:00', '12:00', '12:00'), 'quietStart igual quietEnd desativa silêncio');
    $html = bipolar_aut_render_report(['from' => '2026-10-01', 'to' => '2026-10-01', 'daysWithRecords' => 1, 'recordCount' => 1, 'moodDistribution' => ['estável' => 1], 'records' => [['date' => '2026-10-01', 'period' => 'morning', 'mood' => 0, 'moodLabel' => 'estável', 'note' => '<não é HTML>']]], 'Pessoa & teste'); ok(str_contains($html['html'], '&lt;não é HTML&gt;') && !str_contains($html['html'], '<não é HTML>'), 'relatório HTML escapa nota e usa UTF-8');
} finally { $pdo->prepare('DELETE FROM users WHERE id IN (?,?)')->execute([$user, $other]); }
echo "TOTAL: $count verificações de automação passaram; nenhum SMTP/push real foi chamado.\n";
