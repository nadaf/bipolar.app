<?php
/** Validação de entradas — funções puras, testáveis. */
/** Campos opcionais de um registro; só são sobrescritos na edição quando enviados. */
const ENTRY_OPTIONAL_FIELDS = ['sleep_hours', 'irritability', 'anxiety', 'energy', 'medication_taken', 'note', 'tags'];
/** @param mixed $d */
function valid_iso_date($d): bool {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1
        && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
}
/** @param mixed $v */
function int_or_null($v, int $min, int $max): ?int {
    if ($v === null || $v === '' || is_bool($v) || !is_numeric($v)) return null;
    $i = (int)$v;
    return ($i < $min || $i > $max) ? null : $i;
}
/**
 * @param array<string,mixed> $in
 * @return array{0: ?string, 1: array<string,mixed>} [mensagem de erro | null, dados normalizados]
 */
function validate_entry(array $in): array {
    $date = $in['entry_date'] ?? '';
    $period = $in['period'] ?? '';
    $mood = $in['mood'] ?? null;
    if (!valid_iso_date($date)) return ['Informe uma data válida.', []];
    if (!is_string($period) || !isset(PERIODS[$period])) return ['Escolha o período do dia (manhã, tarde ou noite).', []];
    if (is_bool($mood) || !is_numeric($mood) || (int)$mood != $mood || (int)$mood < -3 || (int)$mood > 3) return ['Escolha um nível de humor.', []];
    $sleep = $in['sleep_hours'] ?? null;
    $sleep = is_numeric($sleep) ? max(0.0, min(24.0, round((float)$sleep, 1))) : null;
    $med = $in['medication_taken'] ?? null;
    $med = ($med === null || $med === '' || !is_scalar($med)) ? null : ((int)$med ? 1 : 0);
    $tags = is_scalar($in['tags'] ?? null) ? (string)$in['tags'] : '';
    $tags = mb_substr(implode(', ', array_slice(array_filter(array_map('trim', explode(',', $tags)), function (string $t): bool { return $t !== ''; }), 0, 20)), 0, 500);
    $note = is_scalar($in['note'] ?? null) ? mb_substr(trim((string)$in['note']), 0, 5000) : '';
    $uuid = $in['client_uuid'] ?? null;
    $uuid = (is_string($uuid) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) ? strtolower($uuid) : null;
    return [null, [
        'entry_date' => $date, 'period' => $period, 'mood' => (int)$mood, 'sleep_hours' => $sleep,
        'irritability' => int_or_null($in['irritability'] ?? null, 0, 3), 'anxiety' => int_or_null($in['anxiety'] ?? null, 0, 3),
        'energy' => int_or_null($in['energy'] ?? null, 0, 3), 'medication_taken' => $med,
        'note' => $note !== '' ? $note : null, 'tags' => $tags !== '' ? $tags : null, 'client_uuid' => $uuid,
    ]];
}
/**
 * @param array<string,mixed> $in
 * @return array{0: ?string, 1: array<string,mixed>}
 */
function validate_reminder(array $in, bool $pushAvailable = true): array {
    $t = $in['time_local'] ?? '';
    if (!is_string($t) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) return ['Horário inválido.', []];
    $days = [];
    foreach ((array)($in['days'] ?? []) as $d) { if (is_numeric($d) && (int)$d >= 0 && (int)$d <= 6) $days[(int)$d] = true; }
    if (!$days) return ['Escolha ao menos um dia da semana.', []];
    $days = array_keys($days); sort($days);
    $allowed = $pushAvailable ? ['push', 'email', 'ambos'] : ['email'];
    $ch = $in['channel'] ?? 'email';
    if (!is_string($ch) || !in_array($ch, ['push', 'email', 'ambos'], true)) $ch = 'email';
    if (!in_array($ch, $allowed, true)) return ['Notificações push não estão disponíveis neste servidor. Escolha lembrete por e-mail.', []];
    return [null, ['time_local' => $t, 'days' => implode(',', $days), 'channel' => $ch]];
}
