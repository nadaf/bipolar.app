<?php

namespace App\Domain\Alerts;

use App\Models\AlertSetting;
use App\Models\AuditLog;
use App\Models\User;

/** Le/grava os limiares do paciente no tenant atual (cache por requisicao). */
final class AlertSettingsService
{
    /** @var array<int, AlertThresholds> */
    private array $cache = [];

    public function for(User $patient): AlertThresholds
    {
        return $this->cache[$patient->id] ??= $this->load($patient);
    }

    /** @param array{mood_low:int,mood_high:int,energy_low:int,energy_high:int} $v */
    public function update(User $patient, array $v): AlertThresholds
    {
        $s = AlertSetting::query()->where('patient_id', $patient->id)->first() ?? new AlertSetting(['patient_id' => $patient->id]);
        $s->fill($v)->save();
        AuditLog::create(['actor_id' => $patient->id, 'patient_id' => $patient->id, 'action' => 'alert_settings.updated',
            'subject_type' => 'alert_settings', 'subject_id' => $s->id]);
        unset($this->cache[$patient->id]);

        return $this->for($patient);
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'mood_low' => ['required', 'integer', 'between:-3,-1'],
            'mood_high' => ['required', 'integer', 'between:1,3'],
            'energy_low' => ['required', 'integer', 'between:-3,-1'],
            'energy_high' => ['required', 'integer', 'between:1,3'],
        ];
    }

    private function load(User $patient): AlertThresholds
    {
        $s = AlertSetting::query()->where('patient_id', $patient->id)->first();

        return $s === null ? new AlertThresholds
            : new AlertThresholds($s->mood_low, $s->mood_high, $s->energy_low, $s->energy_high);
    }
}
