<?php
/** V4.1: biometria com confirmação por PIN/senha, destinatário duplicado, álcool sincronizado, batimento dos crons, exportação no fuso. */
final class V41Test extends IntegrationCase
{
    public function testFirstPasskeyNeedsPinOrPasswordReauth(): void
    {
        $c = $this->user('v41wa');
        $uid = $this->uid('v41wa');
        db()->prepare('UPDATE users SET sex_pin_hash = ? WHERE id = ?')->execute([password_hash('4321', PASSWORD_DEFAULT), $uid]);
        $c->json('POST', '/api/webauthn.php?action=lock', []);
        $r = $c->json('POST', '/api/webauthn.php?action=reg_options', []);
        $this->assertSame(423, $r['status'], $r['body']);
        $this->assertTrue($r['json']['reauth']);
        $this->assertStringContainsString('cadastrar a biometria', $r['json']['erro'], 'primeiro aparelho: sem “outro”');
        $this->assertStringNotContainsString('outro', $r['json']['erro']);
        $r = $c->json('POST', '/api/webauthn.php?action=reg_options', ['confirmacao' => '0000']);
        $this->assertSame(422, $r['status']);
        $r = $c->json('POST', '/api/webauthn.php?action=reg_options', ['confirmacao' => '4321']);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertArrayHasKey('challenge', $r['json']['publicKey']);
    }

    public function testDuplicateRecipientIsRejected(): void
    {
        $c = $this->user('v41rc');
        $a = $c->json('POST', '/api/recipients.php', ['name' => 'Dra Ana', 'email' => 'ana@example.com', 'role' => 'psiquiatra', 'frequency' => 'semanal']);
        $this->assertContains($a['status'], [200, 201], $a['body']);
        $b = $c->json('POST', '/api/recipients.php', ['name' => 'Ana de novo', 'email' => 'ANA@Example.com', 'role' => 'familia', 'frequency' => 'semanal']);
        $this->assertSame(409, $b['status'], $b['body']);
        $this->assertStringContainsString('já está na sua lista', $b['json']['erro']);
        $this->assertCount(1, $c->get('/api/recipients.php')['json']['recipients']);
        // outro usuário pode cadastrar o mesmo e-mail
        $o = $this->user('v41rc_o');
        $this->assertContains($o->json('POST', '/api/recipients.php', ['name' => 'Ana', 'email' => 'ana@example.com', 'role' => 'psiquiatra', 'frequency' => 'semanal'])['status'], [200, 201]);
    }

    public function testAlcoholWithoutAmountCountsOneDose(): void
    {
        $c = $this->user('v41al');
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['substancias' => true]]);
        $d = (new DateTime('now', new DateTimeZone('America/Cuiaba')))->format('Y-m-d');
        $r = $c->json('POST', '/api/day.php?action=subst_add', ['entry_date' => $d, 'substance' => 'alcool', 'period' => 'noite']);
        $this->assertSame(200, $r['status'], $r['body']);
        $m = $c->get('/api/day.php?from=' . $d . '&to=' . $d)['json']['markers'][0];
        $this->assertSame(1, (int)$m['alcohol_units']);
    }

    public function testCronHeartbeatShownInAccountAndExportFilename(): void
    {
        TestEnv::runCron('analyze_signals.php');
        $c = $this->user('v41hb');
        // V4.3: a situação dos crons NÃO aparece mais em Conta (só no servidor: heartbeat + storage/logs/cron.log)
        $h = $c->get('/')['body'];
        $this->assertStringNotContainsString('analyze_signals.php', $h);
        $this->assertStringContainsString(' analise usuarios=', (string)file_get_contents(TestEnv::$tmp . '/storage/logs/cron.log'));
        $hb = json_decode((string)file_get_contents(TestEnv::$tmp . '/storage/heartbeat-analise.json'), true);
        $this->assertSame(['at', 'usuarios', 'alertas', 'personalizados', 'pendentes_entregues'], array_keys($hb), 'só data e contadores');
        $e = $c->get('/api/account.php?action=export&format=json');
        $today = (new DateTime('now', new DateTimeZone('America/Cuiaba')))->format('Y-m-d');
        $this->assertStringContainsString('bipolar-dados-' . $today . '-', $e['headers']['content-disposition'][0]);
    }
}
