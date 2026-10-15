<?php
final class EmailAuthTest extends IntegrationCase
{
    private const PW = 'cavalo correto bateria';

    private function signup(Http $c, string $email, string $pw = self::PW, string $name = 'Ana Teste', bool $consent = true): array
    {
        $f = ['nome' => $name, 'email' => $email, 'senha' => $pw];
        if ($consent) $f['consentimento'] = '1';
        return $c->form('/conta/cadastro.php', '/conta/cadastro.php', $f);
    }
    private function login(Http $c, string $email, string $pw): array
    {
        return $c->form('/', '/conta/entrar.php', ['email' => $email, 'senha' => $pw]);
    }
    private function linkFrom(string $mail, string $path): string
    {
        $html = TestEnv::htmlPart($mail);
        $this->assertMatchesRegularExpression('#' . preg_quote($path, '#') . '\?t=([A-Za-z0-9_-]+)#', $html);
        preg_match('#' . preg_quote($path, '#') . '\?t=([A-Za-z0-9_-]+)#', $html, $m);
        return $m[1];
    }
    /** @return list<string> */
    private function mailsTo(string $email): array
    {
        return array_values(array_filter(TestEnv::mails(), fn ($m) => str_contains($m, "RCPT TO:<$email>")));
    }
    private function loggedIn(Http $c): bool { return $c->get('/api/entries.php')['status'] === 200; }
    private function normalize(string $html): string
    {
        return (string)preg_replace(['/name="csrf" value="[a-f0-9]+"/', '/<strong>[^<]*@[^<]*<\/strong>/'], '', $html);
    }
    private function uidByEmail(string $email): int
    {
        $st = db()->prepare("SELECT user_id FROM oauth_identities WHERE provider = 'email' AND subject = ?");
        $st->execute([$email]);
        return (int)$st->fetchColumn();
    }

    public function testSignupVerifyAndLogin(): void
    {
        TestEnv::clearMails();
        $c = $this->dev();
        $r = $this->signup($c, 'Ana@Example.com');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Verifique seu e-mail', $r['body']);
        $uid = $this->uidByEmail('ana@example.com');
        $this->assertGreaterThan(0, $uid, 'e-mail normalizado em minúsculas');
        $row = db()->query("SELECT password_hash, email_verified_at FROM users WHERE id = $uid")->fetch();
        $this->assertNull($row['email_verified_at']);
        $this->assertTrue(password_verify(self::PW, $row['password_hash']));
        $this->assertStringStartsWith(defined('PASSWORD_ARGON2ID') ? '$argon2id$' : '$2y$', $row['password_hash']);

        $mails = $this->mailsTo('ana@example.com');
        $this->assertCount(1, $mails);
        $tok = $this->linkFrom($mails[0], '/conta/verificar.php');
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM auth_tokens WHERE token_hash = " . db()->quote($tok))->fetchColumn(), 'token não é guardado em claro');
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM auth_tokens WHERE token_hash = '" . hash('sha256', $tok) . "'")->fetchColumn());

        // antes de verificar: login com senha certa não entra
        $r = $this->login($c, 'ana@example.com', self::PW);
        $this->assertSame('/conta/reenviar.php', $c->header($r, 'Location'));
        $this->assertStringContainsString('Confirme seu e-mail', $c->follow($r)['body']);
        $this->assertFalse($this->loggedIn($c));

        // GET só mostra o botão; POST confirma
        $v = $this->dev();
        $this->assertStringContainsString('Confirmar meu e-mail', $v->get('/conta/verificar.php?t=' . $tok)['body']);
        $this->assertNull(db()->query("SELECT email_verified_at FROM users WHERE id = $uid")->fetchColumn());
        $r = $v->form('/conta/verificar.php?t=' . $tok, '/conta/verificar.php', ['t' => $tok]);
        $this->assertSame('/', $v->header($r, 'Location'));
        $this->assertTrue($this->loggedIn($v), 'confirmação entra na conta');
        $this->assertNotNull(db()->query("SELECT email_verified_at FROM users WHERE id = $uid")->fetchColumn());
        // uso único
        $this->assertStringContainsString('Link inválido ou expirado', $this->dev()->get('/conta/verificar.php?t=' . $tok)['body']);

        // login normal (e-mail com maiúsculas também funciona) e regeneração de sessão
        $l = $this->dev();
        preg_match('/bpsid=([^;]+)/', (string)$l->header($l->get('/'), 'Set-Cookie'), $before);
        $r = $this->login($l, 'ANA@example.com', self::PW);
        $this->assertSame('/', $l->header($r, 'Location'));
        preg_match('/bpsid=([^;]+)/', implode("\n", $r['headers']['set-cookie'] ?? []), $after);
        $this->assertNotSame($before[1] ?? '', $after[1] ?? '', 'ID de sessão regenerado no login');
        $this->assertTrue($this->loggedIn($l));
        $this->assertStringContainsString('Olá, Ana', $l->get('/')['body']);
    }

    public function testWrongPasswordAndUnknownEmailAreIndistinguishable(): void
    {
        $this->verifiedUser('bia@example.com');
        $msgs = [];
        foreach ([['bia@example.com', 'senha errada 123'], ['ninguem@example.com', 'senha errada 123'], ['nao-e-email', 'x']] as [$e, $p]) {
            $c = $this->dev();
            $r = $this->login($c, $e, $p);
            $this->assertSame('/', $c->header($r, 'Location'));
            $page = $c->follow($r)['body'];
            $this->assertStringContainsString('E-mail ou senha incorretos.', $page);
            $this->assertFalse($this->loggedIn($c));
            $msgs[] = preg_replace('/value="[^"]*"/', '', $page);
        }
        $this->assertSame($msgs[0], $msgs[1], 'mesma página para senha errada e e-mail inexistente');
    }

    private function verifiedUser(string $email, string $pw = self::PW): int
    {
        $this->signup($this->dev(), $email, $pw);
        $uid = $this->uidByEmail($email);
        db()->exec("UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = $uid");
        db()->exec('DELETE FROM rate_limits');
        return $uid;
    }

    public function testSignupValidationConsentAndDuplicateIsEnumerationSafe(): void
    {
        $c = $this->dev();
        $r = $this->signup($c, 'carla@example.com', self::PW, 'Carla', false);
        $this->assertStringContainsString('LGPD', $r['body']);
        $this->assertSame(0, $this->uidByEmail('carla@example.com'), 'sem consentimento não cria conta');
        $this->assertStringContainsString('pelo menos 8', $this->signup($c, 'carla@example.com', 'curta')['body']);
        $this->assertStringContainsString('muito comum', $this->signup($c, 'carla@example.com', '12345678')['body']);
        $this->assertStringContainsString('e-mail válido', $this->signup($c, 'carla@', self::PW)['body']);
        db()->exec('DELETE FROM rate_limits');

        TestEnv::clearMails();
        $new = $this->signup($this->dev(), 'carla@example.com');
        $dup = $this->signup($this->dev(), 'carla@example.com', 'outra senha boa 99');
        $this->assertSame($this->normalize($new['body']), $this->normalize($dup['body']), 'resposta idêntica para e-mail novo e existente');
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM oauth_identities WHERE provider = 'email' AND subject = 'carla@example.com'")->fetchColumn());
        $mails = $this->mailsTo('carla@example.com');
        $this->assertCount(2, $mails);
        $this->assertStringContainsString('Você já tem uma conta', TestEnv::htmlPart($mails[1]), 'dono do e-mail é avisado');
        $this->assertTrue(password_verify(self::PW, (string)db()->query('SELECT password_hash FROM users WHERE id = ' . $this->uidByEmail('carla@example.com'))->fetchColumn()), 'senha original não muda');
    }

    public function testXssInNameEscapedInEmailAndPage(): void
    {
        TestEnv::clearMails();
        $this->signup($this->dev(), 'xssname@example.com', self::PW, '<script>alert(1)</script>');
        $html = TestEnv::htmlPart($this->mailsTo('xssname@example.com')[0]);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testPasswordResetFlowExpiryAndSingleUse(): void
    {
        $uid = $this->verifiedUser('davi@example.com');
        $other = $this->dev();
        $this->login($other, 'davi@example.com', self::PW);
        $this->assertTrue($this->loggedIn($other));

        TestEnv::clearMails();
        $ask = function (string $email) { $c = $this->dev(); return $c->form('/conta/esqueci.php', '/conta/esqueci.php', ['email' => $email])['body']; };
        $a = $ask('davi@example.com');
        $b = $ask('naoexiste@example.com');
        $this->assertSame($this->normalize($a), $this->normalize($b), 'mesma resposta para conta existente e inexistente');
        $this->assertStringContainsString('Se existir uma conta', $a);
        $this->assertCount(0, $this->mailsTo('naoexiste@example.com'));
        $tok1 = $this->linkFrom($this->mailsTo('davi@example.com')[0], '/conta/redefinir.php');

        // expiração
        db()->exec("UPDATE auth_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE token_hash = '" . hash('sha256', $tok1) . "'");
        $c = $this->dev();
        $this->assertStringContainsString('Link inválido ou expirado', $c->get('/conta/redefinir.php?t=' . $tok1)['body']);
        $this->assertStringContainsString('Link inválido ou expirado', $c->form('/conta/esqueci.php', '/conta/redefinir.php', ['t' => $tok1, 'senha' => 'nova senha forte 1', 'senha2' => 'nova senha forte 1'])['body']);

        db()->exec('DELETE FROM rate_limits');
        TestEnv::clearMails();
        $ask('davi@example.com');
        $ask('davi@example.com');
        $links = array_map(fn ($m) => $this->linkFrom($m, '/conta/redefinir.php'), $this->mailsTo('davi@example.com'));
        $this->assertCount(2, $links);
        [$tok2, $tok3] = $links;

        // senhas diferentes / fraca
        $this->assertStringContainsString('não conferem', $c->form('/conta/redefinir.php?t=' . $tok2, '/conta/redefinir.php', ['t' => $tok2, 'senha' => 'nova senha forte 1', 'senha2' => 'outra coisa 1'])['body']);
        $this->assertStringContainsString('muito comum', $c->form('/conta/redefinir.php?t=' . $tok2, '/conta/redefinir.php', ['t' => $tok2, 'senha' => 'password', 'senha2' => 'password'])['body']);
        // sucesso
        $r = $c->form('/conta/redefinir.php?t=' . $tok2, '/conta/redefinir.php', ['t' => $tok2, 'senha' => 'nova senha forte 1', 'senha2' => 'nova senha forte 1']);
        $this->assertSame('/', $c->header($r, 'Location'));
        $this->assertStringContainsString('Senha alterada', $c->follow($r)['body']);
        // uso único e invalidação do outro token pendente
        $this->assertStringContainsString('Link inválido', $this->dev()->get('/conta/redefinir.php?t=' . $tok2)['body']);
        $this->assertStringContainsString('Link inválido', $this->dev()->get('/conta/redefinir.php?t=' . $tok3)['body']);
        // sessão antiga encerrada; senha antiga não entra; nova entra
        $this->assertFalse($this->loggedIn($other), 'outras sessões encerradas após redefinir');
        db()->exec('DELETE FROM rate_limits');
        $this->assertFalse($this->loggedIn($this->loginClient('davi@example.com', self::PW)));
        $this->assertTrue($this->loggedIn($this->loginClient('davi@example.com', 'nova senha forte 1')));
        $this->assertSame(1, (int)db()->query("SELECT session_epoch FROM users WHERE id = $uid")->fetchColumn());
    }
    private function loginClient(string $e, string $p): Http { $c = $this->dev(); $this->login($c, $e, $p); return $c; }

    public function testResendVerificationIsGenericAndRateLimited(): void
    {
        $this->signup($this->dev(), 'eva@example.com');
        db()->exec('DELETE FROM rate_limits');
        TestEnv::clearMails();
        $send = fn (string $e) => $this->dev()->form('/conta/reenviar.php', '/conta/reenviar.php', ['email' => $e])['body'];
        $a = $send('eva@example.com');
        $b = $send('fantasma@example.com');
        $this->assertSame($this->normalize($a), $this->normalize($b));
        $this->assertCount(1, $this->mailsTo('eva@example.com'));
        $send('eva@example.com'); $send('eva@example.com'); $send('eva@example.com');
        $this->assertCount(3, $this->mailsTo('eva@example.com'), 'máximo 3 por hora por e-mail');
    }

    public function testLoginRateLimitPerEmailAndIp(): void
    {
        $this->verifiedUser('fabi@example.com');
        for ($i = 0; $i < 10; $i++) $this->login($this->dev(), 'fabi@example.com', 'errada ' . $i);
        $c = $this->dev();
        $r = $this->login($c, 'fabi@example.com', self::PW);
        $this->assertStringContainsString('Muitas tentativas', $c->follow($r)['body']);
        $this->assertFalse($this->loggedIn($c), 'bloqueado mesmo com a senha certa');
        // outro e-mail ainda funciona (limite por e-mail), até o limite por IP
        db()->exec('DELETE FROM rate_limits');
        $this->verifiedUser('gabi@example.com');
        $this->assertTrue($this->loggedIn($this->loginClient('gabi@example.com', self::PW)));
        for ($i = 0; $i < 30; $i++) $this->login($this->dev(), "x$i@example.com", 'qualquer');
        $c = $this->dev();
        $this->assertStringContainsString('Muitas tentativas', $c->follow($this->login($c, 'gabi@example.com', self::PW))['body']);
    }

    public function testSignupRateLimitPerIp(): void
    {
        for ($i = 0; $i < 5; $i++) $this->signup($this->dev(), "rl$i@example.com");
        $r = $this->signup($this->dev(), 'rl9@example.com');
        $this->assertStringContainsString('Muitas tentativas de cadastro', $r['body']);
        $this->assertSame(0, $this->uidByEmail('rl9@example.com'));
    }

    public function testCsrfOnAllAuthForms(): void
    {
        $uid = $this->verifiedUser('hugo@example.com');
        TestEnv::clearMails();
        $c = $this->dev();
        $c->form('/', '/conta/cadastro.php', ['nome' => 'X', 'email' => 'csrf-new@example.com', 'senha' => self::PW, 'consentimento' => '1'], false);
        $this->assertSame(0, $this->uidByEmail('csrf-new@example.com'));
        $r = $c->form('/', '/conta/entrar.php', ['email' => 'hugo@example.com', 'senha' => self::PW], false);
        $this->assertStringContainsString('sessão expirou', $c->follow($r)['body']);
        $this->assertFalse($this->loggedIn($c));
        $c->form('/', '/conta/esqueci.php', ['email' => 'hugo@example.com'], false);
        $c->form('/', '/conta/reenviar.php', ['email' => 'hugo@example.com'], false);
        $this->assertCount(0, TestEnv::mails());
        $tok = create_auth_token($uid, 'reset', 600);
        $c->form('/', '/conta/redefinir.php', ['t' => $tok, 'senha' => 'hackeada 12345', 'senha2' => 'hackeada 12345'], false);
        $vt = create_auth_token($uid, 'verify', 600);
        $c->form('/', '/conta/verificar.php', ['t' => $vt], false);
        $this->assertNotNull(find_auth_token($tok, 'reset'), 'token não consumido sem CSRF');
        $this->assertNotNull(find_auth_token($vt, 'verify'));
        $this->assertTrue(password_verify(self::PW, (string)db()->query("SELECT password_hash FROM users WHERE id = $uid")->fetchColumn()));
    }

    public function testChangePasswordInAccount(): void
    {
        $this->verifiedUser('ines@example.com');
        $a = $this->loginClient('ines@example.com', self::PW);
        $a->refreshCsrf();
        $b = $this->loginClient('ines@example.com', self::PW);
        $this->assertStringContainsString('id="form-password"', $a->get('/?aba=conta')['body']);
        $this->assertSame(403, $a->json('POST', '/api/account.php?action=password', ['atual' => self::PW, 'nova' => 'outra senha boa 1'], false)['status']);
        $r = $a->json('POST', '/api/account.php?action=password', ['atual' => 'errada', 'nova' => 'outra senha boa 1']);
        $this->assertSame(422, $r['status']);
        $this->assertSame('Senha atual incorreta.', $r['json']['erro']);
        $this->assertSame(422, $a->json('POST', '/api/account.php?action=password', ['atual' => self::PW, 'nova' => '123'])['status']);
        $r = $a->json('POST', '/api/account.php?action=password', ['atual' => self::PW, 'nova' => 'outra senha boa 1']);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertTrue($this->loggedIn($a), 'sessão atual continua');
        $this->assertFalse($this->loggedIn($b), 'outro aparelho sai');
        $a->csrf = $r['json']['csrf'];
        $this->assertSame(200, $a->json('POST', '/api/entries.php', ['entry_date' => '2026-10-10', 'period' => 'manha', 'mood' => 0])['status'], 'novo token CSRF funciona');
        // exportação não contém o hash
        $exp = $a->get('/api/account.php?action=export&format=json');
        $this->assertStringNotContainsString('password_hash', $exp['body']);
        $this->assertStringNotContainsString('$argon2', $exp['body']);
        $this->assertStringNotContainsString('$2y$', $exp['body']);
        $this->assertTrue($exp['json']['usuario']['tem_senha']);
    }

    public function testOauthOnlyAccountHasNoPasswordFormAndIsNotMerged(): void
    {
        // conta "Google" com o mesmo e-mail de uma conta por senha → contas separadas
        $emailUid = $this->verifiedUser('joao@example.com');
        $googleUid = oauth_login_or_register('google', ['subject' => 'g-123', 'name' => 'João G', 'email' => 'joao@example.com']);
        $this->assertNotSame($emailUid, $googleUid);
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM users WHERE id = $googleUid AND password_hash IS NOT NULL")->fetchColumn());
        // conta OAuth não consegue usar "esqueci minha senha" para virar conta por senha
        TestEnv::clearMails();
        $this->dev()->form('/conta/esqueci.php', '/conta/esqueci.php', ['email' => 'joao@example.com']);
        $tok = $this->linkFrom($this->mailsTo('joao@example.com')[0], '/conta/redefinir.php');
        $this->assertSame($emailUid, (int)find_auth_token($tok, 'reset')['user_id'], 'token pertence somente à conta por e-mail');
        $c = $this->user('oauthonly');
        $body = $c->get('/?aba=conta')['body'];
        $this->assertStringNotContainsString('id="form-password"', $body);
        $this->assertStringContainsString('conta externa', $body);
        $this->assertSame(422, $c->json('POST', '/api/account.php?action=password', ['atual' => '', 'nova' => 'qualquer senha 1'])['status']);
    }

    public function testLandingWorksWithOauthUnconfigured(): void
    {
        $p = $this->prod();
        $html = $p->get('/')['body'];
        $this->assertStringContainsString('action="/conta/entrar.php"', $html);
        $this->assertStringContainsString('Entrar com Google', $html);
        $this->assertStringNotContainsString('Entrar com Microsoft', $html, 'placeholders do exemplo ocultam o botão');
        $this->assertStringNotContainsString('Entrar com X', $html, 'credenciais vazias ocultam o botão');
        $this->assertSame(400, $p->get('/auth/start.php?provider=microsoft')['status']);
        $this->assertSame(400, $p->get('/auth/start.php?provider=x')['status']);
        foreach (['/conta/cadastro.php', '/conta/esqueci.php', '/conta/reenviar.php', '/conta/verificar.php?t=x', '/conta/redefinir.php?t=x'] as $u) {
            $this->assertSame(200, $p->get($u)['status'], $u);
        }
        $this->assertSame(302, $p->get('/conta/entrar.php')['status'], 'GET em entrar.php só redireciona');
    }
}
