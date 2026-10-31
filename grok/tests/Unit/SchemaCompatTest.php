<?php
use PHPUnit\Framework\TestCase;

/** Garante compatibilidade com MySQL 5.7 (sem recursos exclusivos do MySQL 8). */
final class SchemaCompatTest extends TestCase
{
    private function sources(): string
    {
        $s = (string)file_get_contents(TestEnv::ROOT . '/schema.sql');
        foreach (glob(TestEnv::ROOT . '/migrations/*.sql') ?: [] as $m) $s .= file_get_contents($m);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(TestEnv::ROOT . '/app'));
        foreach ([$it, new RecursiveIteratorIterator(new RecursiveDirectoryIterator(TestEnv::ROOT . '/public_html')), new RecursiveIteratorIterator(new RecursiveDirectoryIterator(TestEnv::ROOT . '/cron'))] as $files) {
            foreach ($files as $f) if ($f->isFile() && $f->getExtension() === 'php' && $f->getFilename() !== 'config.php') $s .= file_get_contents($f->getPathname());
        }
        return $s;
    }
    public function testNoMysql8OnlyFeatures(): void
    {
        $src = $this->sources();
        $forbidden = [
            '/\bWITH\s+(RECURSIVE\s+)?\w+\s+AS\s*\(/i' => 'CTE',
            '/\bOVER\s*\(/i' => 'window function',
            '/\bJSON_TABLE\b/i' => 'JSON_TABLE',
            '/\bJSON\b(?=\s+(NOT\s+)?NULL|,)/' => 'coluna JSON',
            '/utf8mb4_0900/i' => 'colação MySQL 8',
            '/\bCHECK\s*\(/i' => 'CHECK constraint',
            '/DEFAULT\s*\(/i' => 'DEFAULT com expressão',
            '/\bAS\s+new\b/i' => 'alias de linha em INSERT (8.0.19+)',
            '/\bREGEXP_(LIKE|REPLACE|SUBSTR)\b/i' => 'REGEXP_* (8.0)',
            '/\bLATERAL\b/i' => 'LATERAL',
        ];
        foreach ($forbidden as $re => $what) $this->assertDoesNotMatchRegularExpression($re, $src, $what);
    }
    public function testIndexedVarcharsFitUtf8mb4Limit(): void
    {
        $schema = (string)file_get_contents(TestEnv::ROOT . '/schema.sql');
        preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\) ENGINE/s', $schema, $tables, PREG_SET_ORDER);
        // V4.4: + mfa_recovery_codes, V4.7: + period_markers. tabelas do módulo privado ficam fora deste schema.
        // V5.1: + outbox, entry_client_ids
        $this->assertCount(23, $tables);
        foreach ($tables as [, $name, $body]) {
            $this->assertStringContainsString('utf8mb4', $schema);
            preg_match_all('/^\s*(\w+) VARCHAR\((\d+)\)/m', $body, $cols, PREG_SET_ORDER);
            $len = [];
            foreach ($cols as [, $c, $n]) $len[$c] = (int)$n;
            preg_match_all('/KEY \w+ \(([^)]+)\)/', $body, $keys);
            foreach ($keys[1] as $k) foreach (array_map('trim', explode(',', $k)) as $col) {
                if (isset($len[$col])) $this->assertLessThanOrEqual(191, $len[$col], "$name.$col indexado > 191 caracteres");
            }
        }
    }
    public function testDatabaseVersionReported(): void
    {
        $v = (string)db()->query('SELECT VERSION()')->fetchColumn();
        fwrite(STDERR, "\n[banco de testes: $v]\n");
        $this->assertNotSame('', $v);
        $this->assertSame('utf8mb4', db()->query("SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()")->fetchColumn());
    }

    /** A migração roda sobre o schema anterior, duas vezes, e o resultado bate com o schema.sql novo. */
    public function testEmailLoginMigrationIsIdempotent(): void
    {
        $pdo = db();
        $pdo->exec('CREATE DATABASE IF NOT EXISTS bipolar_migtest CHARACTER SET utf8mb4');
        $pdo->exec('USE bipolar_migtest');
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $old = (string)file_get_contents(TestEnv::ROOT . '/schema.sql');
            $old = preg_replace('/  password_hash.*?session_epoch INT UNSIGNED NOT NULL DEFAULT 0,\n/s', '', $old);
            $old = preg_replace('/CREATE TABLE IF NOT EXISTS auth_tokens.*?;\n/s', '', (string)$old);
            self::runSql($pdo, (string)$old);
            $pdo->exec("INSERT INTO users (name) VALUES ('antigo')");
            $mig = (string)file_get_contents(TestEnv::ROOT . '/migrations/2026-10-14-email-login.sql');
            self::runSql($pdo, $mig);
            self::runSql($pdo, $mig); // segunda vez não falha
            $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'bipolar_migtest' AND TABLE_NAME = 'users' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
            $this->assertContains('password_hash', $cols);
            $this->assertContains('email_verified_at', $cols);
            $this->assertContains('session_epoch', $cols);
            $this->assertSame('0', (string)$pdo->query('SELECT session_epoch FROM users')->fetchColumn(), 'dados existentes preservados');
            $this->assertSame(['auth_tokens'], $pdo->query("SHOW TABLES LIKE 'auth_tokens'")->fetchAll(PDO::FETCH_COLUMN));
        } finally {
            $pdo->exec('DROP DATABASE IF EXISTS bipolar_migtest');
            $pdo->exec('USE `' . cfg('db.name') . '`');
        }
    }
    /** Migração V2 sobre o schema do servidor (login por e-mail), duas vezes; resultado igual ao schema.sql. */
    public function testV2V3MigrationsAreIdempotentAndMatchSchema(): void
    {
        $pdo = db();
        $pdo->exec('CREATE DATABASE IF NOT EXISTS bipolar_migtest CHARACTER SET utf8mb4');
        $pdo->exec('USE bipolar_migtest');
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $old = (string)file_get_contents(TestEnv::ROOT . '/schema.sql');
            $old = preg_replace('/  (prefs|sex_pin_hash|include_markers|include_sexuality|include_substances|include_alerts|last_alert_at|label|last_ok_at|snooze_until|include_custom_alerts|invite_status|invite_error_at|food_sweets|food_processed|food_binge|include_food|th_positive|th_negative|th_racing|th_grandiose|th_rumination|th_death|cigarettes|nicotine_other|include_death|religiosity|is_professional|professional_kind|professional_reg) [^\n]*\n/', '', $old);
            $old = preg_replace('/  UNIQUE KEY uq_recipient_user_email[^\n]*\n/', '', (string)$old);
            // V5.1: volta ao formato 0.5.0 (sem colunas/tabelas novas, índice antigo de oauth_identities, enum antigo)
            $old = preg_replace('/  (pending_email|pending_email_at|email_verified_email|subject_hash|next_due_at|payload) [^\n]*\n/', '', (string)$old);
            $old = preg_replace('/  UNIQUE KEY uq_oi_subject_hash \(subject_hash\),\n  KEY idx_oi_provider_subject \(provider, subject\(150\)\),/', '  UNIQUE KEY uq_provider_subject (provider, subject),', (string)$old);
            $old = preg_replace('/  KEY idx_rm_due[^\n]*\n/', '', (string)$old);
            $old = str_replace("ENUM('verify','reset','email_change','export')", "ENUM('verify','reset')", (string)$old);
            $old = preg_replace('/CREATE TABLE IF NOT EXISTS (outbox|entry_client_ids) .*?;\n/s', '', (string)$old);
            $old = preg_replace('/CREATE TABLE IF NOT EXISTS (daily_markers|sexuality_log|substance_log|alerts|webauthn_credentials|ai_usage|alert_rules|period_markers|care_shares|care_codes|care_access_log) .*?;\n/s', '', (string)$old);
            self::runSql($pdo, (string)$old);
            $this->assertSame([], $pdo->query("SHOW TABLES LIKE 'daily_markers'")->fetchAll());
            $pdo->exec("INSERT INTO users (name) VALUES ('antigo')");
            $pdo->exec("INSERT INTO recipients (user_id, name, email, role, frequency, confirm_token) VALUES (1, 'Dra', 'd@example.com', 'psiquiatra', 'semanal', 'x')");
            $mig = (string)file_get_contents(TestEnv::ROOT . '/migrations/2026-10-15-v2-marcadores.sql');
            self::runSql($pdo, $mig);
            self::runSql($pdo, $mig);
            // texto livre da V2 (caso alguém tenha usado) vira registro "Outra" na V3
            $pdo->exec("INSERT INTO daily_markers (user_id, entry_date, substances) VALUES (1, '2026-10-10', 'cannabis à noite'), (1, '2026-10-11', '  ')");
            $mig3 = (string)file_get_contents(TestEnv::ROOT . '/migrations/2026-10-16-v3-substancias.sql');
            self::runSql($pdo, $mig3);
            self::runSql($pdo, $mig3);
            $rows = $pdo->query('SELECT entry_date, substance, other_name, migrated FROM substance_log')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame([['entry_date' => '2026-10-10', 'substance' => 'outra', 'other_name' => 'cannabis à noite', 'migrated' => '1']], array_map(function ($r) { return array_map('strval', $r); }, $rows), 'migrado uma única vez');
            $this->assertSame('0', (string)$pdo->query('SELECT COUNT(*) FROM daily_markers WHERE substances IS NOT NULL')->fetchColumn());
            $this->assertSame('0', (string)$pdo->query('SELECT include_substances FROM recipients')->fetchColumn());
            // V4: tabelas novas e colunas em recipients/push_subscriptions; rodar de novo só dá #1060 (ignorado, como no guia)
            $pdo->exec("INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash) VALUES (1, 'https://push.example/x', REPEAT('a', 64))");
            $mig4 = (string)file_get_contents(TestEnv::ROOT . '/migrations/2026-10-17-v4-alertas-biometria-ia.sql');
            self::runSql($pdo, $mig4);
            self::runSql($pdo, $mig4);
            $this->assertSame('0', (string)$pdo->query('SELECT include_alerts FROM recipients')->fetchColumn(), 'alertas para apoio nunca vêm ligados');
            $this->assertSame('1', (string)$pdo->query('SELECT COUNT(*) FROM push_subscriptions WHERE label IS NULL AND snooze_until IS NULL')->fetchColumn());
            // V4.1: e-mail repetido para a mesma pessoa é removido (fica o mais antigo) e passa a ser proibido
            $pdo->exec("INSERT INTO recipients (user_id, name, email, role, frequency, confirm_token) VALUES (1, 'Dra 2', 'D@example.com', 'psiquiatra', 'semanal', 'y')");
            $mig41 = (string)file_get_contents(TestEnv::ROOT . '/migrations/2026-10-19-v4-1-destinatarios-unicos.sql');
            self::runSql($pdo, $mig41);
            self::runSql($pdo, $mig41);
            $this->assertSame([['Dra']], $pdo->query('SELECT name FROM recipients')->fetchAll(PDO::FETCH_NUM));
            $this->assertCount(2, $pdo->query("SHOW INDEX FROM recipients WHERE Key_name = 'uq_recipient_user_email'")->fetchAll());
            try { $pdo->exec("INSERT INTO recipients (user_id, name, email, role, frequency, confirm_token) VALUES (1, 'X', 'd@EXAMPLE.com', 'outro', 'semanal', 'z')"); $this->fail('duplicado aceito'); } catch (PDOException $e) { $this->assertSame(1062, (int)$e->errorInfo[1]); }
            // V4.2: regras personalizadas + colunas novas em alerts/recipients; o convite fica sem status e o envio a apoio desligado
            $mig42 = (string)file_get_contents(TestEnv::ROOT . '/migrations/2026-10-21-v4-2-alertas-personalizados.sql');
            self::runSql($pdo, $mig42);
            self::runSql($pdo, $mig42);
            // V4.3, V4.3.1, V4.4 e V4.5: colunas de IA, de versão e da verificação em duas etapas, cada uma rodada duas vezes (#1060 pulado)
            // V4.7: dia antigo com estresse (valia para o dia inteiro) — vai para o último período registrado, uma única vez
            $pdo->exec("INSERT INTO daily_markers (user_id, entry_date, stress_level, stress_note) VALUES (1, '2026-10-01', 3, 'briga'), (1, '2026-10-02', 1, NULL), (1, '2026-10-03', NULL, NULL)");
            $pdo->exec("INSERT INTO entries (user_id, entry_date, period, mood) VALUES (1, '2026-10-01', 'manha', 0), (1, '2026-10-01', 'tarde', 1)");
            foreach (['2026-10-23-v4-3-ia-chave-propria.sql', '2026-10-25-v4-3-1-versao-registros.sql', '2026-10-27-v4-4-verificacao-duas-etapas.sql', '2026-11-03-v4-7-periodos-alimentacao.sql', '2026-11-04-v4-7-1-limpeza-vazios.sql', '2026-11-10-v4-8-pensamentos-nicotina-profissionais.sql'] as $mf) {
                $sqlM = (string)file_get_contents(TestEnv::ROOT . '/migrations/' . $mf);
                self::runSql($pdo, $sqlM);
                self::runSql($pdo, $sqlM);
            }
            // V5.1: sobre dados 0.5.0 — identidade sem hash, e-mail confirmado pelo link (token usado) e outro sem token
            $this->assertSame([], $pdo->query("SHOW TABLES LIKE 'outbox'")->fetchAll());
            $pdo->exec("UPDATE users SET email = 'antigo@example.com', email_verified_at = UTC_TIMESTAMP() WHERE id = 1");
            $pdo->exec("INSERT INTO users (name, email, email_verified_at) VALUES ('sem token', 'semtoken@example.com', UTC_TIMESTAMP())");
            $pdo->exec("INSERT INTO oauth_identities (user_id, provider, subject) VALUES (1, 'email', 'antigo@example.com'), (2, 'email', 'semtoken@example.com'), (2, 'google', 'g-1')");
            $pdo->exec("INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at, used_at) VALUES (1, 'verify', REPEAT('b', 64), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            $mig51 = (string)file_get_contents(TestEnv::ROOT . '/migrations/2026-11-17-v5-1-seguranca.sql');
            self::runSql($pdo, $mig51);
            self::runSql($pdo, $mig51);
            $this->assertSame(['antigo@example.com', null], $pdo->query('SELECT email_verified_email FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'só quem comprovou pelo link');
            $this->assertSame([hash('sha256', 'email|antigo@example.com'), hash('sha256', 'email|semtoken@example.com'), hash('sha256', 'google|g-1')],
                $pdo->query('SELECT subject_hash FROM oauth_identities ORDER BY user_id, provider')->fetchAll(PDO::FETCH_COLUMN), 'hash igual ao do PHP');
            try { $pdo->exec("INSERT INTO oauth_identities (user_id, provider, subject, subject_hash) VALUES (2, 'x', 'y', '" . hash('sha256', 'google|g-1') . "')"); $this->fail('hash duplicado aceito'); } catch (PDOException $e) { $this->assertSame(1062, (int)$e->errorInfo[1]); }
            $pdo->exec("INSERT INTO oauth_identities (user_id, provider, subject) VALUES (2, 'dev', 'durante-a-atualizacao')"); // código 0.5.0 ainda no ar
            $pdo->exec("INSERT INTO auth_tokens (user_id, purpose, token_hash, payload, expires_at) VALUES (1, 'email_change', REPEAT('c', 64), 'novo@example.com', UTC_TIMESTAMP())");
            $this->assertSame(['entry_client_ids', 'outbox'], array_merge($pdo->query("SHOW TABLES LIKE 'entry_client_ids'")->fetchAll(PDO::FETCH_COLUMN), $pdo->query("SHOW TABLES LIKE 'outbox'")->fetchAll(PDO::FETCH_COLUMN)));
            $this->assertSame(['alert_rules'], $pdo->query("SHOW TABLES LIKE 'alert_rules'")->fetchAll(PDO::FETCH_COLUMN));
            $pm = $pdo->query('SELECT entry_date, period, stress_level, stress_note, migrated FROM period_markers ORDER BY entry_date')->fetchAll(PDO::FETCH_NUM);
            $this->assertSame([['2026-10-01', 'tarde', '3', 'briga', '1'], ['2026-10-02', 'noite', '1', null, '1']], array_map(fn($r) => array_map(fn($v) => $v === null ? null : (string)$v, $r), $pm), 'estresse antigo copiado uma vez, sem perder o valor do dia');
            $this->assertSame('3', (string)$pdo->query("SELECT stress_level FROM daily_markers WHERE entry_date = '2026-10-01'")->fetchColumn(), 'daily_markers preservado');
            // V4.7.1: limpeza apaga só linhas sem nenhum dado (10-03 estava vazio), e de novo depois de novos vazios
            $this->assertSame(['2026-10-01', '2026-10-02'], $pdo->query('SELECT entry_date FROM daily_markers ORDER BY entry_date')->fetchAll(PDO::FETCH_COLUMN), 'dia vazio apagado, dias com dados mantidos');
            $pdo->exec("INSERT INTO period_markers (user_id, entry_date, period) VALUES (1, '2026-10-05', 'manha'), (1, '2026-10-05', 'tarde')");
            $pdo->exec("UPDATE period_markers SET food_binge = 0 WHERE entry_date = '2026-10-05' AND period = 'tarde'");
            $pdo->exec("INSERT INTO daily_markers (user_id, entry_date, doses_json) VALUES (1, '2026-10-06', NULL), (1, '2026-10-07', '{\"a\":1}')");
            self::runSql($pdo, (string)file_get_contents(TestEnv::ROOT . '/migrations/2026-11-04-v4-7-1-limpeza-vazios.sql'));
            $this->assertSame(['2026-10-01|tarde', '2026-10-02|noite', '2026-10-05|tarde'], $pdo->query("SELECT CONCAT(entry_date, '|', period) FROM period_markers ORDER BY entry_date, period")->fetchAll(PDO::FETCH_COLUMN), 'food_binge = 0 (não) é dado e fica');
            $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-07'], $pdo->query('SELECT entry_date FROM daily_markers ORDER BY entry_date')->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSame('0', (string)$pdo->query('SELECT include_food FROM recipients')->fetchColumn(), 'alimentação nunca vem ligada para quem já recebe relatório');
            $this->assertSame(['0', null], array_map(fn($v) => $v === null ? null : (string)$v, $pdo->query('SELECT include_custom_alerts, invite_status FROM recipients')->fetch(PDO::FETCH_NUM)));
            $cols = function (string $t) use ($pdo): array { return $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' ORDER BY COLUMN_NAME")->fetchAll(PDO::FETCH_ASSOC); };
            $migrated = [];
            foreach (['users', 'entries', 'recipients', 'daily_markers', 'sexuality_log', 'substance_log', 'push_subscriptions', 'alerts', 'webauthn_credentials', 'ai_usage', 'alert_rules', 'mfa_recovery_codes', 'period_markers', 'care_shares', 'care_codes', 'care_access_log', 'reminders', 'auth_tokens', 'outbox', 'entry_client_ids'] as $t) $migrated[$t] = $cols($t);
            // oauth_identities: na migração subject_hash fica NULL-ável (o código 0.5.0 continua inserindo durante a atualização) — o resto é igual
            $oi = array_values(array_filter($cols('oauth_identities'), fn($c) => $c['COLUMN_NAME'] !== 'subject_hash'));
            $this->assertSame('0', (string)$pdo->query('SELECT include_sexuality FROM recipients')->fetchColumn(), 'sexualidade nunca vem ligada');
            $this->assertSame('1', (string)$pdo->query('SELECT include_markers FROM recipients')->fetchColumn());
            // compara com schema.sql completo
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            self::runSql($pdo, (string)file_get_contents(TestEnv::ROOT . '/schema.sql'));
            foreach ($migrated as $t => $c) $this->assertSame($cols($t), $c, "colunas de $t iguais ao schema.sql");
            $this->assertSame(array_values(array_filter($cols('oauth_identities'), fn($c) => $c['COLUMN_NAME'] !== 'subject_hash')), $oi);
        } finally {
            $pdo->exec('DROP DATABASE IF EXISTS bipolar_migtest');
            $pdo->exec('USE `' . cfg('db.name') . '`');
        }
    }
    /** V5.1 (P2-a): regra de repetidos da migração V4.1 — fica o ativo, depois o cancelado, depois o que compartilha menos. */
    public function testV41DedupeKeepsActiveAndAbortsOnConflictingActives(): void
    {
        $pdo = db();
        $pdo->exec('CREATE DATABASE IF NOT EXISTS bipolar_migtest CHARACTER SET utf8mb4');
        $pdo->exec('USE bipolar_migtest');
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $old = preg_replace('/  UNIQUE KEY uq_recipient_user_email[^\n]*\n/', '', (string)file_get_contents(TestEnv::ROOT . '/schema.sql'));
            self::runSql($pdo, (string)$old);
            $pdo->exec("INSERT INTO users (name) VALUES ('a'), ('b')");
            $ins = $pdo->prepare('INSERT INTO recipients (id, user_id, name, email, confirm_token, confirmed_at, unsubscribed_at, include_notes, include_sexuality) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $now = gmdate('Y-m-d H:i:s');
            foreach ([
                [1, 1, 'a-pendente-mais', 'a@example.com', null, null, 1, 1], [2, 1, 'a-ativo', 'A@example.com', $now, null, 0, 0],
                [3, 1, 'b-cancelado', 'b@example.com', $now, $now, 0, 0], [4, 1, 'b-pendente', 'b@example.com', null, null, 0, 0],
                [5, 1, 'c-pendente-notas', 'c@example.com', null, null, 1, 0], [6, 1, 'c-pendente-menos', 'c@example.com', null, null, 0, 0],
                [7, 1, 'd-ativo-1', 'd@example.com', $now, null, 0, 0], [8, 1, 'd-ativo-2', 'd@example.com', $now, null, 0, 0],
                [9, 2, 'outra-pessoa', 'a@example.com', null, null, 1, 1],
            ] as $r) $ins->execute([$r[0], $r[1], $r[2], $r[3], 'tok' . $r[0], $r[4], $r[5], $r[6], $r[7]]);
            $mig = (string)file_get_contents(TestEnv::ROOT . '/migrations/2026-10-19-v4-1-destinatarios-unicos.sql');
            self::runSql($pdo, $mig);
            self::runSql($pdo, $mig);
            $this->assertSame(['a-ativo', 'b-cancelado', 'c-pendente-menos', 'd-ativo-1', 'outra-pessoa'], $pdo->query('SELECT name FROM recipients ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
            // dois ativos com opções diferentes: para com #1062 e não apaga nada
            $pdo->exec('ALTER TABLE recipients ADD KEY idx_tmp_user (user_id), DROP INDEX uq_recipient_user_email');
            $ins->execute([20, 2, 'e-ativo-sem', 'e@example.com', 'tok20', $now, null, 0, 0]);
            $ins->execute([21, 2, 'e-ativo-com-sexualidade', 'e@example.com', 'tok21', $now, null, 0, 1]);
            $before = (int)$pdo->query('SELECT COUNT(*) FROM recipients')->fetchColumn();
            try { self::runSql($pdo, $mig); $this->fail('conflito entre ativos não parou a migração'); }
            catch (PDOException $e) { $this->assertSame(1062, (int)$e->errorInfo[1]); }
            $this->assertSame($before, (int)$pdo->query('SELECT COUNT(*) FROM recipients')->fetchColumn(), 'nada apagado');
            $pdo->exec('DELETE FROM recipients WHERE id = 21'); // a pessoa escolhe à mão, como o guia explica
            self::runSql($pdo, $mig);
            $this->assertCount(2, $pdo->query("SHOW INDEX FROM recipients WHERE Key_name = 'uq_recipient_user_email'")->fetchAll());
        } finally {
            $pdo->exec('DROP DATABASE IF EXISTS bipolar_migtest');
            $pdo->exec('USE `' . cfg('db.name') . '`');
        }
    }
    /** Hospedagem: o usuário do phpMyAdmin não lê information_schema (#1044) — migrações são SQL simples. */
    public function testMigrationsArePlainSql(): void
    {
        foreach (glob(TestEnv::ROOT . '/migrations/*.sql') ?: [] as $f) {
            $sql = (string)preg_replace('/^--[^\n]*$/m', '', (string)file_get_contents($f));
            $this->assertDoesNotMatchRegularExpression('/information_schema/i', $sql, basename($f));
            $this->assertDoesNotMatchRegularExpression('/\b(PREPARE|EXECUTE|DEALLOCATE)\b/i', $sql, basename($f));
            $this->assertStringNotContainsString('@', $sql, basename($f) . ' usa variáveis @');
            $this->assertDoesNotMatchRegularExpression('/\bDELIMITER\b|\bPROCEDURE\b/i', $sql, basename($f));
            $this->assertStringContainsString('Duplicate column', (string)file_get_contents($f), basename($f) . ' explica o #1060');
        }
    }
    /** Executa como o phpMyAdmin: #1060/#1061 (coluna/índice já existe) é "pule esta linha", conforme o guia. */
    private static function runSql(PDO $pdo, string $sql): void
    {
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if (preg_match('/^(--[^\n]*(\n|$)\s*)*$/', $stmt)) continue;
            try {
                $q = $pdo->query($stmt);
                if ($q) $q->closeCursor();
            } catch (PDOException $e) {
                if (!in_array((int)($e->errorInfo[1] ?? 0), [1060, 1061], true)) throw $e;
            }
        }
    }
}
