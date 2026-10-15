<?php
/** Correções do QA ao vivo (14/10/2026): edição sem perda, push sem VAPID, convites, formato BR, fusos, privacidade, último login, bypass de teste de carga. */
final class QaFixesTest extends IntegrationCase
{
    private const PW = 'cavalo correto bateria';

    /** Cria conta de e-mail já verificada direto no banco e entra pelo formulário (servidor "produção"). */
    private function prodUser(string $email): Http
    {
        $pdo = db();
        $pdo->prepare('INSERT INTO users (name, email, timezone, password_hash, email_verified_at) VALUES (?,?,?,?,UTC_TIMESTAMP())')
            ->execute(['Prod Teste', $email, 'America/Cuiaba', pw_hash(self::PW)]);
        $uid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO oauth_identities (user_id, provider, subject) VALUES (?, 'email', ?)")->execute([$uid, $email]);
        $c = $this->prod();
        $r = $c->form('/', '/conta/entrar.php', ['email' => $email, 'senha' => self::PW]);
        $this->assertSame(302, $r['status']);
        $c->refreshCsrf();
        return $c;
    }
    private function entriesOn(Http $c, string $date): array
    {
        return $c->get("/api/entries.php?from=$date&to=$date")['json']['entries'];
    }

    public function testEditingKeepsFieldsThatWereNotSent(): void
    {
        $c = $this->user('qaedit');
        $a = $this->entry($c, ['entry_date' => '2026-10-01', 'period' => 'tarde', 'mood' => 1, 'tags' => 'trabalho, café', 'note' => 'nota original', 'sleep_hours' => 6.5, 'anxiety' => 2, 'medication_taken' => 1]);
        // cliente que manda só o humor (ex.: versão antiga, integração): o resto é preservado
        $b = $this->entry($c, ['entry_date' => '2026-10-01', 'period' => 'tarde', 'mood' => -2]);
        $this->assertSame($a['id'], $b['id'], 'mesmo registro (data + período)');
        $e = $this->entriesOn($c, '2026-10-01')[0];
        $this->assertSame(-2, (int)$e['mood']);
        $this->assertSame('trabalho, café', $e['tags']);
        $this->assertSame('nota original', $e['note']);
        $this->assertSame(6.5, (float)$e['sleep_hours']);
        $this->assertSame(2, (int)$e['anxiety']);
        $this->assertSame(1, (int)$e['medication_taken']);
        // apagar de propósito continua possível: o campo enviado vazio limpa
        $this->entry($c, ['entry_date' => '2026-10-01', 'period' => 'tarde', 'mood' => -2, 'tags' => '', 'note' => '', 'sleep_hours' => null, 'anxiety' => null]);
        $e = $this->entriesOn($c, '2026-10-01')[0];
        $this->assertNull($e['tags']);
        $this->assertNull($e['note']);
        $this->assertNull($e['sleep_hours']);
        $this->assertNull($e['anxiety']);
        $this->assertSame(1, (int)$e['medication_taken'], 'medicação não enviada → mantida');
        $this->assertCount(1, $this->entriesOn($c, '2026-10-01'));
    }

    public function testRegisterScreenHasEditUi(): void
    {
        $html = $this->user('qaui')->get('/')['body'];
        foreach (['id="edit-note"', 'id="btn-new-entry"', 'id="entry-date-br"', 'placeholder="dd/mm/aaaa"', 'class="date-native"', 'aria-busy="true"', 'class="sk-bar"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('type="date" id="entry-date" name="entry_date" required', $html);
    }

    public function testPushHiddenAndRejectedWithoutVapid(): void
    {
        $p = $this->prodUser('semvapid@example.com');
        $html = $p->get('/?aba=lembretes')['body'];
        $this->assertStringNotContainsString('value="push"', $html);
        $this->assertStringNotContainsString('value="ambos"', $html);
        $this->assertStringNotContainsString('id="btn-push"', $html);
        $this->assertStringContainsString('id="push-off"', $html);
        $this->assertMatchesRegularExpression('/"vapid":""/', $html);
        foreach (['push', 'ambos'] as $ch) {
            $r = $p->json('POST', '/api/reminders.php', ['time_local' => '08:00', 'days' => [1, 2], 'channel' => $ch]);
            $this->assertSame(422, $r['status'], "canal $ch recusado no servidor");
            $this->assertStringContainsString('e-mail', $r['json']['erro']);
        }
        $r = $p->json('POST', '/api/reminders.php', ['time_local' => '08:00', 'days' => [1, 2]]);
        $this->assertSame(200, $r['status']);
        $g = $p->get('/api/reminders.php')['json'];
        $this->assertFalse($g['push_disponivel']);
        $this->assertSame('email', $g['reminders'][0]['channel'], 'padrão é e-mail');
        // com VAPID configurado (dev) o push continua disponível
        $d = $this->user('comvapid');
        $this->assertStringContainsString('value="push"', $d->get('/')['body']);
        $this->assertSame(200, $d->json('POST', '/api/reminders.php', ['time_local' => '09:00', 'days' => [1], 'channel' => 'push'])['status']);
    }

    public function testInviteToRejectedAddressIsFriendlyAndLogged(): void
    {
        $log = TestEnv::$tmp . '/server.log';
        $before = (int)@filesize($log);
        $c = $this->user('qainvite');
        $r = $c->json('POST', '/api/recipients.php', ['name' => 'Dest', 'email' => 'teste.grok+rejeitar@bipolar.app.br', 'role' => 'psiquiatra', 'frequency' => 'semanal', 'weekday' => 1]);
        $this->assertSame(200, $r['status']);
        $this->assertFalse($r['json']['email_enviado']);
        $this->assertStringContainsString('recusou', $r['json']['aviso']);
        $this->assertStringNotContainsString('SMTP', $r['json']['aviso']);
        clearstatcache();
        usleep(200000);
        $tail = (string)file_get_contents($log, false, null, $before);
        $this->assertStringContainsString('SMTP [convite] DESTINATÁRIO RECUSADO te***@bipolar.app.br', $tail);
        $this->assertStringContainsString('550 5.1.1', $tail);
        $this->assertStringNotContainsString('teste.grok+rejeitar@', $tail, 'e-mail mascarado no log');
        $ok = $c->json('POST', '/api/recipients.php', ['name' => 'Dest2', 'email' => 'dest.ok@example.com', 'role' => 'psiquiatra', 'frequency' => 'semanal', 'weekday' => 1])['json'];
        $this->assertTrue($ok['email_enviado']);
        $this->assertNull($ok['aviso']);
    }

    public function testCsvAndReportUseBrazilianNumbers(): void
    {
        $c = $this->user('qacsv');
        $this->entry($c, ['entry_date' => '2026-10-02', 'period' => 'manha', 'mood' => -1, 'sleep_hours' => 6.5, 'note' => 'a; b']);
        $this->entry($c, ['entry_date' => '2026-10-02', 'period' => 'noite', 'mood' => 0, 'sleep_hours' => 7]);
        $csv = $c->get('/api/account.php?action=export&format=csv')['body'];
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\n", trim(substr($csv, 3)));
        $this->assertStringStartsWith('data;período;humor;', $lines[0]);
        $this->assertStringStartsWith('02/10/2026;Manhã;-1;', $lines[1]);
        $this->assertStringContainsString(';6,5;', $lines[1]);
        $this->assertStringContainsString(';7,0;', $lines[2]);
        $this->assertStringContainsString('"a; b"', $lines[1], 'texto com ; fica entre aspas');
        $this->assertSame(12, count(str_getcsv($lines[1], ';', '"', '\\')));
        $rep = $c->get('/relatorio.php?de=2026-10-01&ate=2026-10-03')['body'];
        $this->assertStringContainsString('7,0 h', $rep, 'V4.1: sono médio por noite (maior valor do dia = 7,0), igual ao Histórico');
        $this->assertStringContainsString('−0,50', $rep, 'humor médio com vírgula');
        $this->assertDoesNotMatchRegularExpression('/\d\.\d+ h/', $rep);
        $this->assertMatchesRegularExpression('/Distribuição<\/h2>/', $rep);
        $this->assertStringContainsString('margin:28px 0 8px">Linha do tempo', $rep, 'espaço entre Distribuição e Linha do tempo');
    }

    public function testTimezonesBrazilFirstInOrder(): void
    {
        $html = $this->user('qatz')->get('/?aba=conta')['body'];
        $order = ['America/Sao_Paulo', 'America/Cuiaba', 'America/Manaus', 'America/Belem', 'America/Fortaleza', 'America/Recife', 'America/Bahia', 'America/Noronha',
            'America/Rio_Branco', 'America/Porto_Velho', 'America/Boa_Vista', 'America/Campo_Grande', 'America/Araguaina', 'America/Maceio', 'America/Santarem', 'America/Eirunepe'];
        $pos = -1;
        foreach ($order as $z) {
            $p = strpos($html, 'value="' . $z . '"');
            $this->assertNotFalse($p, $z);
            $this->assertGreaterThan($pos, $p, "$z na ordem");
            $pos = $p;
        }
        $others = strpos($html, 'label="Outros países"');
        $this->assertGreaterThan($pos, $others);
        $this->assertStringContainsString('<option value="Europe/Lisbon">Europa · Lisboa</option>', $html);
        $this->assertSame(1, substr_count($html, 'America/Sao_Paulo"'), 'sem duplicar fusos do Brasil em "Outros"');
        $this->assertMatchesRegularExpression('#value="America/Cuiaba" selected>Cuiabá — MT#', $html);
    }

    public function testPrivacyListsOnlyEnabledLoginOptions(): void
    {
        $prod = $this->prod()->get('/privacidade.php')['body'];
        $this->assertStringContainsString('e-mail e senha ou conta Google.', $prod);
        $this->assertStringNotContainsString('Microsoft', $prod);
        $this->assertStringNotContainsString(' X ', $prod);
        $dev = $this->dev()->get('/privacidade.php')['body'];
        $this->assertStringContainsString('e-mail e senha ou conta Google, Microsoft ou X', $dev);
    }

    public function testLastLoginAtSetOnEveryLogin(): void
    {
        $this->user('qalast');
        $this->assertNotNull(db()->query('SELECT last_login_at FROM users WHERE id = ' . $this->uid('qalast'))->fetchColumn(), 'login OAuth/dev');
        $this->prodUser('ultimo@example.com');
        $this->assertNotNull(db()->query("SELECT last_login_at FROM users WHERE email = 'ultimo@example.com'")->fetchColumn(), 'login por e-mail');
        // cadastro com entrada automática (verificação desligada) passa por login_user(), que grava last_login_at
        $src = (string)file_get_contents(TestEnv::ROOT . '/public_html/conta/cadastro.php');
        $this->assertStringContainsString("login_user((int)\$r['uid'])", $src);
        $this->assertStringContainsString('last_login_at', (string)file_get_contents(TestEnv::ROOT . '/app/lib/auth.php'));
    }

    public function testLoadtestBypassOnlyWithMatchingTokenAndConfig(): void
    {
        $token = str_repeat('t3st-bypass-', 4);
        $email = 'bypass@example.com';
        $try = function (Http $c, array $h = []) use ($email): string {
            $page = $c->get('/', $h)['body'];
            preg_match('/name="csrf" value="([a-f0-9]{64})"/', $page, $m);
            $r = $c->request('POST', '/conta/entrar.php', http_build_query(['csrf' => $m[1], 'email' => $email, 'senha' => 'errada-123']), ['Content-Type' => 'application/x-www-form-urlencoded'] + $h);
            return $c->get('/', $h)['body'];
        };
        $c = $this->dev();
        for ($i = 0; $i < 12; $i++) $last = $try($c, ['X-Loadtest-Bypass' => $token]);
        $this->assertStringNotContainsString('Muitas tentativas', $last, 'com o token certo, sem limite');
        db()->exec('DELETE FROM rate_limits');
        for ($i = 0; $i < 12; $i++) $last = $try($c, ['X-Loadtest-Bypass' => 'token-errado-' . str_repeat('x', 40)]);
        $this->assertStringContainsString('Muitas tentativas', $last, 'token errado não libera');
        db()->exec('DELETE FROM rate_limits');
        $p = $this->prod();
        for ($i = 0; $i < 12; $i++) $last = $try($p, ['X-Loadtest-Bypass' => $token]);
        $this->assertStringContainsString('Muitas tentativas', $last, 'config vazio = desativado');
    }

    public function testLoadtestAdminPanelRemoved(): void
    {
        // V4.3: sem painel web de teste de carga (contas de teste só por tools/ na linha de comando)
        $this->assertFileDoesNotExist(TestEnv::ROOT . '/public_html/loadtest-admin.php');
        $this->assertStringNotContainsString('Teste de carga', $this->prod()->get('/loadtest-admin.php')['body']);
    }
}
