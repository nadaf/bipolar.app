<?php

namespace App\Domain\Reports;

use App\Domain\Alerts\AlertSettingsService;
use App\Domain\Care\CareScope;
use App\Domain\Day\Blocks;
use App\Domain\Mood\Period;
use App\Domain\Mood\SignalClassifier;
use App\Models\DayBlock;
use App\Models\MoodEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Relatorio do periodo (paciente ou rede de apoio). Roda no tenant JA definido.
 * $scope limita as categorias (paciente = tudo exceto blocos com desbloqueio).
 */
final class ReportBuilder
{
    public const DISCLAIMER = 'Este relatório organiza o que foi registrado. Não é diagnóstico; converse com seu profissional de saúde.';

    public const MAX_DAYS = 366;

    public function __construct(private AlertSettingsService $alerts) {}

    /** @return array<string, mixed> */
    public function build(User $patient, string $from, string $to, int $scope, bool $includeStepUp = false): array
    {
        $t = $this->alerts->for($patient);
        $entries = MoodEntry::query()->where('patient_id', $patient->id)->whereBetween('entry_date', [$from, $to])
            ->orderBy('entry_date')->get();
        $blocks = DayBlock::query()->where('patient_id', $patient->id)->whereBetween('entry_date', [$from, $to])
            ->orderBy('entry_date')->get()
            ->filter(fn (DayBlock $b) => (Blocks::SPEC[$b->block]['scope'] & $scope) !== 0 && ($includeStepUp || ! Blocks::stepUp($b->block)));

        $signals = ['depressivo' => 0, 'maniaco' => 0, 'misto' => 0, 'neutro' => 0];
        $days = [];
        $orderP = array_flip(array_map(fn (Period $p) => $p->value, Period::cases()));
        foreach ($entries as $e) {
            $d = $e->entry_date->format('Y-m-d');
            $sig = SignalClassifier::classify($e->mood, $e->energy, $t);
            $signals[$sig]++;
            $days[$d]['periods'][$e->period->value] = ['mood' => $e->mood, 'energy' => $e->energy, 'anxiety' => $e->anxiety,
                'irritability' => $e->irritability, 'signal' => $sig, 'note' => ($scope & CareScope::THOUGHTS) ? $e->note : null];
        }
        $sleep = [];
        foreach ($blocks as $b) {
            $d = $b->entry_date->format('Y-m-d');
            $days[$d]['blocks'][] = ['block' => $b->block, 'title' => Blocks::SPEC[$b->block]['title'],
                'period' => $b->period === 'dia' ? null : Period::from($b->period)->label(), 'summary' => Blocks::summary($b->block, $b->data)];
            if ($b->block === 'sono' && isset($b->data['horas'])) {
                $sleep[] = (float) $b->data['horas'];
            }
        }
        ksort($days);
        foreach ($days as &$day) {
            if (isset($day['periods'])) {
                uksort($day['periods'], fn ($a, $b) => $orderP[$a] <=> $orderP[$b]);
            }
        }
        unset($day);

        $moods = $entries->pluck('mood')->filter(fn ($v) => $v !== null);
        $energies = $entries->pluck('energy')->filter(fn ($v) => $v !== null);

        return [
            'from' => $from, 'to' => $to, 'days_recorded' => count($days),
            'mood_avg' => $moods->isEmpty() ? null : round((float) $moods->avg(), 1),
            'energy_avg' => $energies->isEmpty() ? null : round((float) $energies->avg(), 1),
            'sleep_avg' => $sleep === [] ? null : round(array_sum($sleep) / count($sleep), 1),
            'signals' => $signals, 'thresholds' => $t->toArray(), 'days' => $days,
            'series' => $this->series($entries->all(), $from, $to),
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    /**
     * Uma media por dia para o grafico de vida (humor e energia).
     *
     * @param  list<MoodEntry>  $entries
     * @return list<array{date: string, mood: float|null, energy: float|null}>
     */
    private function series(array $entries, string $from, string $to): array
    {
        $by = [];
        foreach ($entries as $e) {
            $by[$e->entry_date->format('Y-m-d')][] = $e;
        }
        $out = [];
        for ($d = CarbonImmutable::parse($from); $d->lte(CarbonImmutable::parse($to)); $d = $d->addDay()) {
            $rows = $by[$d->toDateString()] ?? [];
            $m = array_values(array_filter(array_map(fn (MoodEntry $e) => $e->mood, $rows), fn ($v) => $v !== null));
            $en = array_values(array_filter(array_map(fn (MoodEntry $e) => $e->energy, $rows), fn ($v) => $v !== null));
            $out[] = ['date' => $d->toDateString(), 'mood' => $m === [] ? null : round(array_sum($m) / count($m), 2),
                'energy' => $en === [] ? null : round(array_sum($en) / count($en), 2)];
        }

        return $out;
    }

    /** Intervalo pedido (7/30/90 dias ou personalizado), limitado e validado. @return array{0: string, 1: string} */
    public static function range(User $u, ?string $preset, ?string $from, ?string $to): array
    {
        $today = CarbonImmutable::now($u->timezone ?: 'America/Sao_Paulo')->startOfDay();
        $valid = fn (?string $d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;
        if ($preset === 'personalizado' && $valid($from) && $valid($to)) {
            $f = CarbonImmutable::parse($from);
            $t = CarbonImmutable::parse($to);
            if ($f->gt($t)) {
                [$f, $t] = [$t, $f];
            }
            if ($f->diffInDays($t) > self::MAX_DAYS) {
                $f = $t->subDays(self::MAX_DAYS);
            }

            return [$f->toDateString(), $t->toDateString()];
        }
        $n = in_array($preset, ['7', '30', '90'], true) ? (int) $preset : 30;

        return [$today->subDays($n - 1)->toDateString(), $today->toDateString()];
    }
}
