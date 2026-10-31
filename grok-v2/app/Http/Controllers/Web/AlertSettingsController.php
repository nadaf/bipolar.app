<?php

namespace App\Http\Controllers\Web;

use App\Domain\Alerts\AlertSettingsService;
use App\Domain\Mood\Period;
use App\Domain\Mood\Scales;
use App\Domain\Push\PushSubscriptionService;
use App\Domain\Reminders\ReminderService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AlertSettingsController extends Controller
{
    public function edit(Request $request, AlertSettingsService $svc, ReminderService $rem)
    {
        $grid = $rem->get($request->user());
        $cells = [];
        $times = ReminderService::DEFAULT_TIMES;
        $channel = 'push';
        foreach ($grid['days'] as $d) {
            foreach ($d['slots'] as $s) {
                $cells[$d['weekday'].'-'.$s['period']] = true;
                $times[$s['period']] = $s['time'];
                $channel = $s['channel'];
            }
        }

        return view('settings.alerts', ['t' => $svc->for($request->user())->toArray(), 'mood' => Scales::MOOD, 'energy' => Scales::ENERGY,
            'vapid' => (string) config('bipolar.vapid.public'), 'devices' => app(PushSubscriptionService::class)->count($request->user()),
            'cells' => $cells, 'times' => $times, 'channel' => $channel, 'periods' => Period::cases()]);
    }

    public function update(Request $request, AlertSettingsService $svc)
    {
        $v = $request->validate(AlertSettingsService::rules());
        $svc->update($request->user(), array_map('intval', $v));

        return $request->header('HX-Request') ? view('settings._alerts_saved') : redirect()->route('alerts')->with('ok', 'Limiares salvos.');
    }

    public function reminders(Request $request, ReminderService $rem)
    {
        $v = $request->validate(['cel' => ['sometimes', 'array'], 'cel.*' => ['in:1'], 'hora' => ['sometimes', 'array'],
            'hora.*' => ['nullable', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'], 'canal' => ['required', 'in:push,email,ambos']]);
        $rem->put($request->user(), ReminderService::fromCells($v['cel'] ?? [], $v['hora'] ?? [], $v['canal']));

        return redirect()->route('alerts')->with('ok', 'Grade de lembretes salva.');
    }
}
