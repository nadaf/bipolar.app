<?php
/**
 * Contas de TESTE DE CARGA (loadtest+NNN@dominio). Usado por tools/seed_loadtest.php,
 * tools/cleanup_loadtest.php e public_html/loadtest-admin.php (desativado por padrão).
 */
function loadtest_domain(): string
{
    return (string)(parse_url((string)cfg('app_url'), PHP_URL_HOST) ?: 'bipolar.app.br');
}
function loadtest_email(int $i, string $domain): string { return sprintf('loadtest+%03d@%s', $i, $domain); }
function is_loadtest_email(string $email, string $domain): bool
{
    return preg_match('/^loadtest\+\d{1,6}@' . preg_quote(strtolower($domain), '/') . '$/', strtolower($email)) === 1;
}

/**
 * Cria (ou completa) N contas verificadas com a mesma senha e, opcionalmente, histórico de registros.
 * Idempotente: contas já existentes são mantidas (a senha é atualizada).
 * @return array{created:int, existing:int, entries:int}
 */
function loadtest_seed(int $count, string $password, int $days = 30, ?string $domain = null): array
{
    if ($count < 1 || $count > 2000) throw new InvalidArgumentException('count deve ser 1..2000');
    if (strlen($password) < 12) throw new InvalidArgumentException('a senha de teste precisa ter 12+ caracteres');
    $days = max(0, min(365, $days));
    $domain = $domain ?: loadtest_domain();
    $pdo = db();
    $hash = pw_hash($password); // um único hash para todas (rápido e suficiente para contas de teste)
    $tz = (string)cfg('default_timezone', 'America/Cuiaba');
    $created = $existing = $entries = 0;
    $periods = array_keys(PERIODS);
    for ($i = 1; $i <= $count; $i++) {
        $email = loadtest_email($i, $domain);
        $st = $pdo->prepare("SELECT user_id FROM oauth_identities WHERE provider = 'email' AND subject = ?");
        $st->execute([$email]);
        $uid = (int)$st->fetchColumn();
        if ($uid) {
            $pdo->prepare('UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?')->execute([$hash, $uid]);
            $existing++;
            continue;
        }
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO users (name, email, timezone, extreme_alerts, password_hash, email_verified_at) VALUES (?, ?, ?, 0, ?, UTC_TIMESTAMP())')
            ->execute([sprintf('Teste de carga %03d', $i), $email, $tz, $hash]);
        $uid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO oauth_identities (user_id, provider, subject) VALUES (?, 'email', ?)")->execute([$uid, $email]);
        if ($days > 0) {
            $rows = []; $vals = [];
            $today = new DateTime('now', new DateTimeZone($tz));
            $mood = 0;
            for ($d = $days; $d >= 1; $d--) {
                $date = (clone $today)->modify("-$d days")->format('Y-m-d');
                foreach ($periods as $p) {
                    $mood = max(-3, min(3, $mood + random_int(-1, 1)));
                    $rows[] = '(?,?,?,?,?,?)';
                    array_push($vals, $uid, $date, $p, $mood, $p === 'manha' ? random_int(5, 9) : null, 'teste de carga');
                }
            }
            foreach (array_chunk($rows, 300, true) as $k => $chunk) {
                $slice = array_slice($vals, $k * 300 * 6, count($chunk) * 6);
                $pdo->prepare('INSERT INTO entries (user_id, entry_date, period, mood, sleep_hours, tags) VALUES ' . implode(',', $chunk))->execute($slice);
            }
            $entries += count($rows);
        }
        $pdo->commit();
        $created++;
    }
    return ['created' => $created, 'existing' => $existing, 'entries' => $entries];
}

/**
 * Lista (dry-run) ou apaga TODAS as contas loadtest+NNN@dominio e seus dados (registros, mídias, lembretes, destinatários, tokens).
 * @return array{found:int, deleted:int, emails:list<string>}
 */
function loadtest_cleanup(bool $dryRun = true, ?string $domain = null): array
{
    $domain = $domain ?: loadtest_domain();
    $pdo = db();
    $st = $pdo->prepare("SELECT u.id, u.email FROM users u WHERE u.email LIKE ? UNION
        SELECT u.id, u.email FROM users u JOIN oauth_identities oi ON oi.user_id = u.id WHERE oi.provider = 'email' AND oi.subject LIKE ?");
    $like = 'loadtest+%@' . $domain;
    $st->execute([$like, $like]);
    $ids = [];
    foreach ($st->fetchAll() as $r) {
        // dupla checagem: só apaga e-mails EXATAMENTE no padrão loadtest+NNN@dominio
        if (is_loadtest_email((string)$r['email'], $domain)) $ids[(int)$r['id']] = (string)$r['email'];
    }
    $deleted = 0;
    if (!$dryRun) {
        foreach ($ids as $id => $email) {
            $dir = STORAGE_DIR . '/media/' . $id;
            if (is_dir($dir)) { foreach (glob($dir . '/*') ?: [] as $f) @unlink($f); @rmdir($dir); }
            $deleted += $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]) ? 1 : 0; // ON DELETE CASCADE apaga o resto
        }
    }
    return ['found' => count($ids), 'deleted' => $deleted, 'emails' => array_values($ids)];
}
