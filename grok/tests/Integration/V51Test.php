<?php
require_once TestEnv::ROOT . '/app/lib/care.php';
require_once TestEnv::ROOT . '/app/lib/outbox.php';
/**
 * V5.1 (0.5.1-beta): testes de aceitação das correções de segurança.
 * P0-1 bloqueio antes de qualquer consulta, P0-2 troca de e-mail em duas etapas, P0-3 fila offline com dono e sem duplicar,
 * P1-1 sem cache de HTML autenticado, P1-2 fila de envios, P1-3 exportação/mídia, P1-4 pensamentos sensíveis, P2 diversos.
 */
final class V51Test extends IntegrationCase
{
    private function rows(string $sql, array $p): int { $st = db()->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); }
    private function lockApp(Http $c, string $u): int
    {
        $uid = $this->uid($u);
        db()->prepare('UPDATE users SET sex_pin_hash = ?, prefs = ? WHERE id = ?')
            ->execute([password_hash('2468', PASSWORD_DEFAULT), json_encode(['lock' => ['app' => true, 'minutes' => 15], 'modules' => ['sexualidade' => true, 'substancias' => true]]), $uid]);
        $c->json('POST', '/api/webauthn.php?action=lock', []);
        return $uid;
    }

    public function testReportShowsOnlyLockScreenWhenAppLocked(): void
    {
        $c = $this->user('v51rep');
        $this->entry($c, ['entry_date' => '2026-10-05', 'mood' => 2, 'note' => 'NOTA-V51']);
        $this->lockApp($c, 'v51rep');
        $r = $c->get('/relatorio.php?de=2026-10-01&ate=2026-10-10&sexualidade=1&substancias=1&notas=1');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('App bloqueado', $r['body']);
        $this->assertStringNotContainsString('Relatório de humor', $r['body']);
        $this->assertStringNotContainsString('NOTA-V51', $r['body']);
        $this->assertStringContainsString('no-store', (string)$c->header($r, 'Cache-Control'));
        // desbloqueado o app, mas sem PIN das seções privadas: a URL não libera sexualidade/substâncias
        $this->assertSame(200, $c->json('POST', '/api/day.php?action=unlock', ['pin' => '2468'])['status']);
        $c->json('POST', '/api/day.php?action=lock', []);
        db()->prepare('UPDATE users SET prefs = ? WHERE id = ?')->execute([json_encode(['modules' => ['sexualidade' => true, 'substancias' => true]]), $this->uid('v51rep')]);
        $r = $c->get('/relatorio.php?de=2026-10-01&ate=2026-10-10&sexualidade=1');
        $this->assertStringContainsString('Relatório de humor', $r['body']);
        $this->assertStringContainsString('seções privadas não entraram', $r['body']);
    }

    public function testPatientViewLockedAndRevoked404(): void
    {
        $pro = $this->user('v51pro');
        $pro->json('POST', '/api/care.php?action=profile', ['is_professional' => true, 'kind' => 'psiquiatra', 'reg' => 'CRM 1']);
        $pat = $this->user('v51pat');
        $this->entry($pat, ['entry_date' => '2026-10-05', 'mood' => 1]);
        $code = $pro->json('POST', '/api/care.php?action=code', [])['json']['code'];
        $id = (int)$pat->json('POST', '/api/care.php?action=invite', ['code' => $code, 'scope' => ['humor']])['json']['id'];
        $this->assertSame(200, $pro->json('POST', '/api/care.php?action=accept', ['id' => $id])['status']);
        $this->assertSame(200, $pro->get('/paciente.php?c=' . $id)['status']);
        // profissional com o app bloqueado: só a tela de bloqueio, nada do paciente
        $this->lockApp($pro, 'v51pro');
        $r = $pro->get('/paciente.php?c=' . $id);
        $this->assertStringContainsString('App bloqueado', $r['body']);
        $this->assertStringNotContainsString('v51pat', $r['body']);
        $this->assertSame(200, $pro->json('POST', '/api/day.php?action=unlock', ['pin' => '2468'])['status']);
        $this->assertSame(200, $pat->json('POST', '/api/care.php?action=revoke', ['id' => $id])['status']);
        $this->assertSame(404, $pro->get('/paciente.php?c=' . $id)['status'], 'revogado = 404 na hora');
    }

    public function testEmailChangeIsTwoStepSingleUseAndRevokesOtherSessions(): void
    {
        $c = $this->user('v51mail');
        $uid = $this->uid('v51mail');
        $this->setEmail($c, 'v51-antigo@example.com');
        $other = $this->user('v51mail'); // outro aparelho da mesma conta
        $this->assertSame(200, $other->get('/api/entries.php')['status']);
        TestEnv::clearMails();
        $r = $c->json('POST', '/api/account.php?action=settings', ['name' => 'T', 'email' => 'v51-novo@example.com', 'timezone' => 'America/Cuiaba']);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame('v51-novo@example.com', $r['json']['email_pendente']);
        $row = db()->query("SELECT email, pending_email FROM users WHERE id = $uid")->fetch();
        $this->assertSame('v51-antigo@example.com', $row['email'], 'só muda depois do link');
        $this->assertSame('v51-novo@example.com', $row['pending_email']);
        $this->assertSame('v51-antigo@example.com', care_verified_email(db()->query("SELECT * FROM users WHERE id = $uid")->fetch()));
        $tokRow = db()->query("SELECT token_hash FROM auth_tokens WHERE user_id = $uid AND purpose = 'email_change' AND used_at IS NULL")->fetchColumn();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$tokRow, 'só o hash fica no banco');
        $link = (string)$this->confirmLink('v51-novo@example.com');
        $t = substr($link, strlen('/conta/confirmar-email.php?t='));
        $this->assertSame(302, $c->form($link, '/conta/confirmar-email.php', ['t' => $t])['status']);
        $row = db()->query("SELECT * FROM users WHERE id = $uid")->fetch();
        $this->assertSame('v51-novo@example.com', $row['email']);
        $this->assertNull($row['pending_email']);
        $this->assertNotNull($row['email_verified_at']);
        $this->assertSame('v51-novo@example.com', care_verified_email($row));
        $this->assertSame(401, $other->get('/api/entries.php')['status'], 'outras sessões encerradas');
        $c->refreshCsrf();
        $this->assertSame(200, $c->get('/api/entries.php')['status'], 'quem confirmou continua dentro');
        // link de uso único
        $this->assertStringContainsString('Link inválido ou expirado', $c->get($link)['body']);
    }

    public function testCareVerifiedEmailNeedsProofEvenWithoutRequiredVerification(): void
    {
        $this->assertNull(care_verified_email(['email' => 'a@example.com', 'email_verified_email' => null, 'email_verified_at' => '2026-01-01 00:00:00']));
        $this->assertNull(care_verified_email(['email' => 'a@example.com', 'email_verified_email' => 'b@example.com']));
        $this->assertSame('a@example.com', care_verified_email(['email' => 'A@example.com', 'email_verified_email' => 'a@example.com']));
    }

    public function testOfflineQueueDedupeAndOwner(): void
    {
        $c = $this->user('v51q');
        $uid = $this->uid('v51q');
        $uuid = '3f1c2b9a-1111-4222-8333-444455556666';
        $p = ['entry_date' => '2026-10-07', 'period' => 'tarde', 'mood' => 1, 'client_uuid' => $uuid];
        $a = $c->json('POST', '/api/entries.php', $p);
        $b = $c->json('POST', '/api/entries.php', $p + ['note' => 'repetido']);
        $this->assertSame(200, $a['status'], $a['body']);
        $this->assertTrue($b['json']['duplicado']);
        $this->assertSame((int)$a['json']['id'], (int)$b['json']['id']);
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM entries WHERE user_id = $uid AND entry_date = '2026-10-07'")->fetchColumn());
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM entry_client_ids WHERE user_id = $uid")->fetchColumn());
        // item da fila de outra sessão/conta: recusado
        $r = $c->json('POST', '/api/entries.php', ['entry_date' => '2026-10-08', 'period' => 'tarde', 'mood' => 0, 'queue_owner' => str_repeat('a', 32)]);
        $this->assertSame(409, $r['status']);
        $this->assertTrue($r['json']['dono_diferente']);
        // dono certo (vem no boot JSON da página)
        preg_match('#<script id="boot" type="application/json">(.*?)</script>#s', $c->get('/')['body'], $m);
        $owner = json_decode($m[1], true)['qowner'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $owner);
        $this->assertSame(200, $c->json('POST', '/api/entries.php', ['entry_date' => '2026-10-08', 'period' => 'tarde', 'mood' => 0, 'queue_owner' => $owner])['status']);
        // front-end: fila com dono, estados e limpeza ao sair; seções íntimas não vão para a fila
        $js = (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/js/app.js');
        foreach (["queue_owner: Q_OWNER", "state: 'pending'", "'synced'", "'failed'", 'BPClearQueue', 'Por privacidade, estes dados não ficam guardados no aparelho'] as $s) $this->assertStringContainsString($s, $js);
        $q = (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/js/quick.js');
        $this->assertStringContainsString('queue_owner', $q);
        $this->assertStringContainsString('ainda não enviado', $q);
        $pwa = (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/js/pwa.js');
        $this->assertStringContainsString("localStorage.removeItem('bp-queue')", $pwa);
        $this->assertStringContainsString('ev.preventDefault()', $pwa, 'limpa antes de navegar');
    }

    public function testServiceWorkerAndNoStoreOnAuthenticatedHtml(): void
    {
        $sw = (string)file_get_contents(TestEnv::ROOT . '/public_html/sw.js');
        $this->assertStringContainsString("const VERSION = 'bp-v18';", $sw);
        $this->assertStringNotContainsString("c.put('/'", $sw);
        $this->assertStringNotContainsString("SHELL = ['/',", $sw);
        $this->assertStringContainsString("caches.match('/offline.html')", $sw);
        $off = (string)file_get_contents(TestEnv::ROOT . '/public_html/offline.html');
        $this->assertStringContainsString('tel:188', $off);
        $this->assertStringContainsString('tel:192', $off);
        $c = $this->user('v51sw');
        foreach (['/', '/relatorio.php', '/registro-rapido.php'] as $p) {
            $cc = (string)$c->header($c->get($p), 'Cache-Control');
            $this->assertStringContainsString('no-store', $cc, $p);
            $this->assertStringContainsString('private', $cc, $p);
        }
        $ht = (string)file_get_contents(TestEnv::ROOT . '/public_html/.htaccess');
        $this->assertStringNotContainsString('X-Forwarded-Proto', $ht);
        $this->assertMatchesRegularExpression('#<IfModule mod_headers\.c>\s*<Files "sw\.js">#', $ht);
        foreach (preg_split('/\R/', $ht) ?: [] as $i => $line) if (preg_match('/^\s*Header\s/', $line)) $this->assertMatchesRegularExpression('/^\s{4}/', $line, 'Header dentro do IfModule (linha ' . ($i + 1) . ')');
    }

    public function testOutboxLeaseConfirmBackoff(): void
    {
        $this->assertSame(300, outbox_backoff(1, 0));
        $this->assertSame(600, outbox_backoff(2, 0));
        $this->assertSame(21600, outbox_backoff(10, 0));
        $this->assertSame(21660, outbox_backoff(10, 60));
        $j = outbox_backoff(3);
        $this->assertGreaterThanOrEqual(1200, $j);
        $this->assertLessThanOrEqual(1260, $j);
        $this->user('v51ob');
        $uid = $this->uid('v51ob');
        $pdo = db();
        $key = outbox_dedupe('teste', (string)$uid, 'x');
        $this->assertTrue(outbox_enqueue($pdo, 'test', $uid, 1, $key, gmdate('Y-m-d H:i:s')));
        $this->assertFalse(outbox_enqueue($pdo, 'test', $uid, 1, $key, gmdate('Y-m-d H:i:s')), 'chave repetida não duplica');
        $l1 = outbox_lease($pdo, 10, ['test']);
        $this->assertCount(1, $l1['rows']);
        $this->assertCount(0, outbox_lease($pdo, 10, ['test'])['rows'], 'reservado não é pego por outro');
        $this->assertFalse(outbox_complete($pdo, $l1['rows'][0], str_repeat('0', 32)), 'token errado não confirma');
        $this->assertSame('retry', outbox_fail($pdo, $l1['rows'][0], $l1['token'], 'temporary', 'smtp_421', null, 0));
        $row = $pdo->query("SELECT * FROM outbox WHERE dedupe_key = '$key'")->fetch();
        $this->assertSame('retry', $row['state']);
        $this->assertSame('smtp_421', $row['last_error_code']);
        $this->assertSame(1, (int)$row['attempts']);
        $this->assertNull($row['lease_token']);
        // 5 tentativas = dead
        $pdo->exec("UPDATE outbox SET attempts = 4, available_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE dedupe_key = '$key'");
        $l = outbox_lease($pdo, 10, ['test']);
        $this->assertSame('dead', outbox_fail($pdo, $l['rows'][0], $l['token'], 'temporary', 'smtp_421', null, 0));
        $cron = (string)file_get_contents(TestEnv::ROOT . '/cron/send_reminders.php');
        $this->assertStringContainsString("'inicio'", $cron);
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM outbox WHERE kind = 'test' AND state = 'leased'")->fetchColumn());
    }

    public function testExportNeedsPostTokenAndMediaMagicBytes(): void
    {
        $c = $this->user('v51exp');
        $this->assertSame(405, $c->get('/api/account.php?action=export&format=json')['status']);
        $r = $c->request('POST', '/api/account.php?action=export', http_build_query(['token' => 'x', 'format' => 'json']), ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF-Token' => (string)$c->csrf]);
        $this->assertSame(403, $r['status']);
        $r = $c->request('POST', '/api/account.php?action=export_prepare', '{}', ['Content-Type' => 'application/json']);
        $this->assertSame(403, $r['status'], 'sem CSRF');
        $p = $c->json('POST', '/api/account.php?action=export_prepare', []);
        $this->assertSame(200, $p['status']);
        $this->assertStringContainsString('dados íntimos', $p['json']['aviso']);
        $ok = $c->request('POST', '/api/account.php?action=export', http_build_query(['token' => $p['json']['token'], 'format' => 'json']), ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF-Token' => (string)$c->csrf]);
        $this->assertSame(200, $ok['status']);
        $reuse = $c->request('POST', '/api/account.php?action=export', http_build_query(['token' => $p['json']['token'], 'format' => 'json']), ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF-Token' => (string)$c->csrf]);
        $this->assertSame(403, $reuse['status'], 'token de uso único');
        $this->assertStringNotContainsString('action=export&amp;format', $c->get('/')['body'], 'sem links GET de exportação');
        // mídia: conteúdo desconhecido = 415, mesmo dizendo ser imagem
        $e = $this->entry($c, ['entry_date' => '2026-10-03']);
        $f = tempnam(sys_get_temp_dir(), 'bin');
        file_put_contents($f, random_bytes(2048));
        $up = $c->upload('/api/media.php', $f, 'x.png', 'image/png', ['entry_id' => (string)$e['id']]);
        $this->assertSame(415, $up['status'], $up['body']);
        $up = $c->upload('/api/media.php', $f, 'x.bin', 'application/octet-stream', ['entry_id' => (string)$e['id']]);
        $this->assertSame(415, $up['status']);
        $ok = $c->upload('/api/media.php', $this->png(), 'foto.png', 'image/png', ['entry_id' => (string)$e['id']]);
        $this->assertSame(200, $ok['status'], $ok['body']);
    }

    public function testThoughtsAreSensitiveAndOptIn(): void
    {
        $this->assertSame(0, CARE_SCOPE_DEFAULT & 8);
        $this->assertSame(8, CARE_SENSITIVE & 8);
        $this->assertFalse(CARE_SCOPE['pensamentos'][2]);
        $this->assertTrue(CARE_SCOPE['pensamentos'][3]);
        $js = (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/js/care.js');
        $this->assertStringContainsString('confirmScope', $js);
    }

    public function testReligiosityPerPeriodValidatedExportedAndOptInScope(): void
    {
        $c = $this->user('v51rel');
        $this->assertSame(200, $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['religiosidade' => true, 'estresse' => true]])['status']);
        $this->assertSame(422, $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-05', 'period' => 'tarde', 'religiosity' => 7])['status']);
        $this->assertSame(422, $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-05', 'period' => 'tarde', 'religiosity' => 'muito'])['status']);
        $this->assertSame(200, $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-05', 'period' => 'tarde', 'religiosity' => 3])['status']);
        $this->assertSame(200, $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-05', 'period' => 'manha', 'religiosity' => 1])['status']);
        $this->assertSame(200, $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-06', 'period' => 'noite', 'religiosity' => null])['status'], 'opcional');
        $pm = $c->get('/api/day.php?from=2026-10-05&to=2026-10-05')['json']['period_markers'];
        $by = array_column($pm, 'religiosity', 'period');
        $this->assertSame(3, (int)$by['tarde']); $this->assertSame(1, (int)$by['manha']);
        $this->assertSame(3, $this->rows("SELECT religiosity FROM daily_markers WHERE entry_date = '2026-10-05' AND religiosity IS NOT NULL ORDER BY id DESC LIMIT 1", []), 'maior nível do dia vai para relatórios');
        // relatório mostra junto do humor
        $rep = $c->get('/relatorio.php?de=2026-10-01&ate=2026-10-07')['body'];
        $this->assertStringContainsString('Religiosidade — dias sensível ao tema / muito sensível', $rep);
        // exportação JSON inclui
        $ex = $c->export('json');
        $this->assertStringContainsString('"religiosity"', $ex['body']);
        // compartilhamento: bit próprio, sensível, fora do padrão; o filtro só libera com o bit
        $this->assertSame(128, CARE_SCOPE['religiosidade'][0]);
        $this->assertFalse(CARE_SCOPE['religiosidade'][2]);
        $this->assertSame(128, CARE_SENSITIVE & 128);
        $this->assertSame(0, CARE_SCOPE_DEFAULT & 128);
        [, $mk] = care_scope_rows(CARE_SCOPE_DEFAULT, [], [['entry_date' => '2026-10-05', 'user_id' => 1, 'religiosity' => 3]]);
        $this->assertNull($mk[0]['religiosity']);
        [, $mk] = care_scope_rows(CARE_SCOPE_DEFAULT | 128, [], [['entry_date' => '2026-10-05', 'user_id' => 1, 'religiosity' => 3]]);
        $this->assertSame(3, $mk[0]['religiosity']);
        $mig = (string)file_get_contents(TestEnv::ROOT . '/migrations/2026-11-17-v5-1-seguranca.sql');
        $this->assertStringContainsString('ALTER TABLE period_markers ADD COLUMN religiosity TINYINT NULL', $mig);
        $this->assertStringContainsString("'Sensível ao tema'", (string)file_get_contents(TestEnv::ROOT . '/app/lib/markers.php'));
    }

    public function testUxRegistrationSessionSaveButtonsThemeAndReminderGrid(): void
    {
        $js = (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/js/app.js');
        $this->assertStringContainsString("'Registrando: ' + regLabel()", $js);
        $this->assertStringContainsString("cancel: 'Continuar em ' + B.periods[s.p]", $js, 'pergunta antes de trocar de período');
        $this->assertStringContainsString('Salvar e continuar', $js);
        $html = $this->user('v51ux')->get('/')['body'];
        foreach (['id="reg-indicator"', 'id="btn-save-all"', 'id="save-all-summary"', 'id="btn-save-entry-next"', 'data-mod="religiosidade"', 'Adicionar horário'] as $n) $this->assertStringContainsString($n, $html);
        $pwa = (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/js/pwa.js');
        $this->assertStringContainsString("prefers-color-scheme: dark", $pwa);
        $this->assertStringContainsString("addEventListener('change', onSys)", $pwa);
        $this->assertStringContainsString('[data-theme=light]', (string)file_get_contents(TestEnv::ROOT . '/public_html/brand.css.php'));
        // grade de lembretes: PATCH liga/desliga dias, exige ao menos um, só do dono
        $c = $this->user('v51rm');
        $this->assertSame(200, $c->json('POST', '/api/reminders.php', ['time_local' => '08:00', 'days' => [1, 2], 'channel' => 'email'])['status']);
        $id = (int)$c->get('/api/reminders.php')['json']['reminders'][0]['id'];
        $this->assertSame(200, $c->json('PATCH', '/api/reminders.php', ['id' => $id, 'days' => [0, 6]])['status']);
        $this->assertSame('0,6', $c->get('/api/reminders.php')['json']['reminders'][0]['days']);
        $this->assertSame(422, $c->json('PATCH', '/api/reminders.php', ['id' => $id, 'days' => []])['status']);
        $this->assertSame(404, $this->user('v51rm2')->json('PATCH', '/api/reminders.php', ['id' => $id, 'days' => [1]])['status']);
        $this->assertStringContainsString("aria-pressed", $js);
    }

    public function testEntriesDefaultsAndRange(): void
    {
        $c = $this->user('v51rng');
        $this->assertSame(422, $c->get('/api/entries.php?from=2026-10-10&to=2026-10-01')['status']);
        $this->assertSame(200, $c->get('/api/entries.php')['status']);
    }

    public function testMigrationsAndDocsRules(): void
    {
        foreach (glob(TestEnv::ROOT . '/migrations/*v5-1*.sql') ?: [] as $f) {
            $sql = (string)file_get_contents($f);
            $this->assertStringNotContainsStringIgnoringCase('information_schema', $sql);
            $this->assertStringNotContainsStringIgnoringCase('SKIP LOCKED', $sql);
            foreach (preg_split('/\R/', $sql) ?: [] as $line) if (str_starts_with(ltrim($line), '--')) $this->assertStringNotContainsString(';', $line, basename($f));
        }
        $this->assertSame('0.5.3-beta', trim((string)file_get_contents(TestEnv::ROOT . '/VERSION')));
        $contrib = (string)file_get_contents(TestEnv::ROOT . '/CONTRIBUTING.md');
        foreach (['DML', 'Pré-condições', 'repetir', 'Conferência'] as $s) $this->assertStringContainsString($s, $contrib);
        $run = (string)file_get_contents(TestEnv::ROOT . '/tests/run-all.sh');
        $this->assertStringContainsString('SKIP_BROWSER', $run);
        $this->assertStringContainsString('preflight', $run);
    }
}
