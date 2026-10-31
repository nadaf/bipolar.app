<?php

namespace App\Http\Resources;

use App\Domain\Alerts\AlertSettingsService;
use App\Domain\Alerts\AlertThresholds;
use App\Domain\Mood\Scales;
use App\Domain\Mood\SignalClassifier;
use App\Models\MoodEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MoodEntry */
class MoodEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'entry_date' => $this->entry_date->format('Y-m-d'),
            'period' => $this->period->value,
            'period_label' => $this->period->label(),
            'mood' => $this->mood,
            'mood_label' => Scales::label(Scales::MOOD, $this->mood),
            'energy' => $this->energy,
            'energy_label' => Scales::label(Scales::ENERGY, $this->energy),
            'anxiety' => $this->anxiety,
            'irritability' => $this->irritability,
            'sleep_hours' => $this->sleep_hours,
            'medication_taken' => $this->medication_taken,
            'note' => $this->note,
            'signal' => SignalClassifier::classify($this->mood, $this->energy, $this->thresholds($request)),
            'client_uuid' => $this->client_uuid,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Limiares do dono do registro (o paciente). So o proprio paciente ve seus registros aqui. */
    private function thresholds(Request $request): ?AlertThresholds
    {
        $u = $request->user();

        return $u !== null && $u->id === $this->patient_id ? app(AlertSettingsService::class)->for($u) : null;
    }
}
