<?php

namespace App\Domain\Push;

use App\Models\AlertSetting;
use App\Models\PushSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Le a grade de lembretes de cada paciente (no fuso dele) e manda o push dos horarios vencidos
 * nos ultimos $window minutos. Um envio por aparelho, dia e periodo (last_slot). Texto sempre neutro.
 */
final class ReminderDispatcher
{
    public function __construct(private PushSender $sender, private TenantContext $ctx) {}

    /** @return array{due: int, sent: int, expired: int} */
    public function run(?CarbonImmutable $now = null, int $window = 10): array
    {
        $now ??= CarbonImmutable::now();
        $out = ['due' => 0, 'sent' => 0, 'expired' => 0];
        if (! $this->sender->enabled()) {
            return $out;
        }
        foreach (Tenant::query()->pluck('id') as $tid) {
            $this->ctx->run((int) $tid, function () use ($now, $window, &$out) {
                $users = PushSubscription::query()->distinct()->pluck('user_id');
                if ($users->isEmpty()) {
                    return;
                }
                $settings = AlertSetting::query()->whereIn('patient_id', $users)->get()->keyBy('patient_id');
                $tz = User::query()->whereIn('id', $users)->pluck('timezone', 'id');
                foreach ($users as $uid) {
                    $slot = $this->dueSlot($settings->get($uid)?->getAttribute('reminders'), (string) ($tz[$uid] ?? 'America/Sao_Paulo'), $now, $window);
                    if ($slot === null) {
                        continue;
                    }
                    $subs = PushSubscription::query()->where('user_id', $uid)
                        ->where(fn ($q) => $q->whereNull('last_slot')->orWhere('last_slot', '!=', $slot))->get()->all();
                    if ($subs === []) {
                        continue;
                    }
                    $out['due'] += count($subs);
                    $expired = $this->sender->send($subs);
                    foreach ($subs as $s) {
                        if (in_array($s->id, $expired, true)) {
                            $s->delete();
                            $out['expired']++;
                        } else {
                            $s->forceFill(['last_slot' => $slot])->save();
                            $out['sent']++;
                        }
                    }
                }
            });
        }

        return $out;
    }

    /** Chave "AAAA-MM-DD|periodo" do horario vencido agora, ou null. */
    public function dueSlot(mixed $reminders, string $tz, CarbonImmutable $now, int $window): ?string
    {
        if (! is_array($reminders) || ! is_array($reminders['days'] ?? null)) {
            return null;
        }
        try {
            $local = $now->setTimezone($tz);
        } catch (\Throwable) {
            $local = $now->setTimezone('America/Sao_Paulo');
        }
        foreach ($reminders['days'] as $d) {
            if ((int) ($d['weekday'] ?? -1) !== $local->dayOfWeek) {
                continue;
            }
            foreach ($d['slots'] ?? [] as $s) {
                if (! in_array($s['channel'] ?? '', ['push', 'ambos'], true) || ! preg_match('/^(\d\d):(\d\d)$/', (string) ($s['time'] ?? ''), $m)) {
                    continue;
                }
                $at = $local->setTime((int) $m[1], (int) $m[2]);
                $diff = $at->diffInMinutes($local, false);
                if ($diff >= 0 && $diff < $window) {
                    return $local->toDateString().'|'.$s['period'];
                }
            }
        }

        return null;
    }
}
