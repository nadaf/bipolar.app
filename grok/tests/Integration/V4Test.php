<?php
/** V4: alertas automáticos, biometria (WebAuthn), Web Push com payload e IA "Reflexões com IA" (HTTP simulado). */
final class V4Test extends IntegrationCase
{
    /** Cenário de início de mania gravado direto no banco: 30 dias estáveis + 3 dias de sono curto e humor alto. */
    private function seedMania(int $uid): void
    {
        $pdo = db();
        $tz = new DateTimeZone('America/Cuiaba');
        for ($i = 32; $i >= 0; $i--) {
            $d = (new DateTime('now', $tz))->modify("-$i days")->format('Y-m-d');
            $hi = $i <= 2;
            foreach (['manha', 'tarde'] as $p) $pdo->prepare('INSERT INTO entries (user_id, entry_date, period, mood, energy) VALUES (?,?,?,?,?)')->execute([$uid, $d, $p, $hi ? 2 : 0, $hi ? 3 : 1]);
            $pdo->prepare('INSERT INTO daily_markers (user_id, entry_date, bed_time, wake_time, racing_thoughts, fast_speech) VALUES (?,?,?,?,?,?)')
                ->execute([$uid, $d, $hi ? '02:30' : '23:00', $hi ? '06:00' : '07:00', $hi ? 2 : 0, $hi ? 2 : 0]);
        }
    }

    /** Usuário na instância "produção" (sem login de desenvolvimento): e-mail + senha. */
    private function prodUser(string $tag): Http
    {
        $email = $tag . '@example.com';
        db()->prepare('INSERT INTO users (name, email, password_hash, email_verified_at) VALUES (?,?,?,UTC_TIMESTAMP())')->execute([$tag, $email, password_hash('senha forte de teste 123', PASSWORD_DEFAULT)]);
        db()->prepare("INSERT INTO oauth_identities (user_id, provider, subject) VALUES (?, 'email', ?)")->execute([(int)db()->lastInsertId(), $email]);
        $p = $this->prod();
        $r = $p->form('/', '/conta/entrar.php', ['email' => $email, 'senha' => 'senha forte de teste 123']);
        $this->assertSame(302, $r['status'], $r['body']);
        $p->refreshCsrf();
        return $p;
    }

    public function testAlertsEngineFeedbackCooldownAndPrivacyOfDelivery(): void
    {
        $c = $this->user('v4alert');
        $uid = $this->uid('v4alert');
        db()->prepare("UPDATE users SET name = 'Ronaldo Teste', email = 'ronaldo@example.com' WHERE id = ?")->execute([$uid]);
        $this->seedMania($uid);
        $r = $c->get('/api/alerts.php');
        $this->assertSame(200, $r['status'], $r['body']);
        $rules = array_column($r['json']['alerts'], 'rule_code');
        $this->assertContains('mania', $rules);
        $mania = $r['json']['alerts'][array_search('mania', $rules, true)];
        $this->assertTrue($mania['active']);
        $this->assertStringContainsString('dormiu 3,5 h', $mania['explanation']);
        $this->assertNotEmpty($mania['evidence']);
        $this->assertStringContainsString('id="alertas"', $c->get('/')['body']);
        // cooldown: rodar de novo não duplica
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM alerts WHERE user_id = $uid AND rule_code = 'mania'")->fetchColumn() - 1);
        TestEnv::runCron('analyze_signals.php');
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM alerts WHERE user_id = $uid AND rule_code = 'mania'")->fetchColumn(), 'dedupe/cooldown de 72 h');
        // feedback ajusta a sensibilidade uma única vez por alerta; outro usuário não mexe
        $o = $this->user('v4alert_o');
        $this->assertSame(404, $o->json('POST', '/api/alerts.php?action=feedback', ['id' => (int)$mania['id'], 'faz_sentido' => false])['status']);
        $this->assertSame(422, $c->json('POST', '/api/alerts.php?action=feedback', ['id' => (int)$mania['id'], 'faz_sentido' => 'x'])['status']);
        $f = $c->json('POST', '/api/alerts.php?action=feedback', ['id' => (int)$mania['id'], 'faz_sentido' => false]);
        $this->assertSame(-1, $f['json']['tune']['mania']);
        $f = $c->json('POST', '/api/alerts.php?action=feedback', ['id' => (int)$mania['id'], 'faz_sentido' => false]);
        $this->assertSame(-1, $f['json']['tune']['mania'], 'segundo clique no mesmo alerta não acumula');
        // salvar módulos em Conta preserva ajustes de alerta/IA/bloqueio
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['sono' => true]]);
        $this->assertSame(-1, $c->get('/api/alerts.php')['json']['tune']['mania']);

        // entrega: pessoa de apoio confirmada com opt-in recebe só "pode precisar de apoio"; sem opt-in, nada
        db()->prepare("INSERT INTO recipients (user_id, name, email, role, frequency, confirm_token, confirmed_at, include_alerts) VALUES (?, 'Ana', 'ana@example.com', 'familiar', 'semanal', ?, UTC_TIMESTAMP(), 1), (?, 'Bia', 'bia@example.com', 'familiar', 'semanal', ?, UTC_TIMESTAMP(), 0)")
            ->execute([$uid, bin2hex(random_bytes(32)), $uid, bin2hex(random_bytes(32))]);
        $c->json('POST', '/api/alerts.php?action=prefs', ['engine' => true, 'push' => true, 'email' => true, 'reset_tune' => true]);
        db()->exec("DELETE FROM alerts WHERE user_id = $uid");
        TestEnv::clearMails();
        [$code, $out] = TestEnv::runCron('analyze_signals.php');
        $this->assertSame(0, $code, $out);
        $mails = TestEnv::mails();
        $to = array_map(function (string $m): string { preg_match('/^To: <([^>]+)>/m', $m, $x); return $x[1] ?? ''; }, $mails);
        $this->assertContains('ana@example.com', $to);
        $this->assertNotContains('bia@example.com', $to);
        $this->assertContains('ronaldo@example.com', $to, 'e-mail opcional para a própria pessoa');
        foreach ($mails as $m) {
            $h = TestEnv::htmlPart($m);
            $this->assertStringContainsString('188', $h);
            foreach (['mania', 'sono', 'dormiu', 'humor elevado', 'acelerad'] as $bad) $this->assertStringNotContainsStringIgnoringCase($bad, $h, 'e-mail sem dados sensíveis');
        }
        $ana = $mails[array_search('ana@example.com', $to, true)];
        $this->assertStringContainsString('Ronaldo pode precisar de apoio', TestEnv::htmlPart($ana));
        // no máximo 1 aviso por semana para apoio
        db()->exec("DELETE FROM alerts WHERE user_id = $uid");
        TestEnv::clearMails();
        TestEnv::runCron('analyze_signals.php');
        $this->assertNotContains('ana@example.com', array_map(function (string $m): string { preg_match('/^To: <([^>]+)>/m', $m, $x); return $x[1] ?? ''; }, TestEnv::mails()));
        // export LGPD inclui alertas
        $this->assertNotEmpty($c->get('/api/account.php?action=export&format=json')['json']['alertas']);
    }

    public function testRecipientAlertOptInIsExplicit(): void
    {
        $c = $this->user('v4rc');
        $c->json('POST', '/api/recipients.php', ['name' => 'Dra', 'email' => 'dra@example.com', 'role' => 'psiquiatra', 'frequency' => 'semanal', 'include_alerts' => 1]);
        $this->assertSame(0, (int)$c->get('/api/recipients.php')['json']['recipients'][0]['include_alerts'], 'só true (booleano) liga');
        $id = $c->get('/api/recipients.php')['json']['recipients'][0]['id'];
        $c->json('PATCH', '/api/recipients.php?id=' . $id, ['include_alerts' => true]);
        $this->assertSame(1, (int)$c->get('/api/recipients.php')['json']['recipients'][0]['include_alerts']);
    }

    public function testPasskeyRegisterUnlockLoginAndAppLock(): void
    {
        $base = 'http://127.0.0.1:' . TestEnv::$devPort;
        $c = $this->user('v4wa');
        $auth = new SoftAuthenticator('127.0.0.1', $base);
        $o = $c->json('POST', '/api/webauthn.php?action=reg_options', []);
        $this->assertSame(200, $o['status'], $o['body']);
        $pk = $o['json']['publicKey'];
        $this->assertSame('127.0.0.1', $pk['rp']['id']);
        $this->assertSame('required', $pk['authenticatorSelection']['userVerification']);
        $this->assertSame('none', $pk['attestation']);
        $this->assertSame([-7, -257], array_column($pk['pubKeyCredParams'], 'alg'));
        $r = $c->json('POST', '/api/webauthn.php?action=reg_verify', $auth->create($pk) + ['name' => 'Celular de teste']);
        $this->assertSame(200, $r['status'], $r['body']);
        // desafio é de uso único
        $this->assertSame(422, $c->json('POST', '/api/webauthn.php?action=reg_verify', $auth->create($pk))['status']);
        $list = $c->get('/api/webauthn.php?action=list')['json'];
        $this->assertSame('Celular de teste', $list['credentials'][0]['name']);
        $this->assertSame(-7, (int)$list['credentials'][0]['alg']);

        // seção privada fica bloqueada (há fator) até a biometria
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['substancias' => true]]);
        $c->json('POST', '/api/webauthn.php?action=lock', []);
        $this->assertTrue($c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json']['subst']['locked']);
        $ao = $c->json('POST', '/api/webauthn.php?action=auth_options', [])['json']['publicKey'];
        $this->assertSame('required', $ao['userVerification']);
        $this->assertSame(b64url($auth->credId), $ao['allowCredentials'][0]['id']);
        $this->assertSame(200, $c->json('POST', '/api/webauthn.php?action=auth_verify', $auth->get($ao))['status']);
        $this->assertFalse($c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json']['subst']['locked']);
        // sem UV (só presença) é recusado
        $c->json('POST', '/api/webauthn.php?action=lock', []);
        $auth->uv = false;
        $ao = $c->json('POST', '/api/webauthn.php?action=auth_options', [])['json']['publicKey'];
        $this->assertSame(422, $c->json('POST', '/api/webauthn.php?action=auth_verify', $auth->get($ao))['status']);
        $auth->uv = true;
        // contador repetido (clone) é recusado
        $ao = $c->json('POST', '/api/webauthn.php?action=auth_options', [])['json']['publicKey'];
        $auth->count = 0;
        $r = $c->json('POST', '/api/webauthn.php?action=auth_verify', $auth->get($ao));
        $this->assertSame(422, $r['status']);
        $this->assertStringContainsString('Contador', $r['json']['erro']);
        $auth->count = 10;

        // bloquear o app inteiro
        $ao = $c->json('POST', '/api/webauthn.php?action=auth_options', [])['json']['publicKey'];
        $c->json('POST', '/api/webauthn.php?action=auth_verify', $auth->get($ao));
        $this->assertSame(200, $c->json('POST', '/api/webauthn.php?action=settings', ['minutes' => 5, 'app' => true, 'on_hide' => true])['status']);
        $this->assertSame(200, $c->get('/api/entries.php')['status'], 'acabou de desbloquear');
        $c->json('POST', '/api/webauthn.php?action=lock', []);
        $locked = $c->get('/api/entries.php');
        $this->assertSame(423, $locked['status']);
        $this->assertTrue($locked['json']['bloqueado_app']);
        $page = $c->get('/')['body'];
        $this->assertStringContainsString('id="lock-screen"', $page);
        $this->assertStringNotContainsString('id="boot"', $page, 'nenhum dado do app na tela de bloqueio');
        $this->assertStringContainsString('id="lock-screen"', $c->get('/registro-rapido.php')['body']);
        $this->assertSame(423, $c->json('POST', '/api/webauthn.php?action=reg_options', [])['status'], 'não cadastra outro aparelho bloqueado');
        $ao = $c->json('POST', '/api/webauthn.php?action=auth_options', [])['json']['publicKey'];
        $this->assertSame(200, $c->json('POST', '/api/webauthn.php?action=auth_verify', $auth->get($ao))['status']);
        $this->assertSame(200, $c->get('/api/entries.php')['status']);

        // login com passkey (sem sessão) a partir da página inicial
        $n = $this->dev();
        $home = $n->get('/')['body'];
        $this->assertStringContainsString('id="btn-passkey-login"', $home);
        preg_match('/name="csrf" value="([a-f0-9]{64})"/', $home, $m);
        $lo = $n->json('POST', '/api/webauthn.php?action=login_options', [], $m[1]);
        $this->assertSame(200, $lo['status'], $lo['body']);
        $this->assertSame([], $lo['json']['publicKey']['allowCredentials'], 'credencial descoberta (passkey)');
        $this->assertSame(200, $n->json('POST', '/api/webauthn.php?action=login_verify', $auth->get($lo['json']['publicKey']), $m[1])['status']);
        $n->refreshCsrf();
        $this->assertSame(200, $n->get('/api/entries.php')['status'], 'entrou e já desbloqueado');
        // assinatura de outra chave não entra
        $evil = new SoftAuthenticator('127.0.0.1', $base);
        $x = $this->dev(); $h2 = $x->get('/')['body']; preg_match('/name="csrf" value="([a-f0-9]{64})"/', $h2, $m2);
        $lo = $x->json('POST', '/api/webauthn.php?action=login_options', [], $m2[1])['json']['publicKey'];
        $this->assertSame(422, $x->json('POST', '/api/webauthn.php?action=login_verify', $evil->get($lo), $m2[1])['status']);
        // origem errada
        $wrong = new SoftAuthenticator('127.0.0.1', 'https://evil.example');
        $c2 = $this->user('v4wa2');
        $o2 = $c2->json('POST', '/api/webauthn.php?action=reg_options', [])['json']['publicKey'];
        $this->assertSame(422, $c2->json('POST', '/api/webauthn.php?action=reg_verify', $wrong->create($o2))['status']);
        // outro usuário não remove a credencial
        $this->assertSame(200, $c2->json('POST', '/api/webauthn.php?action=delete', ['id' => (int)$list['credentials'][0]['id']])['status']);
        $this->assertCount(1, $c->get('/api/webauthn.php?action=list')['json']['credentials']);
    }

    public function testAppLockNeedsAFactor(): void
    {
        $c = $this->user('v4nofactor');
        $this->assertSame(422, $c->json('POST', '/api/webauthn.php?action=settings', ['minutes' => 5, 'app' => true])['status']);
    }

    public function testPushDevicesSnoozeAndQuickEntry(): void
    {
        $c = $this->user('v4push');
        $p256 = b64url(p256_generate()[1]);
        $authSecret = b64url(random_bytes(16));
        $this->assertSame(422, $c->json('POST', '/api/push.php', ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'x', 'auth' => 'y']])['status']);
        $this->assertSame(200, $c->json('POST', '/api/push.php', ['endpoint' => 'https://127.0.0.1:9/abc', 'keys' => ['p256dh' => $p256, 'auth' => $authSecret], 'label' => 'Chrome no Android'])['status']);
        $d = $c->get('/api/push.php')['json']['devices'];
        $this->assertSame('Chrome no Android', $d[0]['label']);
        // teste: endpoint inalcançável → erro amigável
        $t = $c->json('POST', '/api/push.php?action=test', []);
        $this->assertSame(422, $t['status']);
        // "Lembrar em 30 min" pelo service worker: exige o segredo da assinatura
        $x = $this->dev();
        $this->assertSame(403, $x->json('POST', '/api/push.php?action=snooze', ['endpoint' => 'https://127.0.0.1:9/abc', 'auth' => 'errado'], false)['status']);
        $this->assertSame(200, $x->json('POST', '/api/push.php?action=snooze', ['endpoint' => 'https://127.0.0.1:9/abc', 'auth' => $authSecret, 'minutes' => 30], false)['status']);
        $this->assertSame('1', (string)db()->query("SELECT COUNT(*) FROM push_subscriptions WHERE snooze_until > UTC_TIMESTAMP() + INTERVAL 25 MINUTE")->fetchColumn());
        $this->assertSame(200, $c->json('POST', '/api/push.php?action=delete', ['id' => (int)$d[0]['id']])['status']);
        $this->assertSame([], $c->get('/api/push.php')['json']['devices']);
        // produção sem VAPID
        $p = $this->prodUser('v4pushprod');
        $this->assertSame(422, $p->json('POST', '/api/push.php?action=test', [])['status']);
        // página de 1 toque
        $q = $c->get('/registro-rapido.php?p=tarde')['body'];
        $this->assertStringContainsString('Como está seu humor nesta tarde?', $q);
        $this->assertSame(7, substr_count($q, 'data-mood="'));
        $this->assertSame(302, $this->dev()->get('/registro-rapido.php')['status'], 'sem sessão volta ao login');
    }

    public function testVapidKeysAutoLoadFromStorage(): void
    {
        $file = TestEnv::$tmp . '/storage/vapid.php';
        @unlink($file);
        $cfg = TestEnv::$tmp . '/config.novapid.php';
        $arr = require TestEnv::$prodConfig;
        file_put_contents($cfg, '<?php return ' . var_export($arr, true) . ';');
        $run = function () use ($cfg): string { exec('BIPOLAR_CONFIG=' . escapeshellarg($cfg) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(TestEnv::ROOT . '/tools/generate_vapid.php') . ' 2>&1', $o); return implode("\n", $o); };
        $out = $run();
        $this->assertStringContainsString('Chaves VAPID gravadas', $out);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $keys = require $file;
        $this->assertSame(87, strlen($keys['public_key']));
        $this->assertStringContainsString('nada foi alterado', $run(), 'não sobrescreve');
        $chk = 'BIPOLAR_CONFIG=' . escapeshellarg($cfg) . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "' . TestEnv::ROOT . '/app/bootstrap.php"; echo push_configured() ? cfg("vapid.public_key") : "off";');
        $this->assertSame($keys['public_key'], trim((string)shell_exec($chk)));
        @unlink($file); @unlink(TestEnv::$tmp . '/storage/vapid-config.txt'); @unlink($cfg);
    }

    public function testAiCompanionConsentPrivacyCrisisAndCap(): void
    {
        $c = $this->user('v4ai');
        $uid = $this->uid('v4ai');
        db()->prepare("UPDATE users SET name = 'Ronaldo Silva', email = 'ron@example.com' WHERE id = ?")->execute([$uid]);
        $today = (new DateTime('now', new DateTimeZone('America/Cuiaba')))->format('Y-m-d');
        $this->entry($c, ['entry_date' => $today, 'period' => 'manha', 'mood' => 1, 'note' => 'SEGREDO-NOTA']);
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['substancias' => true]]);
        $c->json('POST', '/api/day.php?action=subst_add', ['entry_date' => $today, 'substance' => 'cannabis', 'amount' => 1]);
        $st = $c->get('/api/ai.php')['json'];
        $this->assertTrue($st['configured']);
        $this->assertFalse($st['settings']['consent']);
        $this->assertSame(403, $c->json('POST', '/api/ai.php?action=resumo', [])['status'], 'sem consentimento');
        $this->assertStringNotContainsString('id="ia-card"', $c->get('/')['body']);
        $this->assertSame(422, $c->json('POST', '/api/ai.php?action=consent', ['consent' => true, 'categories' => []])['status']);
        $this->assertSame(200, $c->json('POST', '/api/ai.php?action=consent', ['consent' => true, 'categories' => ['humor', 'sono']])['status']);
        $this->assertStringContainsString('id="ia-card"', $c->get('/')['body']);

        $r = $c->json('POST', '/api/ai.php?action=resumo', []);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertStringContainsString('Resumo:', $r['json']['texto']);
        $this->assertStringContainsString('grok-4.7', $r['json']['aviso']);
        $last = json_decode((string)file_get_contents(TestEnv::$tmp . '/xai/last.json'), true);
        $this->assertSame('Bearer xai-test-key', $last['auth']);
        $this->assertSame('/v1/chat/completions', $last['uri']);
        $sent = json_encode($last['body'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('manha humor 1', $sent);
        foreach (['SEGREDO-NOTA', 'Cannabis', 'ron@example.com', 'Silva'] as $bad) $this->assertStringNotContainsString($bad, $sent, "não enviar: $bad");
        $this->assertStringContainsString('Nunca faça diagnóstico', $sent);

        // crise: resposta local, sem chamar a API
        $before = (int)file_get_contents(TestEnv::$tmp . '/xai/count');
        $k = $c->json('POST', '/api/ai.php?action=checkin', ['messages' => [['role' => 'user', 'content' => 'não aguento mais viver']]]);
        $this->assertTrue($k['json']['crise']);
        $this->assertStringContainsString('188', $k['json']['texto']);
        $this->assertSame($before, (int)file_get_contents(TestEnv::$tmp . '/xai/count'));

        // check-in propõe um registro (a pessoa confirma depois)
        $k = $c->json('POST', '/api/ai.php?action=checkin', ['messages' => [['role' => 'user', 'content' => 'Estou bem, dormi 7 horas e meia']]]);
        $this->assertSame(['mood' => 1, 'period' => 'tarde', 'sleep_hours' => 7.5, 'energy' => 2, 'note' => 'tarde boa'], $k['json']['registro']);
        $this->assertStringNotContainsString('<<REGISTRO', $k['json']['texto']);

        // teto mensal (config de teste: US$ 0,02; cada chamada simulada custa US$ 0,005)
        $c->json('POST', '/api/ai.php?action=consulta', ['notes' => 'quero falar do sono']);
        $c->json('POST', '/api/ai.php?action=resumo', []);
        $cap = $c->json('POST', '/api/ai.php?action=resumo', []);
        $this->assertSame(429, $cap['status']);
        $this->assertStringContainsString('limite mensal', $cap['json']['erro']);
        $this->assertEqualsWithDelta(0.02, (float)db()->query("SELECT cost_usd FROM ai_usage WHERE user_id = $uid")->fetchColumn(), 1e-9);
        // revogar
        $c->json('POST', '/api/ai.php?action=consent', ['consent' => false, 'categories' => ['humor']]);
        $this->assertSame(403, $c->json('POST', '/api/ai.php?action=resumo', [])['status']);
        // produção sem chave: desligado e escondido
        $p = $this->prodUser('v4aiprod');
        $this->assertFalse($p->get('/api/ai.php')['json']['configured']);
        $this->assertStringNotContainsString('id="conta-ia"', $p->get('/')['body']);
    }
}
