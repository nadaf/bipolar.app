<?php
/** V4.3: sem área de administração, regras duplicadas, prévia com intervalo, exportação completa/exclusão, páginas legais e IA com chave própria (BYOK). */
final class V43Test extends IntegrationCase
{
    private function today(int $offset = 0): string
    {
        return (new DateTime('now', new DateTimeZone('America/Cuiaba')))->modify("$offset day")->format('Y-m-d');
    }

    /** Nenhuma rota, papel ou tela de administração em lugar nenhum do código servido. */
    public function testNoAdminAnywhere(): void
    {
        $roots = [TestEnv::ROOT . '/public_html', TestEnv::ROOT . '/app', TestEnv::ROOT . '/cron'];
        foreach ($roots as $r) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r, FilesystemIterator::SKIP_DOTS)) as $f) {
                $path = (string)$f;
                $this->assertDoesNotMatchRegularExpression('/admin/i', basename($path), "arquivo com 'admin' no nome: $path");
                if (!preg_match('/\.(php|js|html|htaccess|webmanifest)$/', $path) && basename($path) !== '.htaccess') continue;
                $src = (string)file_get_contents($path);
                // a lista de senhas fracas contém "admin123", que não é rota nem papel
                $src = str_replace("'admin123'", '', $src);
                // V4.5: a lista de nomes PROIBIDOS como subdomínio contém "admin" justamente para bloqueá-lo (não é rota nem papel)
                $src = (string)preg_replace('/const SUBDOMAIN_RESERVED = \[.*?\];/s', '', $src);
                $this->assertDoesNotMatchRegularExpression('/is_admin|admin_|role\s*=>?\s*[\'"]admin|[\'"]admin[\'"]|\/admin\b/i', $src, "referência a admin em $path");
            }
        }
        $schema = (string)file_get_contents(TestEnv::ROOT . '/schema.sql');
        $this->assertDoesNotMatchRegularExpression('/admin|is_staff|superuser/i', $schema);
        $cols = array_column(db()->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
        foreach ($cols as $c) $this->assertDoesNotMatchRegularExpression('/admin|role/i', $c);
        $c = $this->user('v43noadm');
        foreach (['/admin', '/admin.php', '/admin/', '/painel.php', '/loadtest-admin.php'] as $p) {
            $r = $c->get($p);
            $this->assertNotSame(200, $r['status'] === 200 && strpos($r['body'], 'Teste de carga') !== false ? 200 : 0, $p);
        }
        $this->assertSame(400, $c->get('/api/account.php?action=cron')['status']);
        $this->assertStringNotContainsString('cron-status', $c->get('/')['body']);
        $this->assertStringNotContainsString('loadtestAdmin', (string)file_get_contents(TestEnv::ROOT . '/app/config.example.php'));
        $this->assertStringNotContainsString('loadtest_admin_secret', (string)file_get_contents(TestEnv::ROOT . '/app/config.example.php'));
    }

    public function testCronStatusOnlyFromCommandLine(): void
    {
        $src = (string)file_get_contents(TestEnv::ROOT . '/tools/cron_status.php');
        $this->assertStringContainsString("PHP_SAPI !== 'cli'", $src);
        $this->assertFalse(function_exists('cron_status_all') && in_array('cron_status_all', get_defined_functions()['user'], true) && strpos((string)file_get_contents(TestEnv::ROOT . '/app/lib/helpers.php'), 'function cron_status_all') !== false);
        foreach (['public_html/index.php', 'public_html/api/account.php', 'public_html/assets/js/app.js'] as $f)
            $this->assertStringNotContainsString('cron_status', (string)file_get_contents(TestEnv::ROOT . '/' . $f), $f);
    }

    public function testDuplicateRulesBlocked(): void
    {
        $c = $this->user('v43dup');
        $rule = ['name' => 'Sono curto', 'logic' => 'and', 'conditions' => [['metric' => 'sleep', 'op' => '<', 'value' => 5, 'mode' => 'day']], 'cooldown_days' => 3];
        $a = $c->json('POST', '/api/custom_alerts.php?action=save', $rule);
        $this->assertSame(200, $a['status'], $a['body']);
        $id = (int)$a['json']['rule']['id'];
        // mesmo conteúdo, outro nome e outra gravidade: ainda é duplicada
        $b = $c->json('POST', '/api/custom_alerts.php?action=save', ['name' => 'Outro nome', 'severity' => 2, 'cooldown_days' => 5] + $rule);
        $this->assertSame(409, $b['status']);
        $this->assertStringContainsString('“Sono curto”', $b['json']['erro']);
        $this->assertSame($id, (int)$b['json']['duplicado']['id']);
        // a prévia avisa antes de salvar
        $p = $c->json('POST', '/api/custom_alerts.php?action=preview', $rule);
        $this->assertSame('Sono curto', $p['json']['duplicate_of']['name']);
        // editar a própria regra não é duplicata
        $this->assertSame(200, $c->json('POST', '/api/custom_alerts.php?action=save', ['id' => $id, 'name' => 'Sono curto (editado)'] + $rule)['status']);
        // condição diferente: permitido
        $d = $rule; $d['conditions'][0]['value'] = 4;
        $this->assertSame(200, $c->json('POST', '/api/custom_alerts.php?action=save', $d)['status']);
        // outra pessoa pode ter a mesma regra
        $this->assertSame(200, $this->user('v43dup2')->json('POST', '/api/custom_alerts.php?action=save', $rule)['status']);
        $this->assertSame(2, (int)db()->query('SELECT COUNT(*) FROM alert_rules WHERE user_id = ' . $this->uid('v43dup'))->fetchColumn());
    }

    public function testPreviewExplainsCooldown(): void
    {
        $c = $this->user('v43prev');
        for ($i = 4; $i >= 0; $i--) $this->entry($c, ['entry_date' => $this->today(-$i), 'period' => 'manha', 'mood' => -2]);
        $rule = ['name' => 'Baixo', 'conditions' => [['metric' => 'mood_min', 'op' => '<=', 'value' => -2, 'mode' => 'day']], 'cooldown_days' => 3];
        $p = $c->json('POST', '/api/custom_alerts.php?action=preview', $rule + ['dias' => 14])['json'];
        $this->assertCount(5, $p['matched']);
        $this->assertSame([$this->today(-4), $this->today(-1)], $p['fired']);
        $this->assertSame(3, $p['cooldown_days']);
        $this->assertCount(3, $p['skipped']);
        $this->assertSame(['day' => $this->today(-3), 'after' => $this->today(-4), 'until' => $this->today(-2)], $p['skipped'][0]);
        $js = (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/js/app.js');
        $this->assertStringContainsString('intervalo mínimo entre avisos', $js);
        $this->assertStringContainsString('Dias atendidos × dias que avisariam', $js);
    }

    public function testSavingPastDayKeepsSelectedDate(): void
    {
        $js = (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/js/app.js');
        $this->assertStringContainsString('await resetForm(true, p.entry_date, p.period);', $js);
        $this->assertStringContainsString('function resetForm(preferFree = false, keepDate = null, lastPeriod = null)', $js);
    }

    public function testExportZipHasEverythingAndDeleteRemovesEverything(): void
    {
        $c = $this->user('v43exp');
        $uid = $this->uid('v43exp');
        $e = $this->entry($c, ['entry_date' => $this->today(-1), 'period' => 'noite', 'mood' => 1, 'note' => 'nota-exportada']);
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['sexualidade' => true, 'substancias' => true]]);
        $c->json('POST', '/api/day.php?action=subst_add', ['entry_date' => $this->today(), 'substance' => 'alcool', 'period' => 'noite']);
        $mid = (int)$c->upload('/api/media.php', $this->png(), 'foto.png', 'image/png', ['entry_id' => (string)$e['id']])['json']['id'];
        $this->assertGreaterThan(0, $mid);
        $c->json('POST', '/api/custom_alerts.php?action=save', ['name' => 'Exp', 'conditions' => [['metric' => 'mood_max', 'op' => '>=', 'value' => 3, 'mode' => 'day']]]);
        $c->json('POST', '/api/ai.php?action=key', ['provider' => 'custom', 'base_url' => 'http://127.0.0.1:' . TestEnv::$xaiPort . '/v1', 'model' => 'modelo-x', 'api_key' => 'sk-segredo-export-1234']);

        $z = $c->get('/api/account.php?action=export&format=zip');
        $this->assertSame(200, $z['status']);
        $this->assertStringContainsString('application/zip', $c->header($z, 'content-type'));
        $f = tempnam(sys_get_temp_dir(), 'z');
        file_put_contents($f, $z['body']);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($f) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
        $this->assertContains('dados.json', $names);
        $this->assertContains('registros.csv', $names);
        $this->assertContains('LEIA-ME.txt', $names);
        $this->assertContains('midias/' . $mid . '.png', $names);
        $j = json_decode((string)$zip->getFromName('dados.json'), true);
        foreach (['registros', 'midias', 'substancias', 'sexualidade', 'alertas_personalizados', 'destinatarios', 'lembretes', 'notificacoes_push', 'uso_ia'] as $k) $this->assertArrayHasKey($k, $j);
        $this->assertSame('nota-exportada', $j['registros'][0]['note']);
        $this->assertTrue($j['usuario']['tem_chave_ia']);
        $all = (string)$zip->getFromName('dados.json');
        $zip->close();
        foreach (['ai_key_enc', 'sk-segredo-export-1234', 'password_hash', 'sex_pin_hash'] as $bad) $this->assertStringNotContainsString($bad, $all);
        $this->assertStringNotContainsString('sk-segredo-export-1234', $c->get('/api/account.php?action=export&format=json')['body']);

        // exclusão: tudo some (banco e arquivos)
        $this->assertSame(422, $c->json('POST', '/api/account.php?action=delete', ['confirm' => 'excluir'])['status']);
        $this->assertSame(200, $c->json('POST', '/api/account.php?action=delete', ['confirm' => 'EXCLUIR'])['status']);
        foreach (['users' => 'id', 'entries' => 'user_id', 'media' => 'user_id', 'substance_log' => 'user_id', 'daily_markers' => 'user_id', 'alert_rules' => 'user_id', 'alerts' => 'user_id', 'oauth_identities' => 'user_id', 'ai_usage' => 'user_id', 'reminders' => 'user_id', 'recipients' => 'user_id'] as $t => $col)
            $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM $t WHERE $col = $uid")->fetchColumn(), $t);
        $this->assertDirectoryDoesNotExist(TestEnv::$tmp . '/storage/media/' . $uid);
        $this->assertSame(401, $c->get('/api/entries.php')['status']);
    }

    public function testLegalPagesPublicAndLinked(): void
    {
        $p = $this->prod();
        $priv = $p->get('/privacidade.php');
        $this->assertSame(200, $priv['status']);
        foreach (['Em linguagem simples', 'sexo, drogas e rock and roll', 'Não existe painel de administração', 'código é aberto', 'biometria ou PIN', 'quantidade', 'sua própria chave',
            'compartilhada', 'acesso técnico', 'art. 11, I', 'Transferência internacional', 'cookie', 'Por quanto tempo', 'Seus direitos', 'não é aconselhamento jurídico', 'conforme os termos e a política de privacidade desse provedor'] as $t)
            $this->assertStringContainsString($t, $priv['body'], $t);
        $this->assertStringNotContainsStringIgnoringCase('musk', $priv['body']);
        $t = $p->get('/termos.php');
        $this->assertSame(200, $t['status']);
        $this->assertStringContainsString('AGPL-3.0', $t['body']);
        $this->assertStringContainsString('href="/termos.php"', $priv['body'], 'rodapé');
        $this->assertStringContainsString('href="/termos.php"', $p->get('/conta/cadastro.php')['body'], 'cadastro');
        $this->assertStringContainsString('href="/privacidade.php"', $p->get('/conta/cadastro.php')['body']);
    }

    public function testNoMuskAnywhere(): void
    {
        foreach (['public_html', 'app', 'cron', 'tools', 'docs'] as $d) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(TestEnv::ROOT . '/' . $d, FilesystemIterator::SKIP_DOTS)) as $f) {
                if (!preg_match('/\.(php|js|css|md|html|json|webmanifest)$/', (string)$f)) continue;
                $this->assertStringNotContainsStringIgnoringCase('musk', (string)file_get_contents((string)$f), (string)$f);
            }
        }
        foreach (['README.md', 'SECURITY.md', 'CONTRIBUTING.md'] as $f) $this->assertStringNotContainsStringIgnoringCase('musk', (string)file_get_contents(TestEnv::ROOT . '/' . $f));
    }

    public function testByokSaveTestUseRemoveNeverLeaksKey(): void
    {
        $c = $this->user('v43byok');
        $uid = $this->uid('v43byok');
        $base = 'http://127.0.0.1:' . TestEnv::$xaiPort . '/v1';
        $g = $c->get('/api/ai.php')['json'];
        $this->assertTrue($g['byok']);
        $this->assertSame(['xai', 'openai', 'anthropic', 'google', 'deepseek', 'mistral', 'openrouter', 'custom'], array_column($g['providers'], 'key'));
        // validações
        $this->assertSame(422, $c->json('POST', '/api/ai.php?action=key', ['provider' => 'nada', 'api_key' => 'abcdefgh123'])['status']);
        $this->assertSame(422, $c->json('POST', '/api/ai.php?action=key', ['provider' => 'openai'])['status'], 'sem chave');
        $this->assertSame(422, $c->json('POST', '/api/ai.php?action=key', ['provider' => 'openai', 'model' => 'x y', 'api_key' => 'sk-abcdefgh'])['status'], 'modelo inválido');
        // testar chave: erros claros
        $bad = $c->json('POST', '/api/ai.php?action=key_test', ['provider' => 'custom', 'base_url' => $base, 'model' => 'm', 'api_key' => 'sk-chave-invalida-0000']);
        $this->assertSame(422, $bad['status']);
        $this->assertStringContainsString('Chave inválida', $bad['json']['mensagem']);
        $nc = $c->json('POST', '/api/ai.php?action=key_test', ['provider' => 'custom', 'base_url' => $base, 'model' => 'm', 'api_key' => 'sk-sem-credito-0000']);
        $this->assertStringContainsString('sem crédito', $nc['json']['mensagem']);
        $ok = $c->json('POST', '/api/ai.php?action=key_test', ['provider' => 'custom', 'base_url' => $base, 'model' => 'meu-modelo', 'api_key' => 'sk-minha-chave-boa-9876']);
        $this->assertSame(200, $ok['status'], $ok['body']);
        $this->assertStringContainsString('Chave funcionando', $ok['json']['mensagem']);
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM users WHERE id = $uid AND ai_key_enc IS NOT NULL")->fetchColumn(), 'testar não salva');
        // salvar
        $s = $c->json('POST', '/api/ai.php?action=key', ['provider' => 'custom', 'base_url' => $base, 'model' => 'meu-modelo', 'api_key' => 'sk-minha-chave-boa-9876']);
        $this->assertSame(200, $s['status'], $s['body']);
        $this->assertSame('9876', $s['json']['choice']['last4']);
        $this->assertStringNotContainsString('sk-minha-chave-boa', $s['body']);
        $row = db()->query("SELECT ai_key_enc, ai_key_last4, ai_provider FROM users WHERE id = $uid")->fetch();
        $this->assertStringStartsWith('v1:', $row['ai_key_enc']);
        $this->assertStringNotContainsString('sk-minha-chave-boa', $row['ai_key_enc'], 'cifrada no banco');
        $this->assertSame('sk-minha-chave-boa-9876', ai_decrypt_key((string)$row['ai_key_enc']));
        $g = $c->get('/api/ai.php');
        $this->assertStringNotContainsString('sk-minha-chave-boa', $g['body']);
        $this->assertSame('9876', $g['json']['choice']['last4']);
        $this->assertFalse($g['json']['using_operator_key']);
        $home = $c->get('/')['body'];
        $this->assertStringNotContainsString('sk-minha-chave-boa', $home);
        $this->assertStringContainsString('…9876', $home);
        // trocar só o modelo mantém a chave
        $this->assertSame(200, $c->json('POST', '/api/ai.php?action=key', ['provider' => 'custom', 'base_url' => $base, 'model' => 'outro-modelo'])['status']);
        // usar: vai ao provedor escolhido, com a chave da pessoa, e não conta no teto do operador
        $c->json('POST', '/api/ai.php?action=consent', ['consent' => true, 'categories' => ['humor']]);
        $r = $c->json('POST', '/api/ai.php?action=resumo', []);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertStringContainsString('outro-modelo', $r['json']['aviso']);
        $this->assertStringNotContainsStringIgnoringCase('musk', $r['json']['aviso']);
        $last = json_decode((string)file_get_contents(TestEnv::$tmp . '/xai/last.json'), true);
        $this->assertSame('Bearer sk-minha-chave-boa-9876', $last['auth']);
        $this->assertSame('outro-modelo', $last['body']['model']);
        $this->assertEqualsWithDelta(0.0, (float)db()->query("SELECT cost_usd FROM ai_usage WHERE user_id = $uid")->fetchColumn(), 1e-9);
        // a chave nunca vai para o log
        $c->json('POST', '/api/ai.php?action=key_test', ['provider' => 'custom', 'base_url' => $base, 'model' => 'm', 'api_key' => 'sk-chave-invalida-LOGTEST']);
        foreach ([TestEnv::$tmp . '/storage/logs/ai.log', TestEnv::$tmp . '/server.log'] as $lf) if (is_file($lf)) {
            $txt = (string)file_get_contents($lf);
            $this->assertStringNotContainsString('sk-minha-chave-boa-9876', $txt);
            $this->assertStringNotContainsString('chave-invalida-LOGTEST', $txt);
        }
        // remover
        $this->assertSame(200, $c->json('POST', '/api/ai.php?action=key_remove', [])['status']);
        $this->assertNull(db()->query("SELECT ai_key_enc FROM users WHERE id = $uid")->fetchColumn() ?: null);
        $this->assertFalse($c->get('/api/ai.php')['json']['choice']['has_key']);
    }

    public function testCustomUrlBlocksInternalNetworkInProduction(): void
    {
        $old = $GLOBALS['CONFIG']['ai'];
        $GLOBALS['CONFIG']['ai']['allow_private_urls'] = false;
        try {
            foreach (['http://exemplo.com/v1', 'https://127.0.0.1/v1', 'https://10.0.0.5/v1', 'https://169.254.169.254/latest', 'https://user:pw@exemplo.com/v1', 'ftp://exemplo.com'] as $u)
                $this->assertNotNull(ai_base_url_problem($u), $u);
            $this->assertNull(ai_base_url_problem('https://8.8.8.8/v1'));
        } finally { $GLOBALS['CONFIG']['ai'] = $old; }
    }

    public function testProductionWithoutSecretKeyHidesByok(): void
    {
        $p = $this->prod();
        $r = $p->get('/');
        $this->assertSame(200, $r['status']);
        $this->assertStringNotContainsString('form-ai-key', $r['body']);
    }
}
