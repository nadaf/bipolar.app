<?php

namespace App\Domain\Reminders;

use App\Domain\Mood\Period;
use App\Models\AlertSetting;
use App\Models\AuditLog;
use App\Models\User;

/**
 * Grade semanal de lembretes (formato ReminderGrid do OpenAPI). Nesta versao a grade e so salva;
 * o ENVIO (push/e-mail) fica para a proxima etapa.
 */
final class ReminderService
{
    public const WEEKDAYS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    public const WEEKDAYS_LONG = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

    public const DEFAULT_TIMES = ['manha' => '09:00', 'tarde' => '15:00', 'noite' => '21:00'];

    public const CHANNELS = ['push' => 'Notificação no aparelho', 'email' => 'E-mail', 'ambos' => 'Os dois'];

    /** @return array{timezone: string, days: list<array{weekday: int, slots: list<array{time: string, period: string, channel: string}>}>} */
    public function get(User $patient): array
    {
        $s = AlertSetting::query()->where('patient_id', $patient->id)->first();
        $days = $s?->getAttribute('reminders')['days'] ?? null;
        if (! is_array($days) || count($days) !== 7) {
            $days = array_map(fn ($w) => ['weekday' => $w, 'slots' => []], range(0, 6));
        }

        return ['timezone' => $patient->timezone, 'days' => array_values($days)];
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'days' => ['required', 'array', 'size:7'],
            'days.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'],
            'days.*.slots' => ['present', 'array', 'max:6'],
            'days.*.slots.*.time' => ['required', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],
            'days.*.slots.*.period' => ['required', 'in:manha,tarde,noite'],
            'days.*.slots.*.channel' => ['required', 'in:push,email,ambos'],
        ];
    }

    /** @param list<array{weekday: int, slots: list<array<string, string>>}> $days */
    public function put(User $patient, array $days): array
    {
        usort($days, fn ($a, $b) => (int) $a['weekday'] <=> (int) $b['weekday']);
        $clean = array_map(fn ($d) => ['weekday' => (int) $d['weekday'], 'slots' => array_map(
            fn ($s) => ['time' => (string) $s['time'], 'period' => (string) $s['period'], 'channel' => (string) $s['channel']], $d['slots'])], $days);
        $s = AlertSetting::query()->where('patient_id', $patient->id)->first() ?? new AlertSetting(['patient_id' => $patient->id]);
        $s->setAttribute('reminders', ['days' => $clean]);
        $s->save();
        AuditLog::create(['actor_id' => $patient->id, 'patient_id' => $patient->id, 'action' => 'reminders.updated', 'subject_type' => 'alert_settings', 'subject_id' => $s->id]);

        return $this->get($patient);
    }

    /**
     * Converte a grade de celulas da tela (dia x periodo marcados) para o formato da API.
     *
     * @param  array<int|string, mixed>  $cells  ex.: ['1-manha' => '1']
     * @return list<array{weekday: int, slots: list<array{time: string, period: string, channel: string}>}>
     */
    public static function fromCells(array $cells, array $times, string $channel): array
    {
        $days = [];
        foreach (range(0, 6) as $w) {
            $slots = [];
            foreach (Period::cases() as $p) {
                if (! empty($cells[$w.'-'.$p->value])) {
                    $t = (string) ($times[$p->value] ?? self::DEFAULT_TIMES[$p->value]);
                    $slots[] = ['time' => preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $t) ? $t : self::DEFAULT_TIMES[$p->value],
                        'period' => $p->value, 'channel' => $channel];
                }
            }
            $days[] = ['weekday' => $w, 'slots' => $slots];
        }

        return $days;
    }
}
