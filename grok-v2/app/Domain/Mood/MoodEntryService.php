<?php

namespace App\Domain\Mood;

use App\Models\AuditLog;
use App\Models\MoodEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Regra unica usada pela API e pela tela HTMX. */
final class MoodEntryService
{
    /**
     * Cria ou atualiza o registro do (dia, periodo) travado pelo cliente.
     * Trava de periodo: data/periodo vem explicitos do cliente; o servidor
     * recusa periodo futuro (no fuso do paciente). Dias passados: sem limite.
     * Concorrencia otimista: se $expectedVersion vier e nao bater => 409.
     */
    public function upsert(User $patient, array $data, ?int $expectedVersion = null): MoodEntry
    {
        $period = Period::from($data['period']);
        $this->assertPeriodOpen($patient, $data['entry_date'], $period);

        return DB::transaction(function () use ($patient, $data, $period, $expectedVersion) {
            $entry = MoodEntry::query()->ownedBy($patient)
                ->where('entry_date', $data['entry_date'])->where('period', $period->value)
                ->lockForUpdate()->first();

            if ($entry === null) {
                $entry = new MoodEntry(['patient_id' => $patient->id]);
                $action = 'mood_entry.created';
            } else {
                if ($expectedVersion !== null && $expectedVersion !== $entry->version) {
                    throw new ConflictHttpException('Registro alterado em outro aparelho.');
                }
                $entry->version = $entry->version + 1;
                $action = 'mood_entry.updated';
            }
            $entry->fill($data);
            $entry->save();

            AuditLog::create(['actor_id' => $patient->id, 'patient_id' => $patient->id, 'action' => $action,
                'subject_type' => 'mood_entry', 'subject_id' => $entry->id]);

            return $entry;
        });
    }

    public function assertPeriodOpen(User $patient, string $date, Period $period): void
    {
        $now = CarbonImmutable::now($patient->timezone ?: 'America/Sao_Paulo');
        $start = CarbonImmutable::parse($date, $now->timezone)->setTime($period->startsAtHour(), 0);
        if ($start->greaterThan($now)) {
            throw ValidationException::withMessages(['period' => 'Este período ainda não começou.']);
        }
    }
}
