<?php

namespace App\Domain\Day;

use App\Domain\Mood\MoodEntryService;
use App\Domain\Mood\Period;
use App\Models\AuditLog;
use App\Models\DayBlock;
use App\Models\MoodEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Regra unica dos blocos do Registrar (API e tela). Nunca recebe tenant/patient do cliente. */
final class DayBlockService
{
    public function __construct(private MoodEntryService $mood) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(User $patient, string $date, string $period, string $block, array $data, ?int $expectedVersion, bool $steppedUp): DayBlock
    {
        if (! Blocks::exists($block) || ! Blocks::enabledFor($block, $patient->modules)) {
            throw new NotFoundHttpException;
        }
        if (Blocks::stepUp($block) && ! $steppedUp) {
            throw new StepUpRequired;
        }
        if (Blocks::perPeriod($block)) {
            $p = Period::tryFrom($period) ?? throw ValidationException::withMessages(['period' => 'Período inválido.']);
            $this->mood->assertPeriodOpen($patient, $date, $p);
        } else {
            $period = 'dia';
            $this->mood->assertPeriodOpen($patient, $date, Period::Manha);
        }
        $clean = Blocks::normalize($block, $data);

        return DB::transaction(function () use ($patient, $date, $period, $block, $clean, $expectedVersion) {
            $row = DayBlock::query()->ownedBy($patient)->where('entry_date', $date)
                ->where('period', $period)->where('block', $block)->lockForUpdate()->first();
            if ($row === null) {
                $row = new DayBlock(['patient_id' => $patient->id, 'entry_date' => $date, 'period' => $period, 'block' => $block]);
                $action = 'day_block.created';
            } else {
                if ($expectedVersion !== null && $expectedVersion !== $row->version) {
                    throw new ConflictHttpException('Este bloco foi alterado em outro aparelho.');
                }
                $row->version = $row->version + 1;
                $action = 'day_block.updated';
            }
            $row->data = $clean;
            $row->save();
            // auditoria sem conteudo (nunca grava o que foi escrito)
            AuditLog::create(['actor_id' => $patient->id, 'patient_id' => $patient->id, 'action' => $action,
                'subject_type' => 'day_block:'.$block, 'subject_id' => $row->id]);

            return $row;
        });
    }

    /**
     * Tudo de um dia do proprio paciente. Blocos com desbloqueio so aparecem se $steppedUp.
     *
     * @return array{entries: Collection<int, MoodEntry>, blocks: Collection<int, DayBlock>, locked: list<string>}
     */
    public function day(User $patient, string $date, bool $steppedUp): array
    {
        $entries = MoodEntry::query()->ownedBy($patient)->where('entry_date', $date)->get();
        $blocks = DayBlock::query()->ownedBy($patient)->where('entry_date', $date)->get();
        $locked = [];
        if (! $steppedUp) {
            $locked = $blocks->filter(fn (DayBlock $b) => Blocks::stepUp($b->block))->pluck('block')->unique()->values()->all();
            $blocks = $blocks->reject(fn (DayBlock $b) => Blocks::stepUp($b->block))->values();
        }

        return ['entries' => $entries, 'blocks' => $blocks, 'locked' => $locked];
    }
}
