<?php
/**
 * V4.3: situação das tarefas cron, lida SOMENTE pela linha de comando (tools/cron_status.php).
 * Não há tela nem API para isso: o app não tem área de administração.
 */
/** As três tarefas cron do app: nome do batimento => [rótulo, frequência esperada em horas]. */
const CRON_JOBS = [
    'analise' => ['Análise de sinais e alertas (analyze_signals.php)', 1],
    'lembretes' => ['Envio de lembretes (send_reminders.php)', 1],
    'relatorios' => ['Envio de relatórios (send_reports.php)', 1],
];

/**
 * Situação de cada cron: última execução no fuso da pessoa e se está atrasada (> 2 dias).
 * @return list<array{job:string,label:string,last_utc:?string,last_local:?string,status:string,status_label:string,info:array<string,mixed>}>
 */
function cron_status_all(string $tz, ?int $now = null): array {
    $now = $now ?? time();
    try { $z = new DateTimeZone($tz); } catch (Exception $e) { $z = new DateTimeZone('America/Sao_Paulo'); }
    $out = [];
    foreach (CRON_JOBS as $job => [$label, $everyH]) {
        $h = cron_heartbeat_read($job);
        $row = ['job' => $job, 'label' => $label, 'last_utc' => null, 'last_local' => null, 'status' => 'nunca', 'status_label' => 'ainda não registrada', 'info' => []];
        if ($h !== null) {
            try { $d = new DateTime((string)$h['at']); } catch (Exception $e) { $out[] = $row; continue; }
            $age = $now - $d->getTimestamp();
            $row['last_utc'] = gmdate('c', $d->getTimestamp());
            $row['last_local'] = $d->setTimezone($z)->format('d/m/Y \\à\\s H:i');
            $late = $age > 2 * 86400;
            $row['status'] = $late ? 'atrasada' : ($age > 3 * 3600 * $everyH ? 'demorando' : 'ok');
            $row['status_label'] = ['ok' => 'em dia', 'demorando' => 'sem rodar há mais de 3 horas', 'atrasada' => 'atrasada: confira as tarefas cron'][$row['status']];
            $info = $h; unset($info['at']);
            $row['info'] = $info;
        }
        $out[] = $row;
    }
    return $out;
}
