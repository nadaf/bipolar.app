<?php
declare(strict_types=1);

function bipolar_aut_report_fingerprint(array $report): string
{
    $records = [];
    foreach ($report['records'] as $record) {
        $records[] = ['id' => (string) ($record['id'] ?? ''), 'date' => $record['date'], 'period' => $record['period'], 'mood' => $record['mood'], 'note' => $record['note'] ?? null, 'details' => $record['details'] ?? null];
    }
    return hash('sha256', json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
}
function bipolar_aut_medication_label(string $value): string
{
    return ['' => 'não informada', 'taken' => 'tomada conforme prescrição', 'not-taken' => 'não tomada', 'not-applicable' => 'não se aplica'][$value] ?? 'não informada';
}
function bipolar_aut_details_text(array $details): string
{
    return 'Sono: ' . ($details['sleep'] === null ? 'não informado' : $details['sleep'] . ' h') . '. Medicação: ' . bipolar_aut_medication_label((string) $details['medication']) . '. Sintomas mistos: ' . ($details['mixed'] ? 'relatados' : 'não relatados') . '. Sintomas: ' . (implode(', ', $details['symptoms']) ?: 'não informados') . '. Contexto: ' . (implode(', ', $details['factors']) ?: 'não informado') . '.';
}
function bipolar_aut_render_report(array $report, string $recipientName): array
{
    $dateLabel = static fn(string $date): string => substr($date, 8, 2) . '/' . substr($date, 5, 2) . '/' . substr($date, 0, 4);
    $range = $dateLabel($report['from']) . ' a ' . $dateLabel($report['to']);
    $title = 'Relatório do diário — ' . $range;
    $distribution = [];
    foreach ($report['moodDistribution'] as $label => $count) $distribution[] = $label . ': ' . $count;
    $disclaimer = 'Documento pessoal, compartilhado por escolha do usuário. Não é um diagnóstico nem um alerta de crise. Lacunas são ausência de registro, não estabilidade. Notas e detalhes aparecem somente quando autorizados. Áudio e vídeo não estão incluídos.';
    $text = $title . "\n" . $report['recordCount'] . ' registros em ' . $report['daysWithRecords'] . " dias.\n" . implode(' · ', $distribution) . "\n\n" . $disclaimer . "\n\n";
    $rows = '';
    foreach ($report['records'] as $record) {
        $mood = ($record['mood'] > 0 ? '+' : '') . $record['mood'];
        $line = $dateLabel($record['date']) . ' · ' . bipolar_aut_period_label((string)$record['period']) . ': ' . $record['moodLabel'] . ' (' . $mood . ')';
        $text .= $line . "\n"; $details = '';
        if (array_key_exists('note', $record) && $record['note'] !== '') { $text .= 'Nota: ' . $record['note'] . "\n"; $details .= '<p><strong>Nota:</strong> ' . nl2br(bipolar_aut_escape($record['note']), false) . '</p>'; }
        if (isset($record['details'])) { $detailText = bipolar_aut_details_text($record['details']); $text .= $detailText . "\n"; $details .= '<p>' . bipolar_aut_escape($detailText) . '</p>'; }
        $text .= "\n";
        $rows .= '<tr><td style="padding:10px;border-bottom:1px solid #dde5d7">' . bipolar_aut_escape($dateLabel($record['date'])) . '</td><td style="padding:10px;border-bottom:1px solid #dde5d7">' . bipolar_aut_escape(bipolar_aut_period_label((string)$record['period'])) . '</td><td style="padding:10px;border-bottom:1px solid #dde5d7">' . bipolar_aut_escape($record['moodLabel'] . ' (' . $mood . ')') . '</td><td style="padding:10px;border-bottom:1px solid #dde5d7;overflow-wrap:anywhere">' . $details . '</td></tr>';
    }
    $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"></head><body style="font:14px/1.6 Arial,sans-serif;color:#2e432a;margin:0;padding:24px"><main style="max-width:900px;margin:auto"><p style="font-size:11px;color:#52664b">DIÁRIO PESSOAL · COMPARTILHAMENTO VOLUNTÁRIO</p><h1 style="font-size:24px">' . bipolar_aut_escape($title) . '</h1><p>Para: ' . bipolar_aut_escape($recipientName) . '</p><p>' . $report['recordCount'] . ' registros em ' . $report['daysWithRecords'] . ' dias.</p><p style="padding:14px;border-left:4px solid #456b53;background:#f2f6ed">' . bipolar_aut_escape($disclaimer) . '</p><h2 style="font-size:17px">Distribuição dos níveis registrados</h2><p>' . bipolar_aut_escape(implode(' · ', $distribution)) . '</p><table style="border-collapse:collapse;width:100%;text-align:left"><thead><tr style="background:#eff4e9"><th style="padding:10px">Data</th><th style="padding:10px">Período</th><th style="padding:10px">Humor</th><th style="padding:10px">Conteúdo autorizado</th></tr></thead><tbody>' . $rows . '</tbody></table><p style="font-size:11px;color:#52664b">Revogar uma programação não apaga cópias já recebidas. Não há links públicos para o diário.</p></main></body></html>';
    return ['subject' => $title, 'text' => $text, 'html' => $html];
}
