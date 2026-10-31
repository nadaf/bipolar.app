<?php

namespace App\Domain\Export;

use App\Domain\Care\CareScope;
use App\Domain\Care\GrantDirectory;
use App\Domain\Mood\Scales;
use App\Models\AuditLog;
use App\Models\MoodEntry;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;

/**
 * Exportacao de registros de humor em CSV (Excel pt-BR: UTF-8 com BOM, separador ;, datas dd/mm/aaaa)
 * e PDF (dompdf, PHP puro: roda em hospedagem compartilhada, sem acesso a rede).
 *
 * - Paciente: os proprios registros, no tenant da sessao.
 * - Profissional/familiar: SO pacientes com concessao ativa para ele (GrantDirectory), respeitando
 *   data_from e as categorias (sono so com SONO; anotacao so com PENSAMENTOS). Leitura no tenant do paciente.
 * - Toda exportacao gera AuditLog (quem, de quem, formato, categorias). Nome de arquivo neutro, sem nome nem dado.
 */
final class RecordExport
{
    public const FORMATS = ['csv', 'pdf'];

    public const MAX_DAYS = 1096;

    public const HEADERS = ['Data', 'Período', 'Humor', 'Humor (descrição)', 'Energia', 'Energia (descrição)', 'Ansiedade', 'Irritabilidade', 'Sono (horas)', 'Medicação tomada', 'Anotação'];

    public function __construct(private TenantContext $ctx, private GrantDirectory $dir) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return ['formato' => ['required', 'in:csv,pdf'], 'de' => ['required', 'date_format:Y-m-d'], 'ate' => ['required', 'date_format:Y-m-d', 'after_or_equal:de']];
    }

    public static function filename(string $format): string
    {
        return 'bipolar-registros-'.now()->format('Y-m-d').'.'.$format;
    }

    /** @return array{0: string, 1: string} intervalo limitado a MAX_DAYS */
    public static function clamp(string $from, string $to): array
    {
        $f = CarbonImmutable::parse($from);
        $t = CarbonImmutable::parse($to);
        if ($f->diffInDays($t) > self::MAX_DAYS) {
            $f = $t->subDays(self::MAX_DAYS);
        }

        return [$f->toDateString(), $t->toDateString()];
    }

    /** Paciente exporta os proprios registros (tenant ja resolvido). */
    public function own(User $patient, string $format, string $from, string $to): string
    {
        [$from, $to] = self::clamp($from, $to);
        $rows = $this->rows($patient->id, $from, $to, CareScope::MOOD | CareScope::SLEEP | CareScope::THOUGHTS);
        AuditLog::create(['actor_id' => $patient->id, 'patient_id' => $patient->id, 'action' => 'export.'.$format,
            'subject_type' => 'user', 'subject_id' => $patient->id]);

        return $this->render($format, $rows, $patient->name, $from, $to);
    }

    /** Profissional/familiar exporta paciente vinculado. null => sem concessao (404). */
    public function granted(User $grantee, int $patientId, string $format, string $from, string $to): ?string
    {
        $g = $this->dir->find($grantee, $patientId, CareScope::MOOD);
        $patient = $g ? User::query()->find($patientId) : null;
        if ($g === null || $patient === null) {
            return null;
        }
        [$from, $to] = self::clamp($from, $to);
        if ($g->data_from !== null && $from < $g->data_from) {
            $from = $g->data_from;
        }

        return $this->ctx->run($g->tenant_id, function () use ($g, $grantee, $patient, $format, $from, $to) {
            $rows = $this->rows($patient->id, $from, $to, $g->scope_mask);
            AuditLog::create(['actor_id' => $grantee->id, 'patient_id' => $patient->id, 'action' => 'care.exported_'.$format,
                'subject_type' => 'care_grant', 'subject_id' => $g->id, 'scope_mask' => $g->scope_mask]);

            return $this->render($format, $rows, $patient->name, $from, $to);
        });
    }

    /** @return Collection<int, array<int, string>> */
    public function rows(int $patientId, string $from, string $to, int $mask): Collection
    {
        $sleep = ($mask & CareScope::SLEEP) === CareScope::SLEEP;
        $note = ($mask & CareScope::THOUGHTS) === CareScope::THOUGHTS;

        return MoodEntry::query()->where('patient_id', $patientId)->whereBetween('entry_date', [$from, $to])
            ->orderBy('entry_date')->orderByRaw("case period when 'manha' then 1 when 'tarde' then 2 else 3 end")->get()
            ->map(fn (MoodEntry $e): array => $this->row($e, $sleep, $note))->values();
    }

    /** @return array<int, string> */
    private function row(MoodEntry $e, bool $sleep, bool $note): array
    {
        $n = fn (?int $v): string => $v === null ? '' : (string) $v;

        return [
            $e->entry_date->format('d/m/Y'), $e->period->label(),
            $n($e->mood), (string) Scales::label(Scales::MOOD, $e->mood),
            $n($e->energy), (string) Scales::label(Scales::ENERGY, $e->energy),
            $n($e->anxiety), $n($e->irritability),
            $sleep && $e->sleep_hours !== null ? number_format((float) $e->sleep_hours, 1, ',', '') : '',
            $e->medication_taken === null ? '' : ($e->medication_taken ? 'sim' : 'não'),
            $note ? (string) $e->note : '',
        ];
    }

    /** @param Collection<int, array<int, string>> $rows */
    public function render(string $format, Collection $rows, string $name, string $from, string $to): string
    {
        return $format === 'pdf' ? $this->pdf($rows, $name, $from, $to) : $this->csv($rows);
    }

    /** @param Collection<int, array<int, string>> $rows */
    public function csv(Collection $rows): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, self::HEADERS, ';', '"', '', "\r\n");
        foreach ($rows as $r) {
            fputcsv($h, array_map(self::cell(...), $r), ';', '"', '', "\r\n");
        }
        rewind($h);
        $out = (string) stream_get_contents($h);
        fclose($h);

        return $out;
    }

    /** Neutraliza injecao de formula em planilhas (=, +, -, @, tab, CR) em texto livre. */
    public static function cell(string $v): string
    {
        return $v !== '' && ! is_numeric($v) && str_contains("=+-@\t\r", $v[0]) ? "'".$v : $v;
    }

    /** @param Collection<int, array<int, string>> $rows */
    public function pdf(Collection $rows, string $name, string $from, string $to): string
    {
        $o = new Options;
        $o->setIsRemoteEnabled(false);
        $o->setIsPhpEnabled(false);
        $o->setIsJavascriptEnabled(false);
        $o->setChroot(resource_path('views/export'));
        $o->setTempDir(storage_path('framework/cache'));
        $o->setFontCache(storage_path('framework/cache'));
        $o->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($o);
        $pdf->loadHtml(view('export.pdf', ['rows' => $rows, 'headers' => self::HEADERS, 'name' => $name,
            'from' => CarbonImmutable::parse($from)->format('d/m/Y'), 'to' => CarbonImmutable::parse($to)->format('d/m/Y'),
            'generated' => now()->format('d/m/Y H:i')])->render(), 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return (string) $pdf->output();
    }
}
