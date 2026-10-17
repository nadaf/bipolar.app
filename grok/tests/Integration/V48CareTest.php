<?php
require_once TestEnv::ROOT . '/app/lib/care.php';
/**
 * V4.8: compartilhamento com profissionais — isolamento, IDOR, escopo, revogação, prazo, registro de acessos,
 * convite por link (conta nova) preso ao e-mail. Os e-mails só vão para o SMTP falso local (endereços @example.com).
 */
final class V48CareTest extends IntegrationCase
{
    private function pro(string $u): Http
    {
        $c = $this->user($u);
        $r = $c->json('POST', '/api/care.php?action=profile', ['is_professional' => true, 'kind' => 'psiquiatra', 'reg' => 'CRM-MT 1234']);
        $this->assertSame(200, $r['status'], $r['body']);
        return $c;
    }
    private function patientWithData(string $u): Http
    {
        $c = $this->user($u);
        $mods = array_fill_keys(array_keys(DAY_MODULES), true);
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => $mods]);
        $this->entry($c, ['entry_date' => '2026-10-02', 'period' => 'manha', 'mood' => 2, 'sleep_hours' => 4, 'note' => 'NOTA-SECRETA']);
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-02', 'period' => 'manha', 'th_grandiose' => 3, 'th_death' => 2, 'stress_level' => 2, 'cigarettes' => 7]);
        $c->json('POST', '/api/day.php?action=sex', ['entry_date' => '2026-10-02', 'libido' => 2, 'activities' => ['nova_parceria'], 'note' => 'SEXO-SECRETO']);
        $c->json('POST', '/api/day.php?action=subst_add', ['entry_date' => '2026-10-02', 'substance' => 'cannabis', 'amount' => 1, 'period' => 'noite']);
        return $c;
    }
    /** convite por código; devolve o id do compartilhamento aceito */
    private function share(Http $patient, Http $pro, array $scope = ['humor', 'sono', 'estresse_alimentacao', 'pensamentos'], array $extra = []): int
    {
        $code = $pro->json('POST', '/api/care.php?action=code', [])['json']['code'];
        $r = $patient->json('POST', '/api/care.php?action=invite', ['code' => $code, 'scope' => $scope] + $extra);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(200, $pro->json('POST', '/api/care.php?action=accept', ['id' => $r['json']['id']])['status']);
        return (int)$r['json']['id'];
    }

    public function testCodeInviteAcceptScopeAndReadOnlyView(): void
    {
        $p = $this->patientWithData('cpat1');
        $d = $this->pro('cpro1');
        $code = $d->json('POST', '/api/care.php?action=code', [])['json']['code'];
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM care_codes WHERE code_hash = '" . $code . "'")->fetchColumn(), 'código guardado só como hash');
        $inv = $p->json('POST', '/api/care.php?action=invite', ['code' => strtolower($code), 'scope' => ['humor', 'sono', 'estresse_alimentacao', 'pensamentos']]);
        $this->assertSame(200, $inv['status'], $inv['body']);
        $id = (int)$inv['json']['id'];
        $this->assertSame(422, $this->user('cpat1b')->json('POST', '/api/care.php?action=invite', ['code' => $code, 'scope' => ['humor']])['status'], 'código de uso único');
        // antes de aceitar: nada
        $this->assertSame(404, $d->get('/paciente.php?c=' . $id)['status']);
        $this->assertCount(1, $d->get('/api/care.php')['json']['pending']);
        $this->assertSame(200, $d->json('POST', '/api/care.php?action=accept', ['id' => $id])['status']);
        $st = $d->get('/api/care.php')['json'];
        $this->assertSame([], $st['pending']);
        $this->assertSame($id, $st['patients'][0]['id']);
        $v = $d->get('/paciente.php?c=' . $id . '&de=2026-10-01&ate=2026-10-05');
        $this->assertSame(200, $v['status']);
        $b = $v['body'];
        $this->assertStringContainsString('só leitura', $b);
        $this->assertStringContainsString('Humor médio', $b);
        $this->assertStringContainsString('grandiosidade', $b);
        foreach (['NOTA-SECRETA', 'SEXO-SECRETO', 'Sexualidade —', 'Substâncias —', 'Pensamentos de morte —', 'Nicotina — cigarros'] as $no) $this->assertStringNotContainsString($no, $b, "fora do escopo: $no");
        $this->assertStringNotContainsString('<form id="form-day"', $b);
        // registro de acessos visível ao paciente
        $log = $p->get('/api/care.php')['json']['log'];
        $this->assertSame('visualizou', $log[0]['acao_codigo']);
        $this->assertStringContainsString('CRM-MT 1234 declarado', $log[0]['quem']);
        $this->assertSame('2026-10-01', $log[0]['de']);
        // nenhuma API do paciente responde ao profissional com os dados do paciente
        $this->assertSame([], $d->get('/api/day.php?from=2026-10-02&to=2026-10-02')['json']['markers']);
        $this->assertSame([], $d->get('/api/entries.php?from=2026-10-02&to=2026-10-02')['json']['entries']);
    }

    public function testSensitiveScopesNeedPinAndShowOnlyWhenGranted(): void
    {
        $p = $this->patientWithData('cpat2');
        $p->json('POST', '/api/account.php?action=sexpin', ['pin' => '2468']);
        $p->json('POST', '/api/day.php?action=lock', []);
        $d = $this->pro('cpro2');
        $code = $d->json('POST', '/api/care.php?action=code', [])['json']['code'];
        $this->assertSame(423, $p->json('POST', '/api/care.php?action=invite', ['code' => $code, 'scope' => ['humor', 'pensamentos_morte']])['status']);
        $this->assertSame(200, $p->json('POST', '/api/day.php?action=unlock', ['pin' => '2468'])['status']);
        $r = $p->json('POST', '/api/care.php?action=invite', ['code' => $code, 'scope' => ['humor', 'sexualidade', 'substancias', 'pensamentos_morte']]);
        $this->assertSame(200, $r['status'], $r['body']);
        $d->json('POST', '/api/care.php?action=accept', ['id' => $r['json']['id']]);
        $b = $d->get('/paciente.php?c=' . $r['json']['id'] . '&de=2026-10-01&ate=2026-10-05')['body'];
        $this->assertStringContainsString('Pensamentos de morte —', $b);
        $this->assertStringContainsString('Sexualidade —', $b);
        $this->assertStringContainsString('Nicotina — cigarros', $b);
        $this->assertStringNotContainsString('SEXO-SECRETO', $b, 'nunca anotações');
        $this->assertStringNotContainsString('Dias com estresse', $b, 'estresse não foi liberado');
        // o PIN do paciente não vale para o profissional, e o profissional não precisa dele
        $this->assertSame(200, $d->get('/paciente.php?c=' . $r['json']['id'])['status']);
        // reduzir escopo não pede PIN; aumentar pede
        $p->json('POST', '/api/day.php?action=lock', []);
        $this->assertSame(200, $p->json('POST', '/api/care.php?action=update', ['id' => $r['json']['id'], 'scope' => ['humor']])['status']);
        $this->assertStringNotContainsString('Pensamentos de morte —', $d->get('/paciente.php?c=' . $r['json']['id'] . '&de=2026-10-01&ate=2026-10-05')['body'], 'escopo novo vale na hora');
        $this->assertSame(423, $p->json('POST', '/api/care.php?action=update', ['id' => $r['json']['id'], 'scope' => ['humor', 'sexualidade']])['status']);
    }

    public function testIdorAndIsolation(): void
    {
        $p = $this->patientWithData('cpat3');
        $d = $this->pro('cpro3');
        $id = $this->share($p, $d);
        $other = $this->pro('cpro3x');
        $this->assertSame(404, $other->get('/paciente.php?c=' . $id)['status'], 'outro profissional');
        $this->assertSame(404, $this->user('cpat3x')->get('/paciente.php?c=' . $id)['status'], 'conta comum');
        $this->assertSame(404, $p->get('/paciente.php?c=' . $id)['status'], 'nem o próprio paciente usa a visão do profissional');
        $this->assertSame(404, $d->get('/paciente.php?c=' . ($id + 1000))['status']);
        $this->assertSame(404, $d->get('/paciente.php?c=abc')['status']);
        foreach (['update' => ['id' => $id, 'scope' => ['humor']], 'link' => ['id' => $id], 'revoke' => ['id' => $id], 'accept' => ['id' => $id], 'decline' => ['id' => $id]] as $a => $body) {
            $this->assertSame(404, $other->json('POST', '/api/care.php?action=' . $a, $body)['status'], "IDOR em $a");
        }
        $this->assertSame(404, $d->json('POST', '/api/care.php?action=update', ['id' => $id, 'scope' => ['humor', 'sono', 'estresse_alimentacao', 'pensamentos', 'substancias']])['status'], 'profissional não muda escopo');
        $this->assertSame([], $other->get('/api/care.php')['json']['patients']);
        $this->assertSame([], $d->get('/api/care.php')['json']['shares'], 'o profissional não vê os compartilhamentos do paciente como se fossem dele');
        // sem login
        $this->assertSame(302, $this->dev()->get('/paciente.php?c=' . $id)['status']);
        $this->assertSame(401, $this->dev()->get('/api/care.php')['status']);
        // CSRF
        $this->assertSame(403, $p->json('POST', '/api/care.php?action=revoke', ['id' => $id], 'x')['status']);
        // perfil de profissional desligado: perde o acesso
        $d->json('POST', '/api/care.php?action=profile', ['is_professional' => false]);
        $this->assertSame(404, $d->get('/paciente.php?c=' . $id)['status']);
        $d->json('POST', '/api/care.php?action=profile', ['is_professional' => true]);
        $this->assertSame(200, $d->get('/paciente.php?c=' . $id)['status']);
    }

    public function testRevocationIsImmediateAndExpiry(): void
    {
        $p = $this->patientWithData('cpat4');
        $d = $this->pro('cpro4');
        $id = $this->share($p, $d);
        $this->assertSame(200, $d->get('/paciente.php?c=' . $id)['status']);
        $this->assertSame(200, $p->json('POST', '/api/care.php?action=revoke', ['id' => $id])['status']);
        $this->assertSame(404, $d->get('/paciente.php?c=' . $id)['status'], 'revogado vale na hora');
        $this->assertSame([], $d->get('/api/care.php')['json']['patients']);
        $this->assertSame(409, $p->json('POST', '/api/care.php?action=revoke', ['id' => $id])['status']);
        $this->assertSame('revogado', $p->get('/api/care.php')['json']['shares'][0]['status']);
        // prazo: dados antes de data_from não aparecem e o acesso acaba depois de expires_on
        $id2 = $this->share($p, $d, ['humor'], ['data_from' => '2026-10-03']);
        $b = $d->get('/paciente.php?c=' . $id2 . '&de=2026-10-01&ate=2026-10-05')['body'];
        $this->assertStringContainsString('Nenhum registro de humor', $b, 'registro de 02/10 é anterior ao início liberado');
        $this->assertSame(200, $p->json('POST', '/api/care.php?action=update', ['id' => $id2, 'scope' => ['humor', 'sono'], 'data_from' => '2026-10-03'])['status']);
        $this->assertSame(422, $p->json('POST', '/api/care.php?action=update', ['id' => $id2, 'scope' => ['humor'], 'expires_on' => '2020-01-01'])['status']);
        db()->prepare('UPDATE care_shares SET expires_on = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-2 days')), $id2]);
        $this->assertSame(404, $d->get('/paciente.php?c=' . $id2)['status'], 'expirado');
        $this->assertSame('expirado', $p->get('/api/care.php')['json']['shares'][0]['status']);
        // profissional também pode encerrar
        $id3 = $this->share($p, $d, ['sono']);
        $this->assertSame(200, $d->json('POST', '/api/care.php?action=revoke', ['id' => $id3])['status']);
        $this->assertSame(404, $d->get('/paciente.php?c=' . $id3)['status']);
        $acts = array_column($p->get('/api/care.php')['json']['log'], 'acao_codigo');
        foreach (['convite', 'aceito', 'visualizou', 'revogado', 'escopo'] as $a) $this->assertContains($a, $acts);
    }

    public function testEmailInviteLinkForNewAccountIsBoundToEmail(): void
    {
        TestEnv::clearMails();
        $p = $this->patientWithData('cpat5');
        $email = 'nova-dra-v48@example.com';
        $r = $p->json('POST', '/api/care.php?action=invite', ['email' => $email, 'scope' => ['humor']]);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(14, $r['json']['dias']);
        $this->assertTrue($r['json']['email_enviado'], 'SMTP configurado (servidor falso local) → também por e-mail');
        $this->assertMatchesRegularExpression('#/convite-profissional\.php\?t=[A-Za-z0-9_-]{43}$#', $r['json']['link']);
        $mails = array_values(array_filter(TestEnv::mails(), fn ($m) => str_contains($m, "RCPT TO:<$email>")));
        $this->assertCount(1, $mails);
        foreach (TestEnv::mails() as $m) $this->assertMatchesRegularExpression('/RCPT TO:<[^>]+@example\.com>/', $m, 'nenhum e-mail para fora');
        $path = (string)parse_url($r['json']['link'], PHP_URL_PATH) . '?' . (string)parse_url($r['json']['link'], PHP_URL_QUERY);
        $t = substr($path, strpos($path, 't=') + 2);
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM care_shares WHERE invite_token_hash = '" . $t . "'")->fetchColumn(), 'token guardado só como hash');
        // mesma resposta com ou sem conta (não revela quem é usuário)
        $r2 = $p->json('POST', '/api/care.php?action=invite', ['email' => 'devcpro5x@example.com', 'scope' => ['humor']]);
        $this->assertSame(array_keys($r['json']), array_keys($r2['json']));
        $this->assertSame(409, $p->json('POST', '/api/care.php?action=invite', ['email' => $email, 'scope' => ['humor']])['status']);
        // anônimo abre o link → cadastro de profissional com o e-mail travado
        $anon = $this->dev();
        $land = $anon->get($path)['body'];
        $this->assertStringContainsString('/conta/cadastro.php?convite=', $land);
        $this->assertStringNotContainsString($email, $land, 'e-mail mascarado');
        $cad = $anon->get('/conta/cadastro.php?convite=' . $t)['body'];
        $this->assertMatchesRegularExpression('/name="email"[^>]*value="' . preg_quote($email, '/') . '"[^>]*readonly/', $cad);
        $this->assertMatchesRegularExpression('/name="profissional" value="1" checked/', $cad);
        $this->assertStringContainsString('CRP/CRM', $cad);
        // tentar trocar o e-mail no envio não adianta: a conta é criada com o e-mail do convite
        $res = $anon->form('/conta/cadastro.php?convite=' . $t, '/conta/cadastro.php', ['convite' => $t, 'nome' => 'Dra Nova', 'email' => 'outro-v48@example.com', 'senha' => 'cavalo correto bateria', 'consentimento' => '1', 'profissional' => '1', 'tipo' => 'psicologo', 'registro' => 'CRP 18/0001']);
        $this->assertSame(200, $res['status']);
        $st = db()->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$email]);
        $nu = $st->fetch();
        $this->assertNotFalse($nu, 'conta criada com o e-mail do convite');
        $this->assertSame(1, (int)$nu['is_professional']);
        $this->assertSame('CRP 18/0001', $nu['professional_reg']);
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM users WHERE email = 'outro-v48@example.com'")->fetchColumn());
        // antes de confirmar o e-mail o convite não aparece; depois aparece em Meus pacientes
        $this->assertSame([], care_pending_for(db(), $nu));
        db()->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$nu['id']]);
        $nu['email_verified_at'] = gmdate('Y-m-d H:i:s');
        $this->assertCount(1, care_pending_for(db(), $nu));
        // outra conta (logada) não consegue usar o link
        $intruder = $this->pro('cpro5i');
        $page = $intruder->get($path)['body'];
        $this->assertStringContainsString('Este convite é para outra conta', $page);
        $intruder->request('POST', '/convite-profissional.php', http_build_query(['t' => $t, 'csrf' => $intruder->csrf, 'sou_profissional' => '1']), ['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->assertSame('pendente', (string)db()->query('SELECT status FROM care_shares WHERE id = ' . (int)$r['json']['id'])->fetchColumn());
        $this->assertSame(404, $intruder->json('POST', '/api/care.php?action=accept', ['id' => $r['json']['id']])['status']);
        // a conta certa aceita (login por e-mail e senha) e o token deixa de valer
        $pro = $this->dev();
        $login = $pro->form('/', '/conta/entrar.php', ['email' => $email, 'senha' => 'cavalo correto bateria']);
        $this->assertSame(302, $login['status']);
        $this->assertStringContainsString('Aceitar convite', $pro->get($path)['body']);
        $acc = $pro->form($path, '/convite-profissional.php', ['t' => $t]);
        $this->assertSame(302, $acc['status']);
        $this->assertSame('ativo', (string)db()->query('SELECT status FROM care_shares WHERE id = ' . (int)$r['json']['id'])->fetchColumn());
        $this->assertStringContainsString('Convite inválido ou expirado', $pro->get($path)['body'], 'uso único');
        $this->assertSame(200, $pro->get('/paciente.php?c=' . (int)$r['json']['id'])['status']);
    }

    public function testPatientCancelsPendingAndRotatesLinkAndExpiredLink(): void
    {
        $p = $this->user('cpat6');
        $r = $p->json('POST', '/api/care.php?action=invite', ['email' => 'dra6-v48@example.com', 'scope' => ['humor']]);
        $id = (int)$r['json']['id'];
        $old = substr((string)$r['json']['link'], strpos((string)$r['json']['link'], 't=') + 2);
        $new = $p->json('POST', '/api/care.php?action=link', ['id' => $id])['json']['link'];
        $this->assertStringContainsString('Convite inválido ou expirado', $this->dev()->get('/convite-profissional.php?t=' . $old)['body'], 'link antigo deixa de valer');
        $newT = substr((string)$new, strpos((string)$new, 't=') + 2);
        $this->assertStringContainsString('Convite para acompanhar registros', $this->dev()->get('/convite-profissional.php?t=' . $newT)['body']);
        $this->assertSame('cancelado', $p->json('POST', '/api/care.php?action=revoke', ['id' => $id])['json']['status']);
        $this->assertStringContainsString('Convite inválido ou expirado', $this->dev()->get('/convite-profissional.php?t=' . $newT)['body']);
        // convite vencido
        $r = $p->json('POST', '/api/care.php?action=invite', ['email' => 'dra6b-v48@example.com', 'scope' => ['humor']]);
        $t = substr((string)$r['json']['link'], strpos((string)$r['json']['link'], 't=') + 2);
        db()->prepare('UPDATE care_shares SET invite_expires_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id = ?')->execute([$r['json']['id']]);
        $this->assertStringContainsString('Convite inválido ou expirado', $this->dev()->get('/convite-profissional.php?t=' . $t)['body']);
        $this->assertSame('expirado', $p->get('/api/care.php')['json']['shares'][0]['status']);
        // validações
        $this->assertSame(422, $p->json('POST', '/api/care.php?action=invite', ['email' => 'devcpat6@example.com', 'scope' => ['humor']])['status'], 'não convida a si mesmo');
        $this->assertSame(422, $p->json('POST', '/api/care.php?action=invite', ['email' => 'x', 'scope' => ['humor']])['status']);
        $this->assertSame(422, $p->json('POST', '/api/care.php?action=invite', ['email' => 'ok-v48@example.com', 'scope' => []])['status']);
        $this->assertSame(403, $this->user('cpat6n')->json('POST', '/api/care.php?action=code', [])['status'], 'só profissional gera código');
        $this->assertSame(CARE_SCOPE_DEFAULT, CARE_SCOPE['humor'][0] | CARE_SCOPE['sono'][0] | CARE_SCOPE['estresse_alimentacao'][0] | CARE_SCOPE['pensamentos'][0]);
        foreach (['substancias', 'sexualidade', 'pensamentos_morte'] as $k) $this->assertFalse(CARE_SCOPE[$k][2], "$k desligado por padrão");
    }

    public function testPrivacyTermsAndNoAdmin(): void
    {
        $b = $this->prod()->get('/privacidade.php')['body'];
        foreach (['id="pensamentos-morte"', 'id="nicotina"', 'id="profissionais"', 'revogação', 'Ninguém é notificado automaticamente'] as $s) $this->assertStringContainsString($s, $b);
        $t = $this->prod()->get('/termos.php')['body'];
        $this->assertStringContainsString('autodeclarados', $t);
        $this->assertStringContainsString('revogação do consentimento', $t);
        foreach (glob(TestEnv::ROOT . '/public_html/{admin,painel,adm}*', GLOB_BRACE) ?: [] as $f) $this->fail('área de administração: ' . $f);
    }
}
