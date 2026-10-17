<?php
final class TestEnv
{
    public const ROOT = __DIR__ . '/../..';
    /** V4.7: a árvore testada tem o módulo privado (app/ext/module.php)? */
    public static function hasPrivate(): bool { return is_file(self::ROOT . '/app/ext/module.php'); }
    public static string $tmp;
    public static int $devPort;
    public static int $prodPort;
    public static int $smtpPort;
    public static int $xaiPort;
    public static string $devConfig;
    public static string $prodConfig;
    /** @var resource[] */
    private static array $procs = [];

    public static function setup(): void
    {
        self::$tmp = __DIR__ . '/../tmp';
        @mkdir(self::$tmp . '/mail', 0777, true);
        @mkdir(self::$tmp . '/storage/media', 0777, true);
        array_map('unlink', glob(self::$tmp . '/mail/*.eml') ?: []);
        self::$devPort = self::freePort();
        self::$prodPort = self::freePort();
        self::$smtpPort = self::freePort();
        self::$xaiPort = self::freePort();
        @mkdir(self::$tmp . '/xai', 0777, true);
        array_map('unlink', glob(self::$tmp . '/xai/*') ?: []);
        $vapid = self::vapid();
        self::$devConfig = self::writeConfig('dev', self::$devPort, true, $vapid);
        self::$prodConfig = self::writeConfig('prod', self::$prodPort, false, $vapid);

        putenv('BIPOLAR_CONFIG=' . self::$devConfig);
        require_once self::ROOT . '/app/bootstrap.php';
        require_once self::ROOT . '/app/lib/oauth.php';
        require_once self::ROOT . '/app/lib/smtp.php';
        require_once self::ROOT . '/app/lib/webpush.php';
        require_once self::ROOT . '/app/lib/report.php';
        require_once self::ROOT . '/app/lib/schedule.php';
        require_once self::ROOT . '/app/lib/webauthn.php';
        require_once self::ROOT . '/app/lib/alerts.php';
        require_once self::ROOT . '/app/lib/ai.php';
        // V4.7: o módulo privado (pedidos de contato/cobrança) só existe na árvore completa do site
        if (is_file(self::ROOT . '/app/lib/leads.php')) require_once self::ROOT . '/app/lib/leads.php';
        self::resetDb();

        $php = PHP_BINARY;
        self::spawn([$php, __DIR__ . '/../tools/fake_smtp.php', (string)self::$smtpPort, self::$tmp . '/mail'], []);
        $docroot = self::ROOT . '/public_html';
        self::spawn([$php, '-S', '127.0.0.1:' . self::$xaiPort, __DIR__ . '/../tools/fake_xai.php'], ['FAKE_XAI_DIR' => self::$tmp . '/xai']);
        foreach ([[self::$devPort, self::$devConfig], [self::$prodPort, self::$prodConfig]] as [$port, $cfg]) {
            self::spawn([$php, '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=25M', '-d', 'display_errors=0',
                '-S', '127.0.0.1:' . $port, '-t', $docroot], ['BIPOLAR_CONFIG' => $cfg, 'PHP_CLI_SERVER_WORKERS' => '4']);
        }
        foreach ([self::$devPort, self::$prodPort, self::$smtpPort, self::$xaiPort] as $p) self::waitPort($p);
        register_shutdown_function([self::class, 'teardown']);
    }

    /** @param list<string> $cmd @param array<string,string> $env */
    private static function spawn(array $cmd, array $env): void
    {
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', self::$tmp . '/server.log', 'a'], 2 => ['file', self::$tmp . '/server.log', 'a']], $pipes, null, $env + getenv());
        if (!is_resource($p)) throw new RuntimeException('Falha ao iniciar ' . implode(' ', $cmd));
        self::$procs[] = $p;
    }

    public static function teardown(): void
    {
        foreach (self::$procs as $p) { $s = proc_get_status($p); if ($s['running']) posix_kill($s['pid'], SIGTERM); proc_close($p); }
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($s, false);
        fclose($s);
        return (int)substr((string)strrchr((string)$name, ':'), 1);
    }

    private static function waitPort(int $port): void
    {
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
            if ($c) { fclose($c); return; }
            usleep(100000);
        }
        throw new RuntimeException("Porta $port não respondeu");
    }

    /** @return array{public_key:string,private_key_pem:string} */
    private static function vapid(): array
    {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($k, $pem);
        $d = openssl_pkey_get_details($k);
        $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        return ['public_key' => rtrim(strtr(base64_encode($pub), '+/', '-_'), '='), 'private_key_pem' => $pem];
    }

    /** @param array{public_key:string,private_key_pem:string} $vapid */
    private static function writeConfig(string $name, int $port, bool $dev, array $vapid): string
    {
        $cfg = [
            'app_url' => 'http://127.0.0.1:' . $port,
            'app_name' => 'Bipolar (teste)',
            'default_timezone' => 'America/Cuiaba',
            'dev_login' => $dev,
            'storage_dir' => self::$tmp . '/storage',
            'db' => [
                'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => (int)(getenv('TEST_DB_PORT') ?: 3307),
                'name' => getenv('TEST_DB_NAME') ?: 'bipolar_test', 'user' => getenv('TEST_DB_USER') ?: 'bp', 'pass' => getenv('TEST_DB_PASS') ?: 'bp',
            ],
            'require_email_verification' => true,
            // dev: bypass de rate limit habilitado (testado com/sem cabeçalho); prod: desativado (padrão)
            'loadtest_bypass_ratelimit_token' => $dev ? str_repeat('t3st-bypass-', 4) : '',
            'oauth' => $dev ? [
                'google' => ['client_id' => 'gid.apps.googleusercontent.com', 'client_secret' => 'gsecret'],
                'microsoft' => ['client_id' => 'ms-id', 'client_secret' => 'ms-secret', 'tenant' => 'common'],
                'x' => ['client_id' => 'x-id', 'client_secret' => 'x-secret'],
            ] : [
                // produção simulada: só Google configurado; Microsoft com placeholders do exemplo; X vazio
                'google' => ['client_id' => 'gid.apps.googleusercontent.com', 'client_secret' => 'gsecret'],
                'microsoft' => ['client_id' => 'SEU_APPLICATION_CLIENT_ID', 'client_secret' => 'SEU_CLIENT_SECRET_VALUE', 'tenant' => 'common'],
                'x' => ['client_id' => '', 'client_secret' => ''],
            ],
            'smtp' => ['host' => '127.0.0.1', 'port' => self::$smtpPort, 'secure' => '', 'user' => '', 'pass' => '',
                'from_email' => 'nao-responda@bipolar.app.br', 'from_name' => 'Bipolar — Diário de Humor'],
            // prod simula o servidor real: VAPID não configurado
            'vapid' => $dev ? ['subject' => 'mailto:contato@bipolar.app.br'] + $vapid : ['subject' => 'mailto:contato@bipolar.app.br', 'public_key' => '', 'private_key_pem' => ''],
            // dev: IA apontando para o servidor falso (HTTP simulado); prod: sem chave = recurso desligado
            'xai' => $dev ? ['api_key' => 'xai-test-key', 'model' => 'grok-4.7', 'endpoint' => 'http://127.0.0.1:' . self::$xaiPort . '/v1/chat/completions',
                'price_in_per_mtok' => 2.0, 'price_out_per_mtok' => 6.0, 'monthly_cap_usd' => 0.02, 'requests_per_hour' => 50] : ['api_key' => ''],
            // V4.3: chave do servidor para cifrar as chaves próprias (BYOK); dev aceita URL própria local (servidor falso)
            'ai' => $dev ? ['secret_key' => base64_encode(str_repeat('k', 32)), 'allow_private_urls' => true] : [],
            // V4.3.1: produção simulada com o bloco legal no nível de cima, como no config.php real
            'legal' => $dev ? [] : ['controller' => 'Ronaldo Nadaf, Cuiabá/MT', 'contact_email' => 'privacidade@bipolar.app.br'],
            // V4.5: páginas públicas; dev pede 2 s de preenchimento, prod usa o padrão (3 s)
            'sales' => $dev ? ['notify_email' => 'dono@example.com', 'min_seconds' => 2, 'max_per_hour' => 5] : [],
            'media' => ['max_audio_mb' => 2, 'max_video_mb' => 3, 'max_image_mb' => 1],
        ];
        $file = self::$tmp . "/config.$name.php";
        file_put_contents($file, '<?php return ' . var_export($cfg, true) . ';');
        return $file;
    }

    public static function resetDb(): void
    {
        $pdo = db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $sql = (string)file_get_contents(self::ROOT . '/schema.sql');
        if (is_file(self::ROOT . '/schema-private.sql')) $sql .= "\n" . file_get_contents(self::ROOT . '/schema-private.sql'); // V4.7: módulo privado
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if (preg_match('/^(--[^\n]*\n\s*)*$/', $stmt . "\n")) continue;
            $pdo->exec($stmt);
        }
    }

    /** @return list<string> conteúdo bruto das mensagens capturadas */
    public static function mails(): array
    {
        $files = glob(self::$tmp . '/mail/*.eml') ?: [];
        sort($files);
        return array_map('file_get_contents', $files);
    }
    public static function clearMails(): void { array_map('unlink', glob(self::$tmp . '/mail/*.eml') ?: []); }

    /** Decodifica a parte text/html base64 de uma mensagem capturada. */
    public static function htmlPart(string $raw): string
    {
        $raw = preg_replace('/^\.\./m', '.', $raw);
        if (!preg_match('#Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--#s', (string)$raw, $m)) return '';
        return (string)base64_decode(str_replace("\r\n", '', $m[1]));
    }

    /** Executa um script de cron com a configuração de teste. @return array{0:int,1:string} */
    public static function runCron(string $script): array
    {
        $cmd = 'BIPOLAR_CONFIG=' . escapeshellarg(self::$devConfig) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::ROOT . '/cron/' . $script) . ' 2>&1';
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }
}
