<?php
declare(strict_types=1);
/** Somente o worker que conseguiu transicionar queued -> unknown pode chamar o provedor. */
function bipolar_aut_claim_delivery(PDO $pdo, array $job): bool
{
    $now = bipolar_db_now();
    $stmt = $pdo->prepare("UPDATE automation_outbox SET attempts = attempts + 1, status = 'unknown', last_attempt_at = ?, failure_code = 'attempt_started' WHERE id = ? AND user_id = ? AND status = 'queued'");
    $stmt->execute([$now, $job['id'], $job['user_id']]);
    if ($stmt->rowCount() !== 1) return false;
    try {
        $stmt = $pdo->prepare("UPDATE automation_deliveries SET status = 'unknown', last_attempt_at = ?, message = 'Tentativa iniciada. Aguardando confirmação.' WHERE id = ? AND user_id = ?");
        $stmt->execute([$now, $job['id'], $job['user_id']]);
    } catch (Throwable $e) { error_log('[bipolar] delivery history update deferred'); }
    return true;
}
function bipolar_aut_account_rate_limit(PDO $pdo, array $config, string $userId, string $action): void
{
    $limit = $action === 'send_report' ? 20 : 60;
    $window = max(60, (int)($config['limits']['rate_window_seconds'] ?? 900));
    $key = hash_hmac('sha256', 'account|' . $action . '|' . $userId, $config['app']['encryption_key']);
    $now = time(); $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT window_start, hit_count FROM rate_limits WHERE rate_key = ? FOR UPDATE'); $stmt->execute([$key]); $row = $stmt->fetch();
        if (!$row || $now - (int)$row['window_start'] >= $window) {
            $stmt = $pdo->prepare('INSERT INTO rate_limits(rate_key,window_start,hit_count) VALUES(?,?,1) ON DUPLICATE KEY UPDATE window_start=VALUES(window_start),hit_count=1'); $stmt->execute([$key,$now]); $count = 1;
        } else { $count = (int)$row['hit_count'] + 1; $stmt=$pdo->prepare('UPDATE rate_limits SET hit_count=? WHERE rate_key=?'); $stmt->execute([$count,$key]); }
        $pdo->commit();
        if ($count > $limit) throw new BipolarApiException('rate_limited',429);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
