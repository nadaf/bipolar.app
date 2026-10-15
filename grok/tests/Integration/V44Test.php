<?php
/** V4.4: 404 em exclusões/alterações de itens de outra pessoa, dono antes da duplicidade e verificação em duas etapas (MFA). */
final class V44Test extends IntegrationCase
{
    private const PW = 'cavalo correto bateria';

    /* ------------------------------------------------------------ correções do QA */
    public function testDeleteForeignRecipientReturns404(): void
    {
        $a = $this->user('v44ra'); $b = $this->user('v44rb');
        $rid = (int)$b->json('POST', '/api/recipients.php', ['name' => 'Dra B', 'email' => 'drab44@example.com', 'role' => 'psiquiatra', 'frequency' => 'semanal', 'weekday' => 1])['json']['id'];
        $r = $a->json('DELETE', "/api/recipients.php?id=$rid", null);
        $this->assertSame(404, $r['status'], $r['body']);
        $this->assertSame('Destinatário não encontrado.', $r['json']['erro']);
        $this->assertArrayNotHasKey('ok', $r['json']);
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM recipients WHERE id = $rid")->fetchColumn(), 'o de B continua');
        $this->assertSame(200, $b->json('DELETE', "/api/recipients.php?id=$rid", null)['status'], 'a dona apaga');
        $this->assertSame(404, $b->json('DELETE', "/api/recipients.php?id=$rid", null)['status'], 'apagar de novo → 404');
        $this->assertSame(404, $a->json('DELETE', '/api/recipients.php?id=999999', null)['status']);
    }
    public function testAllForeignDeletesAndUpdatesReturn404(): void
    {
        $a = $this->user('v44xa'); $b = $this->user('v44xb');
        $eid = (int)$this->entry($b)['id'];
        $b->json('POST', '/api/reminders.php', ['time_local' => '09:00', 'days' => [1]]);
        $mid = (int)db()->query('SELECT id FROM reminders WHERE user_id = ' . $this->uid('v44xb'))->fetchColumn();
        $b->json('POST', '/api/push.php', ['endpoint' => 'https://push.example/v44', 'keys' => ['p256dh' => b64url("\x04" . str_repeat('k', 64)), 'auth' => b64url(str_repeat('a', 16))]]);
        $pid = (int)db()->query('SELECT id FROM push_subscriptions WHERE user_id = ' . $this->uid('v44xb'))->fetchColumn();
        $up = $b->upload('/api/media.php', $this->png(), 'b.png', 'image/png');
        $med = (int)$up['json']['id'];
        db()->prepare("INSERT INTO webauthn_credentials (user_id, cred_hash, credential_id, public_key_pem, alg, sign_count, name) VALUES (?, REPEAT('c', 64), 'x', 'pem', -7, 0, 'Celular B')")->execute([$this->uid('v44xb')]);
        $wid = (int)db()->query('SELECT id FROM webauthn_credentials WHERE user_id = ' . $this->uid('v44xb'))->fetchColumn();

        $this->assertSame(404, $a->json('DELETE', "/api/entries.php?id=$eid", null)['status']);
        $this->assertSame(404, $a->json('DELETE', "/api/reminders.php?id=$mid", null)['status']);
        $this->assertSame(404, $a->json('DELETE', "/api/media.php?id=$med", null)['status']);
        $this->assertSame(404, $a->json('POST', '/api/push.php?action=delete', ['id' => $pid])['status']);
        $this->assertSame(404, $a->json('POST', '/api/push.php?action=rename', ['id' => $pid, 'label' => 'meu'])['status']);
        $this->assertSame(404, $a->json('POST', '/api/webauthn.php?action=rename', ['id' => $wid, 'name' => 'meu'])['status']);
        $this->assertSame(404, $a->json('POST', '/api/alerts.php?action=feedback', ['id' => 999999, 'faz_sentido' => true])['status']);
        // nada de B mudou
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM entries WHERE id = $eid")->fetchColumn());
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM reminders WHERE id = $mid")->fetchColumn());
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM media WHERE id = $med")->fetchColumn());
        $this->assertNull(db()->query("SELECT label FROM push_subscriptions WHERE id = $pid")->fetchColumn());
        $this->assertSame('Celular B', db()->query("SELECT name FROM webauthn_credentials WHERE id = $wid")->fetchColumn());
        // a dona continua conseguindo (renomear com o MESMO nome também dá 200)
        $this->assertSame(200, $b->json('POST', '/api/push.php?action=rename', ['id' => $pid, 'label' => 'Celular'])['status']);
        $this->assertSame(200, $b->json('POST', '/api/push.php?action=rename', ['id' => $pid, 'label' => 'Celular'])['status']);
        $this->assertSame(200, $b->json('POST', '/api/webauthn.php?action=rename', ['id' => $wid, 'name' => 'Celular B'])['status']);
        $this->assertSame(200, $b->json('DELETE', "/api/media.php?id=$med", null)['status']);
        $this->assertSame(200, $b->json('DELETE', "/api/reminders.php?id=$mid", null)['status']);
        $this->assertSame(200, $b->json('DELETE', "/api/entries.php?id=$eid", null)['status']);
        $this->assertSame(200, $b->json('POST', '/api/push.php?action=delete', ['id' => $pid])['status']);
    }
    public function testCustomAlertOwnershipCheckedBeforeDuplicate(): void
    {
        $a = $this->user('v44ca'); $b = $this->user('v44cb');
        $rule = ['name' => 'Sono curto', 'conditions' => [['metric' => 'sleep', 'op' => '<', 'value' => 5, 'mode' => 'day']]];
        $this->assertSame(200, $a->json('POST', '/api/custom_alerts.php?action=save', $rule)['status']);
        $bid = (int)$b->json('POST', '/api/custom_alerts.php?action=save', ['name' => 'Humor', 'conditions' => [['metric' => 'sleep', 'op' => '<', 'value' => 4, 'mode' => 'day']]])['json']['rule']['id'];
        // A tenta salvar com o id de B e condições iguais a um alerta próprio: 404 (antes: 409 "duplicado")
        $r = $a->json('POST', '/api/custom_alerts.php?action=save', ['id' => $bid] + $rule);
        $this->assertSame(404, $r['status'], $r['body']);
        $this->assertSame('Alerta não encontrado.', $r['json']['erro']);
        $this->assertArrayNotHasKey('duplicado', $r['json']);
        $this->assertSame('Humor', db()->query("SELECT name FROM alert_rules WHERE id = $bid")->fetchColumn());
        // duplicidade real continua 409
        $this->assertSame(409, $a->json('POST', '/api/custom_alerts.php?action=save', $rule)['status']);
    }

    /* ------------------------------------------------------------ MFA */
    private function emailUser(string $email): int
    {
        $pdo = db();
        $pdo->prepare('INSERT INTO users (name, email, timezone, password_hash, email_verified_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute(['Ana MFA', $email, 'America/Cuiaba', pw_hash(self::PW)]);
        $uid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO oauth_identities (user_id, provider, subject) VALUES (?, 'email', ?)")->execute([$uid, $email]);
        return $uid;
    }
    private function passwordLogin(Http $c, string $email, string $pw = self::PW): array
    {
        return $c->form('/', '/conta/entrar.php', ['email' => $email, 'senha' => $pw]);
    }
    private function code(Http $c, string $code): array
    {
        return $c->form('/conta/mfa.php', '/conta/mfa.php', ['codigo' => $code]);
    }
    private function loggedIn(Http $c): bool { return $c->get('/api/entries.php')['status'] === 200; }
    /** Ativa a MFA pela API. @return array{0:string,1:list<string>} chave (binária) e códigos de recuperação */
    private function enable(Http $c): array
    {
        $c->refreshCsrf();
        $this->assertSame(422, $c->json('POST', '/api/mfa.php?action=start', ['senha' => 'errada'])['status'], 'exige a senha');
        $s = $c->json('POST', '/api/mfa.php?action=start', ['senha' => self::PW]);
        $this->assertSame(200, $s['status'], $s['body']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $s['json']['qr'], 'QR gerado localmente');
        $this->assertStringContainsString('otpauth://totp/Bipolar:', $s['json']['uri']);
        $key = (string)base32_decode($s['json']['secret']);
        $this->assertSame(20, strlen($key));
        $this->assertSame(422, $c->json('POST', '/api/mfa.php?action=confirm', ['codigo' => '000000'])['status']);
        $ok = $c->json('POST', '/api/mfa.php?action=confirm', ['codigo' => totp_code($key, time())]);
        $this->assertSame(200, $ok['status'], $ok['body']);
        $this->assertCount(10, $ok['json']['recovery_codes']);
        $this->assertNotSame($c->csrf, $ok['json']['csrf'], 'sessão renovada');
        $c->csrf = $ok['json']['csrf'];
        return [$key, $ok['json']['recovery_codes']];
    }

    public function testEnableLoginReplayRecoveryAndDisable(): void
    {
        $email = 'mfa44@example.com';
        $uid = $this->emailUser($email);
        $c = $this->dev();
        $this->assertSame('/', $c->header($this->passwordLogin($c, $email), 'Location'), 'sem MFA: entra direto');
        $this->assertSame(['available' => true, 'eligible' => true, 'enabled' => false, 'enabled_at' => null, 'recovery_left' => 0], $c->get('/api/mfa.php')['json']);
        TestEnv::clearMails();
        [$key, $codes] = $this->enable($c);
        $this->assertTrue($this->loggedIn($c), 'quem ativou continua dentro');

        // segredo cifrado e códigos só com hash
        $row = db()->query("SELECT mfa_totp_secret_enc, mfa_enabled_at, mfa_totp_last_step FROM users WHERE id = $uid")->fetch();
        $this->assertStringStartsWith('m1:', (string)$row['mfa_totp_secret_enc']);
        $this->assertStringNotContainsString(base32_encode($key), (string)$row['mfa_totp_secret_enc']);
        $this->assertNotNull($row['mfa_totp_last_step']);
        $hashes = db()->query("SELECT code_hash FROM mfa_recovery_codes WHERE user_id = $uid")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(10, $hashes);
        foreach ($codes as $rc) {
            $this->assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $rc);
            $this->assertNotContains($rc, $hashes);
            $this->assertContains(mfa_recovery_hash($uid, $rc), $hashes);
        }
        // aviso por e-mail, sem segredo nem códigos
        $mails = array_values(array_filter(TestEnv::mails(), fn ($m) => str_contains($m, "RCPT TO:<$email>")));
        $this->assertCount(1, $mails);
        $html = TestEnv::htmlPart($mails[0]);
        $this->assertStringContainsString('ativada', $html);
        $this->assertStringNotContainsString(base32_encode($key), $html);
        foreach ($codes as $rc) $this->assertStringNotContainsString($rc, $html);
        // exportação sem o segredo
        $exp = $c->get('/api/account.php?action=export&format=json')['json']['usuario'];
        $this->assertArrayNotHasKey('mfa_totp_secret_enc', $exp);
        $this->assertTrue($exp['verificacao_duas_etapas']);

        // login: depois da senha, pede o código
        $n = $this->dev();
        $r = $this->passwordLogin($n, $email);
        $this->assertSame('/conta/mfa.php', $n->header($r, 'Location'));
        $this->assertFalse($this->loggedIn($n), 'senha sozinha não entra');
        $this->assertStringContainsString('autocomplete="one-time-code"', $n->get('/conta/mfa.php')['body']);
        $bad = $this->code($n, '000000');
        $this->assertSame('/conta/mfa.php', $n->header($bad, 'Location'));
        $this->assertFalse($this->loggedIn($n));
        $good = totp_code($key, time() + 30); // o passo atual foi usado na ativação
        $r = $this->code($n, $good);
        $this->assertSame('/', $n->header($r, 'Location'));
        $this->assertTrue($this->loggedIn($n));

        // reuso do mesmo código em outro login: recusado
        $o = $this->dev();
        $this->passwordLogin($o, $email);
        $this->code($o, $good);
        $this->assertFalse($this->loggedIn($o), 'código reutilizado');
        // código de recuperação: vale uma vez
        $r = $this->code($o, strtoupper(str_replace('-', ' ', $codes[0])));
        $this->assertSame('/', $o->header($r, 'Location'));
        $this->assertTrue($this->loggedIn($o));
        $this->assertStringContainsString('Restam 9 códigos', $o->get('/')['body']);
        $p = $this->dev();
        $this->passwordLogin($p, $email);
        $this->code($p, $codes[0]);
        $this->assertFalse($this->loggedIn($p), 'código de recuperação já usado');

        // regenerar e desativar exigem senha + código atual
        $c = $o; $c->refreshCsrf();
        db()->exec('DELETE FROM rate_limits'); // nova janela de 15 min (o limite por conta é testado em testRateLimitOnCodes)
        $this->assertSame(422, $c->json('POST', '/api/mfa.php?action=regen', ['senha' => self::PW, 'codigo' => '123456'])['status']);
        $this->assertSame(422, $c->json('POST', '/api/mfa.php?action=regen', ['senha' => 'errada', 'codigo' => $codes[1]])['status']);
        $g = $c->json('POST', '/api/mfa.php?action=regen', ['senha' => self::PW, 'codigo' => $codes[1]]);
        $this->assertSame(200, $g['status'], $g['body']);
        $new = $g['json']['recovery_codes'];
        $this->assertCount(10, $new);
        $this->assertSame(10, $c->get('/api/mfa.php')['json']['recovery_left']);
        $this->assertSame(422, $c->json('POST', '/api/mfa.php?action=disable', ['senha' => self::PW, 'codigo' => $codes[2]])['status'], 'códigos antigos deixaram de valer');
        $this->assertSame(422, $c->json('POST', '/api/mfa.php?action=disable', ['codigo' => $new[0]])['status'], 'sem senha');
        TestEnv::clearMails();
        $this->assertSame(200, $c->json('POST', '/api/mfa.php?action=disable', ['senha' => self::PW, 'codigo' => $new[0]])['status']);
        $this->assertStringContainsString('desativada', TestEnv::htmlPart(TestEnv::mails()[0]));
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = $uid")->fetchColumn());
        $this->assertNull(db()->query("SELECT mfa_totp_secret_enc FROM users WHERE id = $uid")->fetchColumn());
        $q = $this->dev();
        $this->assertSame('/', $q->header($this->passwordLogin($q, $email), 'Location'), 'desativada: entra só com a senha');
    }

    public function testEnablingSignsOutOtherDevices(): void
    {
        $email = 'mfa44b@example.com';
        $this->emailUser($email);
        $other = $this->dev();
        $this->passwordLogin($other, $email);
        $this->assertTrue($this->loggedIn($other));
        $c = $this->dev();
        $this->passwordLogin($c, $email);
        $this->enable($c);
        $this->assertFalse($this->loggedIn($other), 'o outro aparelho sai');
        $this->assertTrue($this->loggedIn($c));
    }

    public function testRateLimitOnCodes(): void
    {
        $email = 'mfa44rl@example.com';
        $this->emailUser($email);
        $c = $this->dev();
        $this->passwordLogin($c, $email);
        [$key] = $this->enable($c);
        $n = $this->dev();
        $this->passwordLogin($n, $email);
        for ($i = 1; $i <= 4; $i++) $this->assertSame('/conta/mfa.php', $n->header($this->code($n, '000000'), 'Location'), "tentativa $i");
        $r = $this->code($n, '000000');
        $this->assertSame('/', $n->header($r, 'Location'), '5ª errada: volta ao início');
        $this->assertStringContainsString('entre de novo com e-mail e senha', $n->get('/')['body']);
        $this->assertSame(302, $n->get('/conta/mfa.php')['status'], 'login pendente descartado');
        // limite por conta (10 a cada 15 min, somando ativação e logins): com a senha certa de novo, segue bloqueado
        $this->passwordLogin($n, $email);
        for ($i = 0; $i < 3; $i++) $this->code($n, '000000');
        $r = $this->code($n, totp_code($key, time() + 30));
        $this->assertFalse($this->loggedIn($n));
        $this->assertStringContainsString('Muitas tentativas', $n->get('/')['body']);
        // passado o limite (simulado limpando os contadores), o código certo entra
        db()->exec('DELETE FROM rate_limits');
        $this->passwordLogin($n, $email);
        $this->code($n, totp_code($key, time() + 30));
        $this->assertTrue($this->loggedIn($n));
    }

    public function testPendingLoginExpiresAndCannotUseApi(): void
    {
        $email = 'mfa44exp@example.com';
        $this->emailUser($email);
        $c = $this->dev();
        $this->passwordLogin($c, $email);
        [$key] = $this->enable($c);
        $n = $this->dev();
        $this->passwordLogin($n, $email);
        $this->assertSame(401, $n->get('/api/entries.php')['status']);
        $this->assertSame(401, $n->get('/api/mfa.php')['status']);
        $this->assertStringContainsString('Entrar', $n->get('/')['body'], 'tela de login, não o app');
        // cancelar descarta o login pendente
        $n->get('/conta/mfa.php?cancelar=1');
        $this->assertSame(302, $n->get('/conta/mfa.php')['status']);
        $this->assertNotNull($key);
    }

    public function testPasskeyAsSecondFactor(): void
    {
        $email = 'mfa44pk@example.com';
        $this->emailUser($email);
        $base = 'http://127.0.0.1:' . TestEnv::$devPort;
        $c = $this->dev();
        $this->passwordLogin($c, $email);
        $this->enable($c);
        $auth = new SoftAuthenticator('127.0.0.1', $base);
        $o = $c->json('POST', '/api/webauthn.php?action=reg_options', []);
        $this->assertSame(200, $o['status'], $o['body']);
        $this->assertSame(200, $c->json('POST', '/api/webauthn.php?action=reg_verify', $auth->create($o['json']['publicKey']) + ['name' => 'Celular'])['status']);

        $n = $this->dev();
        $this->passwordLogin($n, $email);
        $page = $n->get('/conta/mfa.php')['body'];
        $this->assertStringContainsString('btn-passkey-mfa', $page);
        preg_match('/name="csrf" value="([a-f0-9]{64})"/', $page, $m);
        $opt = $n->json('POST', '/api/webauthn.php?action=mfa_options', [], $m[1]);
        $this->assertSame(200, $opt['status'], $opt['body']);
        $this->assertCount(1, $opt['json']['publicKey']['allowCredentials'], 'só as passkeys desta conta');
        $v = $n->json('POST', '/api/webauthn.php?action=mfa_verify', $auth->get($opt['json']['publicKey']), $m[1]);
        $this->assertSame(200, $v['status'], $v['body']);
        $this->assertTrue($this->loggedIn($n));
        // sem login pendente não há o que confirmar
        $x = $this->dev();
        preg_match('/name="csrf" value="([a-f0-9]{64})"/', $x->get('/')['body'], $m2);
        $this->assertSame(401, $x->json('POST', '/api/webauthn.php?action=mfa_options', [], $m2[1])['status']);
    }

    public function testOauthAccountsAndMissingKey(): void
    {
        // conta OAuth/dev (sem senha): MFA fica com o provedor
        $c = $this->user('v44oauth');
        $st = $c->get('/api/mfa.php')['json'];
        $this->assertFalse($st['eligible']);
        $this->assertSame(422, $c->json('POST', '/api/mfa.php?action=start', ['senha' => 'x'])['status']);
        $this->assertStringContainsString('ative a verificação em duas etapas na própria conta do provedor', $c->get('/')['body']);
        // servidor sem ai.secret_key (produção simulada): ativação bloqueada com mensagem clara
        $email = 'mfa44nokey@example.com';
        $this->emailUser($email);
        $p = $this->prod();
        $this->assertSame('/', $p->header($this->passwordLogin($p, $email), 'Location'));
        $p->refreshCsrf();
        $this->assertFalse($p->get('/api/mfa.php')['json']['available']);
        $r = $p->json('POST', '/api/mfa.php?action=start', ['senha' => self::PW]);
        $this->assertSame(503, $r['status']);
        $this->assertStringContainsString('ai.secret_key', $r['json']['erro']);
        $this->assertStringContainsString('ainda não está disponível neste servidor', $p->get('/')['body']);
    }

    public function testOauthRedirectUriHasNoQueryString(): void
    {
        $c = $this->dev();
        foreach (['google', 'microsoft', 'x'] as $prov) {
            $loc = (string)$c->header($c->get('/auth/start.php?provider=' . $prov), 'Location');
            parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
            $this->assertSame('http://127.0.0.1:' . TestEnv::$devPort . '/auth/callback.php', $q['redirect_uri'], $prov);
        }
        // o callback sem ?provider= usa o provedor do login iniciado (state forjado continua recusado)
        $r = $c->get('/auth/callback.php?state=forjado&code=abc');
        $this->assertSame(400, $r['status']);
        $this->assertStringContainsString('state inválido', $r['body']);
        // produção simulada: Microsoft com placeholders e X vazio ficam ocultos
        $home = $this->prod()->get('/')['body'];
        $this->assertStringContainsString('provider=google', $home);
        $this->assertStringNotContainsString('provider=microsoft', $home);
        $this->assertStringNotContainsString('provider=x', $home);
    }
}
