<?php
/**
 * V4.8: compartilhamento controlado pelo paciente com profissionais de saúde.
 * - O paciente convida (por e-mail ou pelo código de uso único que o profissional gera); o profissional aceita.
 * - Escopo por categoria; as sensíveis (substâncias, sexualidade, pensamentos de morte) começam desligadas.
 * - Prazo opcional; revogar vale na hora. Acesso do profissional é só leitura e todo acesso fica registrado.
 * - Toda checagem é feita no servidor: o profissional só chega ao compartilhamento com professional_id = ele,
 *   status ativo e dentro do prazo (care_share_for_professional). Qualquer outra coisa é 404.
 * - Convite por e-mail sem conta: link com token secreto de uso único (14 dias), preso ao e-mail convidado.
 */

/** chave => [bit, rótulo, ligado por padrão, sensível] */
const CARE_SCOPE = [
    'humor' => [1, 'Humor, energia e marcadores gerais (sol, exercício, fala, gastos, medicação)', true, false],
    'sono' => [2, 'Sono', true, false],
    'estresse_alimentacao' => [4, 'Estresse e alimentação', true, false],
    'pensamentos' => [8, 'Pensamentos (positivos, negativos, acelerados, grandiosidade, ruminação)', true, false],
    'substancias' => [16, 'Substâncias, nicotina, cafeína e álcool', false, true],
    'sexualidade' => [32, 'Sexualidade', false, true],
    'pensamentos_morte' => [64, 'Pensamentos de morte ou suicídio', false, true],
];
const CARE_SCOPE_ALL = 127;
const CARE_SENSITIVE = 112;
const CARE_SCOPE_DEFAULT = 15;
const CARE_INVITE_DAYS = 14;
const CARE_CODE_HOURS = 24;
const CARE_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const PRO_KINDS = ['psiquiatra' => 'Psiquiatra', 'psicologo' => 'Psicólogo(a)', 'medico' => 'Médico(a)', 'enfermagem' => 'Enfermagem', 'terapeuta' => 'Terapeuta ocupacional', 'outro' => 'Outro profissional de saúde'];
const CARE_ACTIONS = ['convite' => 'convidou', 'aceito' => 'aceitou o convite', 'recusado' => 'recusou o convite', 'cancelado' => 'cancelou o convite',
    'revogado' => 'encerrou o compartilhamento', 'escopo' => 'alterou o que é compartilhado', 'visualizou' => 'viu os registros', 'imprimiu' => 'abriu a versão para imprimir', 'novo_link' => 'gerou um novo link de convite'];

/** @param mixed $in lista de chaves ou máscara */
function care_scope_from_input($in): int
{
    if (is_int($in) || (is_string($in) && ctype_digit($in))) return (int)$in & CARE_SCOPE_ALL;
    $m = 0;
    if (is_array($in)) foreach ($in as $k) if (is_string($k) && isset(CARE_SCOPE[$k])) $m |= CARE_SCOPE[$k][0];
    return $m;
}
/** @return list<string> */
function care_scope_keys(int $mask): array
{
    $o = [];
    foreach (CARE_SCOPE as $k => $s) if ($mask & $s[0]) $o[] = $k;
    return $o;
}
/** @return list<string> */
function care_scope_labels(int $mask): array
{
    $o = [];
    foreach (CARE_SCOPE as $s) if ($mask & $s[0]) $o[] = $s[1];
    return $o;
}

/**
 * Mantém só as colunas liberadas (entries e daily_markers). Anotações/texto livre nunca vão para o profissional.
 * @param list<array<string,mixed>> $entries
 * @param list<array<string,mixed>> $markers
 * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
 */
function care_scope_rows(int $scope, array $entries, array $markers): array
{
    $ek = ['entry_date', 'period'];
    if ($scope & 1) array_push($ek, 'mood', 'energy', 'irritability', 'anxiety', 'medication_taken');
    if ($scope & 2) $ek[] = 'sleep_hours';
    $mkCols = [1 => ['sun_minutes', 'sun_period', 'exercise_minutes', 'fast_speech', 'impulsive_spending', 'spending_amount', 'irritability_day', 'doses_json', 'signs_json', 'cycle_phase'],
        2 => ['bed_time', 'wake_time', 'sleep_quality'], 4 => ['stress_level', 'food_sweets', 'food_processed', 'food_binge'],
        8 => ['racing_thoughts', 'th_positive', 'th_negative', 'th_grandiose', 'th_rumination'], 16 => ['caffeine_cups', 'alcohol_units', 'cigarettes', 'nicotine_other'], 64 => ['th_death']];
    $mk = ['entry_date', 'user_id'];
    foreach ($mkCols as $bit => $cols) if ($scope & $bit) $mk = array_merge($mk, $cols);
    $pick = function (array $rows, array $keep): array {
        $f = array_flip($keep);
        // mantém todas as chaves (o relatório lê colunas direto) e zera o que não foi liberado
        return array_map(function (array $r) use ($f): array { $o = []; foreach ($r as $k => $v) $o[$k] = isset($f[$k]) ? $v : null; foreach ($f as $k => $_) $o[$k] = $r[$k] ?? null; return $o; }, $rows);
    };
    $e = $pick($entries, $ek);
    if (!($scope & 3)) $e = []; // sem humor nem sono, os registros do dia não entram
    return [$e, $pick($markers, $mk)];
}

/** Código do profissional: 8 caracteres sem ambíguos, mostrado como XXXX-XXXX. */
function care_code_normalize(string $raw): string { return (string)preg_replace('/[^A-Z0-9]/', '', strtoupper($raw)); }
function care_new_code(): string
{
    $s = '';
    for ($i = 0; $i < 8; $i++) $s .= CARE_CODE_ALPHABET[random_int(0, strlen(CARE_CODE_ALPHABET) - 1)];
    return substr($s, 0, 4) . '-' . substr($s, 4);
}
function care_hash(string $raw): string { return hash('sha256', 'care|' . $raw); }

/**
 * E-mail confirmado da conta (cadastro por e-mail verificado, ou login por provedor externo que já confirma o e-mail).
 * @param array<string,mixed> $u
 */
function care_verified_email(array $u): ?string
{
    $email = normalize_email((string)($u['email'] ?? ''));
    if ($email === '') return null;
    if (!empty($u['email_verified_at']) || !cfg('require_email_verification', true)) return $email;
    $st = db()->prepare("SELECT COUNT(*) FROM oauth_identities WHERE user_id = ? AND provider <> 'email'");
    $st->execute([$u['id']]);
    return (int)$st->fetchColumn() > 0 ? $email : null;
}

/** @param array<string,mixed> $u */
function care_today(array $u): string
{
    try { $tz = new DateTimeZone((string)($u['timezone'] ?? 'America/Sao_Paulo')); } catch (Exception $e) { $tz = new DateTimeZone('America/Sao_Paulo'); }
    return (new DateTime('now', $tz))->format('Y-m-d');
}

/**
 * Estado efetivo: um ativo com prazo vencido (no fuso do paciente) e um pendente com convite vencido contam como expirados.
 * @param array<string,mixed> $s linha de care_shares (+ ptz = fuso do paciente)
 */
function care_status(array $s): string
{
    $st = (string)$s['status'];
    if ($st === 'ativo' && !empty($s['expires_on']) && (string)$s['expires_on'] < care_today(['timezone' => $s['ptz'] ?? 'America/Sao_Paulo'])) return 'expirado';
    if ($st === 'pendente' && !empty($s['invite_expires_at']) && strtotime((string)$s['invite_expires_at'] . ' UTC') < time()) return 'expirado';
    return $st;
}

/** @param array<string,mixed> $u */
function care_is_pro(array $u): bool { return (int)($u['is_professional'] ?? 0) === 1; }

/**
 * ÚNICA porta de entrada do profissional aos dados: compartilhamento dele, ativo e no prazo. Senão null (a página responde 404).
 * @param array<string,mixed> $pro
 * @return array<string,mixed>|null
 */
function care_share_for_professional(PDO $pdo, array $pro, int $id): ?array
{
    if ($id <= 0 || !care_is_pro($pro)) return null;
    $st = $pdo->prepare("SELECT s.*, p.name AS pname, p.timezone AS ptz, p.prefs AS pprefs FROM care_shares s JOIN users p ON p.id = s.patient_id
        WHERE s.id = ? AND s.professional_id = ? AND s.status = 'ativo'");
    $st->execute([$id, $pro['id']]);
    $s = $st->fetch();
    if (!$s || care_status($s) !== 'ativo' || (int)$s['patient_id'] === (int)$pro['id']) return null;
    return $s;
}

/**
 * Período que o profissional pode ver: dentro de [data_from, min(hoje, expires_on)].
 * @param array<string,mixed> $s
 * @return array{0:string,1:string}
 */
function care_clip_range(array $s, ?string $from, ?string $to): array
{
    $today = care_today(['timezone' => $s['ptz'] ?? 'America/Sao_Paulo']);
    $max = !empty($s['expires_on']) && (string)$s['expires_on'] < $today ? (string)$s['expires_on'] : $today;
    $min = !empty($s['data_from']) ? (string)$s['data_from'] : '2000-01-01';
    $to = $to !== null && valid_iso_date($to) ? $to : $max;
    $from = $from !== null && valid_iso_date($from) ? $from : date('Y-m-d', (int)strtotime("$to -29 days"));
    if ($from > $to) [$from, $to] = [$to, $from];
    if ($to > $max) $to = $max;
    if ($from < $min) $from = $min;
    if ($from > $to) $from = $to;
    return [$from, $to];
}

function care_log(PDO $pdo, ?int $shareId, int $patientId, ?int $actorId, string $actorName, string $action, int $scope = 0, ?string $from = null, ?string $to = null): void
{
    $pdo->prepare('INSERT INTO care_access_log (share_id, patient_id, actor_id, actor_name, action, scope_mask, range_from, range_to) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$shareId, $patientId, $actorId, mb_substr($actorName, 0, 150), $action, $scope, $from, $to]);
}

/** @param array<string,mixed> $u */
function care_display_name(array $u): string
{
    $n = trim((string)($u['name'] ?? ''));
    $k = PRO_KINDS[(string)($u['professional_kind'] ?? '')] ?? '';
    $r = trim((string)($u['professional_reg'] ?? ''));
    return ($n !== '' ? $n : 'Profissional') . ($k !== '' ? " ($k" . ($r !== '' ? ", $r declarado" : '') . ')' : ($r !== '' ? " ($r declarado)" : ''));
}

function care_smtp_on(): bool { return trim((string)(cfg('smtp')['host'] ?? '')) !== ''; }
function care_invite_link(string $raw): string { return app_link('/convite-profissional.php?t=' . rawurlencode($raw)); }

/**
 * Novo token de convite (só o hash é guardado): [raw, hash].
 * @return array{0:string,1:string}
 */
function care_new_token(): array { $raw = b64url(random_bytes(32)); return [$raw, care_hash($raw)]; }

/**
 * Convite pendente pelo token (não usado, no prazo).
 * @return array<string,mixed>|null
 */
function care_invite_by_token(PDO $pdo, string $raw): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $raw)) return null;
    $st = $pdo->prepare("SELECT s.*, p.name AS pname, p.timezone AS ptz FROM care_shares s JOIN users p ON p.id = s.patient_id
        WHERE s.invite_token_hash = ? AND s.status = 'pendente' AND s.invite_expires_at > UTC_TIMESTAMP()");
    $st->execute([care_hash($raw)]);
    $s = $st->fetch();
    return $s ?: null;
}

/**
 * Este usuário pode aceitar/recusar o convite pendente? (dirigido a ele pelo código, ou ao e-mail confirmado dele)
 * @param array<string,mixed> $s
 * @param array<string,mixed> $u
 */
function care_invite_is_for(array $s, array $u): bool
{
    if ((string)$s['status'] !== 'pendente' || care_status($s) !== 'pendente' || (int)$s['patient_id'] === (int)$u['id']) return false;
    if ($s['professional_id'] !== null) return (int)$s['professional_id'] === (int)$u['id'];
    $mine = care_verified_email($u);
    return $mine !== null && $s['invite_email'] !== null && hash_equals(normalize_email((string)$s['invite_email']), $mine);
}

/**
 * Convites pendentes para mim (notificação dentro do app).
 * @param array<string,mixed> $u
 * @return list<array<string,mixed>>
 */
function care_pending_for(PDO $pdo, array $u): array
{
    $mine = care_verified_email($u);
    $st = $pdo->prepare("SELECT s.*, p.name AS pname, p.timezone AS ptz FROM care_shares s JOIN users p ON p.id = s.patient_id
        WHERE s.status = 'pendente' AND s.patient_id <> ? AND (s.professional_id = ? OR (s.professional_id IS NULL AND s.invite_email = ?)) ORDER BY s.id DESC LIMIT 50");
    $st->execute([$u['id'], $u['id'], $mine ?? '-']);
    return array_values(array_filter($st->fetchAll(), function (array $s) use ($u): bool { return care_invite_is_for($s, $u); }));
}

/**
 * Aceita o convite (vira ativo, o token deixa de valer). @return string|null erro
 * @param array<string,mixed> $u
 */
function care_accept(PDO $pdo, array $u, int $id): ?string
{
    if (!care_is_pro($u)) return 'Marque sua conta como “Profissional de saúde” para aceitar.';
    $st = $pdo->prepare('SELECT s.*, p.timezone AS ptz FROM care_shares s JOIN users p ON p.id = s.patient_id WHERE s.id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s || !care_invite_is_for($s, $u)) return 'Convite não encontrado ou expirado.';
    $up = $pdo->prepare("UPDATE care_shares SET status = 'ativo', professional_id = ?, accepted_at = UTC_TIMESTAMP(), invite_token_hash = NULL WHERE id = ? AND status = 'pendente'");
    $up->execute([$u['id'], $id]);
    if ($up->rowCount() !== 1) return 'Convite não encontrado ou expirado.';
    care_log($pdo, $id, (int)$s['patient_id'], (int)$u['id'], care_display_name($u), 'aceito', (int)$s['scope_mask']);
    return null;
}

/**
 * Relatório só leitura do profissional (mesmas funções do relatório do paciente, filtradas pelo escopo e pelas datas).
 * @param array<string,mixed> $s compartilhamento validado por care_share_for_professional
 */
function care_report_html(array $s, string $from, string $to): string
{
    require_once APP_ROOT . '/lib/report.php';
    require_once APP_ROOT . '/lib/custom_alerts.php';
    $pid = (int)$s['patient_id'];
    $scope = (int)$s['scope_mask'];
    $patient = ['id' => $pid, 'name' => (string)$s['pname']];
    $h = '';
    if ($scope & 1) {
        $d = report_data($pid, $from, $to);
        if (!($scope & 2)) $d['sleep_hidden'] = true;
        $h .= report_html($patient, $d, false);
    } else {
        $h .= '<p style="margin:0 0 12px">Período: <strong>' . fmt_date($from) . ' a ' . fmt_date($to) . '</strong> · Paciente: <strong>' . e($patient['name'] ?: 'Paciente') . '</strong></p>';
    }
    $h .= markers_report_html(markers_report_data($pid, $from, $to, ($scope & 32) !== 0, ($scope & 16) !== 0, ($scope & 4) !== 0,
        ['scope' => $scope, 'floor' => $s['data_from'] ? (string)$s['data_from'] : null, 'death' => ($scope & 64) !== 0]));
    if ($scope & 1) $h .= report_alerts_html(report_alerts_data($pid, $from, $to, (($scope & 32) ? CUSTOM_PRIV_SEX : 0) | (($scope & 16) ? CUSTOM_PRIV_SUBST : 0) | (($scope & 64) ? CUSTOM_PRIV_DEATH : 0)));
    return $h;
}

/**
 * E-mail do convite (só com SMTP configurado). Texto neutro: não diz nada de saúde além do convite.
 * @param array<string,mixed> $patient
 */
function care_send_invite_email(array $patient, string $to, string $link): bool
{
    if (!care_smtp_on()) return false;
    require_once APP_ROOT . '/lib/smtp.php';
    $first = trim(explode(' ', trim((string)$patient['name']))[0] ?? '');
    $html = email_layout('Convite para acompanhar registros', '<p>Olá!</p><p>' . e($first !== '' ? $first : 'Uma pessoa') . ' convidou você, como profissional de saúde, para acompanhar (só leitura) os registros que ela escolheu compartilhar no Bipolar — Diário de Humor.</p>'
        . '<p><a href="' . e($link) . '" style="display:inline-block;background:#2f6f73;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none">Ver convite</a></p>'
        . '<p style="font-size:13px;color:#50606c">O link vale por ' . CARE_INVITE_DAYS . ' dias, é de uso único e só funciona com uma conta deste e-mail. Se não esperava este convite, ignore.</p>');
    return send_mail($to, 'Convite para acompanhar registros', $html, '', 'convite_profissional');
}
