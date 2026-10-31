<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Care\GrantDirectory;
use App\Domain\Mood\Scales;
use App\Domain\Reports\ReportBuilder;
use App\Http\Controllers\Controller;
use App\Models\CareGrant;
use App\Models\MoodEntry;
use App\Models\User;
use Illuminate\Http\Request;

/** Visao do profissional: so pacientes que concederam acesso a ele. Somente leitura. */
class CarePatientController extends Controller
{
    public function index(Request $request, GrantDirectory $dir)
    {
        $grants = $dir->activeFor($request->user());
        $names = User::query()->whereIn('id', $grants->pluck('patient_id'))->pluck('name', 'id');

        return ['data' => $grants->map(fn (CareGrant $g) => [
            'patient_id' => $g->patient_id, 'name' => $names[$g->patient_id] ?? null,
            'scope_mask' => $g->scope_mask, 'expires_at' => $g->expires_at?->toIso8601String(),
        ])->values()];
    }

    public function entries(Request $request, GrantDirectory $dir, int $patientId)
    {
        $rows = $dir->moodEntries($request->user(), $patientId);
        abort_if($rows === null, 404);

        return ['data' => $rows->map(fn (MoodEntry $e) => [
            'entry_date' => $e->entry_date->format('Y-m-d'), 'period' => $e->period->value,
            'mood' => $e->mood, 'mood_label' => Scales::label(Scales::MOOD, $e->mood),
            'energy' => $e->energy, 'energy_label' => Scales::label(Scales::ENERGY, $e->energy),
            'sleep_hours' => $e->sleep_hours, 'medication_taken' => $e->medication_taken, 'note' => $e->note,
        ])->values()];
    }

    /** Relatorio do paciente filtrado pelas categorias concedidas. */
    public function report(Request $request, GrantDirectory $dir, int $patientId)
    {
        [$from, $to] = ReportBuilder::range($request->user(), (string) $request->query('periodo', '30'), $request->query('from'), $request->query('to'));
        $r = $dir->report($request->user(), $patientId, $from, $to);
        abort_if($r === null, 404);
        unset($r['grant']);

        return ['data' => $r];
    }
}
