<?php
/** V4.3.1: concorrência otimista (409 de versão), mensagens de erro da IA, status real da IA e legal.controller. */
final class V431Test extends IntegrationCase
{
    private const MSG = 'Este registro foi alterado em outra aba ou aparelho. Recarregue para ver a versão mais recente.';

    private function assertConflict(array $r): void
    {
        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertTrue($r['json']['versao_conflito']);
        $this->assertSame(self::MSG, $r['json']['erro']);
        $this->assertSame(self::MSG, $r['json']['mensagem']);
    }

    public function testTwoTabsEditingSameEntry(): void
    {
        $c = $this->user('v431ent');
        $e = $this->entry($c, ['entry_date' => '2026-10-11', 'period' => 'tarde', 'mood' => 0, 'note' => 'original']);
        $id = (int)$e['id'];
        $v = (int)$e['entry']['version'];
        $this->assertSame(1, $v);
        $list = $c->get('/api/entries.php?from=2026-10-11&to=2026-10-11')['json']['entries'];
        $this->assertSame(1, (int)$list[0]['version'], 'GET devolve a versão');
        // aba A salva com a versão que viu
        $a = $c->json('POST', '/api/entries.php', ['id' => $id, 'version' => $v, 'entry_date' => '2026-10-11', 'period' => 'tarde', 'mood' => 1, 'note' => 'aba A']);
        $this->assertSame(200, $a['status'], $a['body']);
        $this->assertSame(2, (int)$a['json']['entry']['version']);
        // aba B ainda tem a versão 1: 409, sem sobrescrever
        $this->assertConflict($c->json('POST', '/api/entries.php', ['id' => $id, 'version' => $v, 'entry_date' => '2026-10-11', 'period' => 'tarde', 'mood' => -1, 'note' => 'aba B']));
        $this->assertSame('aba A', db()->query("SELECT note FROM entries WHERE id = $id")->fetchColumn());
        // sem id (mesmo dia/período) também confere
        $this->assertConflict($c->json('POST', '/api/entries.php', ['version' => 1, 'entry_date' => '2026-10-11', 'period' => 'tarde', 'mood' => -1]));
        // version 0 = "achava que estava livre": período ocupado → 409
        $this->assertConflict($c->json('POST', '/api/entries.php', ['version' => 0, 'entry_date' => '2026-10-11', 'period' => 'tarde', 'mood' => -1]));
        // após recarregar (versão 2), salva
        $this->assertSame(200, $c->json('POST', '/api/entries.php', ['id' => $id, 'version' => 2, 'entry_date' => '2026-10-11', 'period' => 'tarde', 'mood' => 2])['status']);
        // fila offline / cliente antigo: sem version → comportamento anterior (grava e incrementa)
        $q = $c->json('POST', '/api/entries.php', ['entry_date' => '2026-10-11', 'period' => 'tarde', 'mood' => -2, 'client_uuid' => '11111111-1111-4111-8111-111111111111']);
        $this->assertSame(200, $q['status'], $q['body']);
        $this->assertSame(4, (int)$q['json']['entry']['version']);
        // corrida real: duas gravações simultâneas com a mesma versão → exatamente uma passa
        $codes = $this->parallel($c, [
            ['id' => $id, 'version' => 4, 'entry_date' => '2026-10-11', 'period' => 'tarde', 'mood' => 1, 'note' => 'x'],
            ['id' => $id, 'version' => 4, 'entry_date' => '2026-10-11', 'period' => 'tarde', 'mood' => -1, 'note' => 'y'],
        ]);
        sort($codes);
        $this->assertSame([200, 409], $codes);
    }

    public function testMoveIntoOccupiedPeriodStillReturnsOld409(): void
    {
        $c = $this->user('v431mov');
        $this->entry($c, ['entry_date' => '2026-10-12', 'period' => 'manha', 'mood' => 0]);
        $n = $this->entry($c, ['entry_date' => '2026-10-12', 'period' => 'noite', 'mood' => 1]);
        $r = $c->json('POST', '/api/entries.php', ['id' => $n['id'], 'version' => $n['entry']['version'], 'entry_date' => '2026-10-12', 'period' => 'manha', 'mood' => 1]);
        $this->assertSame(409, $r['status']);
        $this->assertTrue($r['json']['conflito']);
        $this->assertArrayNotHasKey('versao_conflito', $r['json']);
        // a versão não foi consumida: mover para um período livre com a mesma versão funciona
        $ok = $c->json('POST', '/api/entries.php', ['id' => $n['id'], 'version' => $n['entry']['version'], 'entry_date' => '2026-10-12', 'period' => 'tarde', 'mood' => 1]);
        $this->assertSame(200, $ok['status'], $ok['body']);
        $this->assertSame(2, (int)$ok['json']['entry']['version']);
    }

    public function testRulesRecipientsSexualityVersioned(): void
    {
        $c = $this->user('v431oth');
        $rule = ['name' => 'Sono', 'conditions' => [['metric' => 'sleep', 'op' => '<', 'value' => 5, 'mode' => 'day']]];
        $s = $c->json('POST', '/api/custom_alerts.php?action=save', $rule)['json']['rule'];
        $this->assertSame(1, $s['version']);
        $this->assertSame(1, $c->get('/api/custom_alerts.php')['json']['rules'][0]['version']);
        $this->assertSame(200, $c->json('POST', '/api/custom_alerts.php?action=save', ['id' => $s['id'], 'version' => 1, 'name' => 'Sono A'] + $rule)['status']);
        $this->assertConflict($c->json('POST', '/api/custom_alerts.php?action=save', ['id' => $s['id'], 'version' => 1, 'name' => 'Sono B'] + $rule));
        $this->assertSame('Sono A', db()->query('SELECT name FROM alert_rules WHERE id = ' . (int)$s['id'])->fetchColumn());
        $this->assertSame(200, $c->json('POST', '/api/custom_alerts.php?action=save', ['id' => $s['id'], 'name' => 'Sem versão'] + $rule)['status'], 'cliente antigo');

        $rc = $c->json('POST', '/api/recipients.php', ['name' => 'Dra', 'email' => 'dra431@example.com', 'role' => 'psiquiatra', 'frequency' => 'semanal', 'weekday' => 1])['json'];
        $rid = (int)$rc['id'];
        $this->assertSame(1, (int)$c->get('/api/recipients.php')['json']['recipients'][0]['version']);
        $this->assertSame(200, $c->json('PATCH', "/api/recipients.php?id=$rid", ['include_notes' => true, 'version' => 1])['status']);
        $this->assertConflict($c->json('PATCH', "/api/recipients.php?id=$rid", ['include_notes' => false, 'version' => 1]));
        $this->assertSame('1', (string)db()->query("SELECT include_notes FROM recipients WHERE id = $rid")->fetchColumn());
        $this->assertSame(404, $c->json('PATCH', '/api/recipients.php?id=999999', ['include_notes' => true, 'version' => 1])['status']);

        $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['sexualidade' => true]]);
        $a = $c->json('POST', '/api/day.php?action=sex', ['entry_date' => '2026-10-11', 'libido' => 1, 'version' => 0]);
        $this->assertSame(200, $a['status'], $a['body']);
        $this->assertSame(1, $a['json']['version']);
        $this->assertSame(1, (int)$c->get('/api/day.php?from=2026-10-11&to=2026-10-11')['json']['sexuality'][0]['version']);
        $this->assertConflict($c->json('POST', '/api/day.php?action=sex', ['entry_date' => '2026-10-11', 'libido' => 2, 'version' => 0]));
        $this->assertSame(2, $c->json('POST', '/api/day.php?action=sex', ['entry_date' => '2026-10-11', 'libido' => 2, 'version' => 1])['json']['version']);
        $this->assertConflict($c->json('POST', '/api/day.php?action=sex', ['entry_date' => '2026-10-11', 'libido' => -1, 'version' => 1]));
    }

    public function testUiHandlesVersionConflict(): void
    {
        $js = (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/js/app.js');
        $this->assertStringContainsString('data.erro || data.mensagem', $js);
        $this->assertStringContainsString('versao_conflito', $js);
        $this->assertStringContainsString('Recarregar agora', $js);
        $this->assertStringContainsString('location.reload()', $js);
        $this->assertMatchesRegularExpression('/delete qp\.version/', $js, 'fila offline sem versão');
        foreach (['passkey.js', 'quick.js'] as $f) $this->assertStringContainsString('d.mensagem', (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/js/' . $f));
        $sw = (string)file_get_contents(TestEnv::ROOT . '/public_html/sw.js');
        $this->assertStringContainsString("'bp-v9'", $sw);
        $this->assertStringContainsString('app.js?v=9', $sw);
        $this->assertStringContainsString('app.js?v=9', $this->user('v431ui')->get('/')['body']);
    }

    public function testKeyTestReturnsErroAndMensagem(): void
    {
        $c = $this->user('v431key');
        $base = 'http://127.0.0.1:' . TestEnv::$xaiPort . '/v1';
        $bad = $c->json('POST', '/api/ai.php?action=key_test', ['provider' => 'custom', 'base_url' => $base, 'model' => 'm', 'api_key' => 'sk-chave-invalida-0000']);
        $this->assertSame(422, $bad['status']);
        $this->assertFalse($bad['json']['ok']);
        $this->assertStringContainsString('Chave inválida', $bad['json']['erro']);
        $this->assertSame($bad['json']['erro'], $bad['json']['mensagem']);
        $ok = $c->json('POST', '/api/ai.php?action=key_test', ['provider' => 'custom', 'base_url' => $base, 'model' => 'm', 'api_key' => 'sk-minha-chave-boa-9876']);
        $this->assertSame(200, $ok['status']);
        $this->assertArrayNotHasKey('erro', $ok['json']);
    }

    /** Nenhuma API devolve erro (4xx/5xx) só com "mensagem", sem "erro". */
    public function testNoApiErrorWithoutErro(): void
    {
        foreach (glob(TestEnv::ROOT . '/public_html/api/*.php') ?: [] as $f) {
            foreach (file($f) ?: [] as $n => $line) {
                if (!preg_match_all('/json_out\((\[.*?\]),\s*([^)]*)\)/', $line, $mm, PREG_SET_ORDER)) continue;
                foreach ($mm as [, $arr, $code]) {
                    if (preg_match('/\b[45]\d\d\b/', $code) && strpos($arr, "'mensagem'") !== false && strpos($line, "'erro'") === false)
                        $this->fail(basename($f) . ':' . ($n + 1) . ' devolve erro só com mensagem');
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testAiConfiguredIsAccurate(): void
    {
        // dev: chave do operador (bloco xai antigo) → funciona mesmo sem chave própria
        $d = $this->user('v431aid')->get('/api/ai.php')['json'];
        $this->assertTrue($d['operator_fallback']);
        $this->assertTrue($d['configured']);
        $this->assertTrue($d['using_operator_key']);
        // configured vem da credencial real, não da simples disponibilidade do BYOK (caso sem fallback: Unit/V431UnitTest)
        $src = (string)file_get_contents(TestEnv::ROOT . '/public_html/api/ai.php');
        $this->assertStringContainsString('\'configured\' => $cred !== null', $src);
    }

    public function testPrivacyShowsLegalController(): void
    {
        $body = $this->prod()->get('/privacidade.php')['body'];
        $this->assertStringContainsString('Ronaldo Nadaf, Cuiabá/MT', $body);
        $this->assertStringNotContainsString('a pessoa responsável pelo site bipolar.app.br', $body);
        $this->assertStringContainsString('a pessoa responsável pelo site bipolar.app.br', $this->dev()->get('/privacidade.php')['body'], 'sem legal.controller: texto genérico');
    }

    /** @param list<array<string,mixed>> $bodies @return list<int> */
    private function parallel(Http $c, array $bodies): array
    {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($bodies as $b) {
            $h = $c->curlHandle('POST', '/api/entries.php', $b);
            curl_multi_add_handle($mh, $h);
            $hs[] = $h;
        }
        do { curl_multi_exec($mh, $run); curl_multi_select($mh); } while ($run > 0);
        $codes = [];
        foreach ($hs as $h) { $codes[] = (int)curl_getinfo($h, CURLINFO_HTTP_CODE); curl_multi_remove_handle($mh, $h); }
        curl_multi_close($mh);
        return $codes;
    }
}
