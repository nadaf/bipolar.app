<?php
/**
 * V5.1: fila de envios no MySQL (lembretes, adiamentos de 30 min e relatórios por e-mail).
 *
 * Estados: ready → leased → sent | retry → leased ... | dead (5 tentativas ou erro permanente) | cancelled | expired.
 * - Reserva atômica: UPDATE ... SET lease_token = ? ... ORDER BY ... LIMIT n e depois SELECT pelo token
 *   (o MySQL 5.7 não tem SKIP LOCKED; o UPDATE com leitura travada garante que dois consumidores nunca pegam o mesmo item).
 * - O envio acontece FORA de transação; a confirmação só vale se o token da reserva ainda for o mesmo
 *   (reserva vencida e retomada por outro consumidor = confirmação recusada, sem efeito colateral).
 * - O efeito colateral (lembrete marcado como enviado, adiamento consumido, próximo relatório agendado) acontece
 *   na mesma transação da confirmação, e só depois de entrega confirmada.
 * - Entrega "pelo menos uma vez": se o processo morrer depois de enviar e antes de confirmar, a reserva vence e o item é
 *   reenviado (no máximo 5 tentativas no total). O flock dos crons é só otimização.
 */
const OUTBOX_MAX_ATTEMPTS = 5;
const OUTBOX_LEASE_SECONDS = 300;
const OUTBOX_STATES = ['ready', 'leased', 'sent', 'retry', 'dead', 'cancelled', 'expired'];

function outbox_dedupe(string ...$parts): string { return hash('sha256', implode('|', $parts)); }

/** Espera antes da tentativa seguinte: min(6 h, 5 min × 2^(tentativa−1)) + 0–60 s de variação. */
function outbox_backoff(int $attempt, ?int $jitter = null): int
{
    $attempt = max(1, $attempt);
    $base = (int)min(21600, 300 * (2 ** min(20, $attempt - 1)));
    return $base + ($jitter ?? random_int(0, 60));
}

/** Coloca um envio na fila; a chave de deduplicação impede repetir o mesmo envio. @return bool true se entrou agora */
function outbox_enqueue(PDO $pdo, string $kind, int $userId, int $refId, string $dedupe, string $dueUtc, ?string $localDate = null, ?string $expiresUtc = null, ?string $refValue = null): bool
{
    $st = $pdo->prepare("INSERT IGNORE INTO outbox (kind, user_id, ref_id, dedupe_key, state, available_at, expires_at, due_at, local_date, ref_value)
        VALUES (?, ?, ?, ?, 'ready', UTC_TIMESTAMP(), ?, ?, ?, ?)");
    $st->execute([$kind, $userId, $refId, $dedupe, $expiresUtc, $dueUtc, $localDate, $refValue]);
    return $st->rowCount() === 1;
}

/**
 * Vence o que passou do prazo e encerra reservas abandonadas na última tentativa.
 * @return array{expired:int, dead:int}
 */
function outbox_sweep(PDO $pdo): array
{
    $e = $pdo->prepare("UPDATE outbox SET state = 'expired', lease_token = NULL, lease_until = NULL, last_error_code = COALESCE(last_error_code, 'prazo_vencido')
        WHERE expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP() AND (state IN ('ready', 'retry') OR (state = 'leased' AND lease_until < UTC_TIMESTAMP()))");
    $e->execute();
    $d = $pdo->prepare("UPDATE outbox SET state = 'dead', lease_token = NULL, lease_until = NULL, last_error_code = 'sem_confirmacao'
        WHERE state = 'leased' AND lease_until < UTC_TIMESTAMP() AND attempts >= ?");
    $d->execute([OUTBOX_MAX_ATTEMPTS]);
    return ['expired' => $e->rowCount(), 'dead' => $d->rowCount()];
}

/**
 * Reserva até $n itens prontos (ou com reserva vencida) de forma atômica.
 * @param list<string> $kinds
 * @return array{token:string, rows:list<array<string,mixed>>}
 */
function outbox_lease(PDO $pdo, int $n, array $kinds = [], int $leaseSec = OUTBOX_LEASE_SECONDS): array
{
    $tok = bin2hex(random_bytes(16));
    $kindSql = $kinds ? ' AND kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')' : '';
    $sql = "UPDATE outbox SET state = 'leased', lease_token = ?, lease_until = UTC_TIMESTAMP() + INTERVAL ? SECOND, attempts = attempts + 1
        WHERE ((state IN ('ready', 'retry') AND available_at <= UTC_TIMESTAMP()) OR (state = 'leased' AND lease_until < UTC_TIMESTAMP()))
          AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) AND attempts < ?$kindSql
        ORDER BY available_at, id LIMIT " . max(1, min(200, $n));
    for ($try = 1; ; $try++) {
        try {
            $pdo->prepare($sql)->execute(array_merge([$tok, $leaseSec, OUTBOX_MAX_ATTEMPTS], $kinds));
            break;
        } catch (PDOException $e) {
            if ($try >= 3 || !in_array((int)($e->errorInfo[1] ?? 0), [1213, 1205], true)) throw $e;
            usleep(50000 * $try);
        }
    }
    $st = $pdo->prepare('SELECT * FROM outbox WHERE lease_token = ? ORDER BY id');
    $st->execute([$tok]);
    return ['token' => $tok, 'rows' => $st->fetchAll()];
}

/**
 * Confirma a entrega (só se a reserva ainda for deste consumidor) e aplica o efeito colateral na mesma transação.
 * @param array<string,mixed> $row
 * @param callable(PDO, array<string,mixed>):void|null $onDone
 */
function outbox_complete(PDO $pdo, array $row, string $token, ?callable $onDone = null): bool
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("UPDATE outbox SET state = 'sent', sent_at = UTC_TIMESTAMP(), lease_token = NULL, lease_until = NULL, last_error_code = NULL
            WHERE id = ? AND lease_token = ? AND state = 'leased'");
        $st->execute([$row['id'], $token]);
        if ($st->rowCount() !== 1) { $pdo->rollBack(); error_log('outbox: confirmação recusada (reserva perdida) item ' . (int)$row['id']); return false; }
        if ($onDone) $onDone($pdo, $row);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Registra a falha: 'permanent' ou 5ª tentativa → dead; 'cancel' → cancelled; senão → retry com espera crescente.
 * @param array<string,mixed> $row
 * @param callable(PDO, array<string,mixed>):void|null $onDead
 * @return string novo estado, ou '' se a reserva já não era deste consumidor
 */
function outbox_fail(PDO $pdo, array $row, string $token, string $class, string $code, ?callable $onDead = null, ?int $jitter = null): string
{
    $attempts = (int)$row['attempts'];
    $state = $class === 'cancel' ? 'cancelled' : (($class === 'permanent' || $attempts >= OUTBOX_MAX_ATTEMPTS) ? 'dead' : 'retry');
    $delay = $state === 'retry' ? outbox_backoff($attempts, $jitter) : 0;
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("UPDATE outbox SET state = ?, lease_token = NULL, lease_until = NULL, last_error_code = ?, available_at = UTC_TIMESTAMP() + INTERVAL ? SECOND
            WHERE id = ? AND lease_token = ? AND state = 'leased'");
        $st->execute([$state, mb_substr($code, 0, 40), $delay, $row['id'], $token]);
        if ($st->rowCount() !== 1) { $pdo->rollBack(); return ''; }
        if ($state !== 'retry' && $onDead) $onDead($pdo, $row);
        $pdo->commit();
        return $state;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Classifica a resposta HTTP de um envio push: ok | transient | permanent | unknown. */
function outbox_classify_http(int $status): string
{
    if ($status >= 200 && $status < 300) return 'ok';
    if ($status === 0) return 'unknown';             // tempo esgotado / rede: pode ou não ter chegado
    if (in_array($status, [408, 425, 429], true) || ($status >= 500 && $status < 600)) return 'transient';
    if ($status >= 400 && $status < 500) return 'permanent'; // 404/410 = assinatura expirada; 400/401/403/413 = pedido inválido
    return 'unknown';
}

/**
 * Classifica o resultado de send_mail_ex: ok | transient | permanent | unknown.
 * @param array{ok:bool, stage:string, code:int, reply:string, recipient_rejected:bool} $r
 */
function outbox_classify_mail(array $r): string
{
    if ($r['ok']) return 'ok';
    if ($r['recipient_rejected']) return 'permanent';
    $code = (int)$r['code'];
    if ($r['stage'] === 'data_end' && $code === 0) return 'unknown'; // mensagem enviada, sem resposta final
    if ($code >= 400 && $code < 500) return 'transient';
    if ($code >= 500 && in_array($r['stage'], ['mail_from', 'rcpt', 'data', 'data_end'], true)) return 'permanent';
    return 'transient'; // conexão, TLS, autenticação: pode ser passageiro ou configuração a corrigir
}

/**
 * Processa a fila em lotes pequenos até esvaziar ou acabar o tempo.
 * $handlers[kind] = ['send' => fn(PDO, row): array{0:string,1:string}, 'done' => fn(PDO,row):void, 'dead' => fn(PDO,row):void]
 * 'send' devolve [classe, código], classe = ok | transient | permanent | unknown | cancel.
 * @param array<string, array{send: callable, done?: callable, dead?: callable}> $handlers
 * @return array{sent:int, retry:int, dead:int, cancelled:int, lost:int}
 */
function outbox_process(PDO $pdo, array $handlers, int $batch = 20, int $budgetSec = 240, ?int $jitter = null): array
{
    $out = ['sent' => 0, 'retry' => 0, 'dead' => 0, 'cancelled' => 0, 'lost' => 0];
    $t0 = time();
    $kinds = array_keys($handlers);
    while (time() - $t0 < $budgetSec) {
        $l = outbox_lease($pdo, $batch, $kinds);
        if (!$l['rows']) break;
        foreach ($l['rows'] as $row) {
            $h = $handlers[(string)$row['kind']];
            try { $res = ($h['send'])($pdo, $row); } catch (Throwable $e) { error_log('outbox ' . $row['kind'] . ': ' . $e->getMessage()); $res = ['unknown', 'excecao']; }
            [$class, $code] = [(string)($res[0] ?? 'unknown'), (string)($res[1] ?? '')];
            if ($class === 'ok') {
                if (outbox_complete($pdo, $row, $l['token'], $h['done'] ?? null)) $out['sent']++; else $out['lost']++;
                continue;
            }
            $st = outbox_fail($pdo, $row, $l['token'], $class, $code !== '' ? $code : $class, $h['dead'] ?? null, $jitter);
            if ($st === 'retry') $out['retry']++; elseif ($st === 'dead') $out['dead']++; elseif ($st === 'cancelled') $out['cancelled']++; else $out['lost']++;
        }
    }
    return $out;
}

/* ---------- Lembretes: próximo horário (UTC) e data local (para não repetir no dia) ---------- */
const REMINDER_WINDOW_SECONDS = 900; // tolera atraso de até 15 min do cron

/**
 * Primeira ocorrência do lembrete cujo prazo (horário + 15 min) ainda não passou em $from.
 * Horário que não existe no dia da mudança de horário (ex.: 02:30 quando o relógio pula) vale o horário seguinte;
 * horário repetido (o relógio volta) vale uma única vez, porque a data local é a chave.
 * @return array{due:DateTimeImmutable, local_date:string}|null
 */
function reminder_next_due(string $timeLocal, string $days, string $tz, DateTimeImmutable $from): ?array
{
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeLocal)) return null;
    try { $z = new DateTimeZone($tz); } catch (Exception $e) { return null; }
    $set = array_flip(array_filter(explode(',', $days), function (string $d): bool { return $d !== '' && ctype_digit($d) && (int)$d <= 6; }));
    if (!$set) return null;
    $day = $from->setTimezone($z)->modify('-1 day');
    for ($i = 0; $i < 10; $i++, $day = $day->modify('+1 day')) {
        $date = $day->format('Y-m-d');
        $noon = new DateTimeImmutable($date . ' 12:00', $z);
        if (!isset($set[$noon->format('w')])) continue;
        $due = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $timeLocal, $z);
        if ($due === false) continue;
        if ($due->getTimestamp() + REMINDER_WINDOW_SECONDS >= $from->getTimestamp()) return ['due' => $due->setTimezone(new DateTimeZone('UTC')), 'local_date' => $date];
    }
    return null;
}

/**
 * Coloca na fila os lembretes que estão no horário (janela de 15 min) e grava o próximo horário de cada um.
 * @return int quantos entraram na fila agora
 */
function reminders_enqueue_due(PDO $pdo, ?DateTimeImmutable $now = null): int
{
    $now = $now ?? new DateTimeImmutable('now');
    $nowUtc = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $st = $pdo->prepare('SELECT r.id, r.user_id, r.time_local, r.days, r.last_sent_local_date, r.next_due_at, u.timezone FROM reminders r JOIN users u ON u.id = r.user_id
        WHERE r.active = 1 AND (r.next_due_at IS NULL OR r.next_due_at <= ?) ORDER BY r.next_due_at LIMIT 5000');
    $st->execute([$nowUtc]);
    $upd = $pdo->prepare('UPDATE reminders SET next_due_at = ? WHERE id = ?');
    $n = 0;
    foreach ($st->fetchAll() as $r) {
        $occ = reminder_next_due((string)$r['time_local'], (string)$r['days'], (string)$r['timezone'], $now);
        if ($occ === null) { $upd->execute([null, $r['id']]); continue; }
        $next = $occ;
        if ($occ['due']->getTimestamp() <= $now->getTimestamp()) {
            if ((string)$r['last_sent_local_date'] !== $occ['local_date']) {
                $dueS = $occ['due']->format('Y-m-d H:i:s');
                if (outbox_enqueue($pdo, 'reminder', (int)$r['user_id'], (int)$r['id'], outbox_dedupe('reminder', (string)$r['id'], $occ['local_date']), $dueS, $occ['local_date'],
                    $occ['due']->modify('+2 hours')->format('Y-m-d H:i:s'))) $n++;
            }
            $next = reminder_next_due((string)$r['time_local'], (string)$r['days'], (string)$r['timezone'], $occ['due']->modify('+' . (REMINDER_WINDOW_SECONDS + 1) . ' seconds'));
        }
        $upd->execute([$next ? $next['due']->format('Y-m-d H:i:s') : null, $r['id']]);
    }
    return $n;
}
