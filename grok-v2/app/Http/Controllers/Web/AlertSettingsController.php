<?php

namespace App\Http\Controllers\Web;

use App\Domain\Alerts\AlertSettingsService;
use App\Domain\Mood\Scales;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AlertSettingsController extends Controller
{
    public function edit(Request $request, AlertSettingsService $svc)
    {
        return view('settings.alerts', ['t' => $svc->for($request->user())->toArray(), 'mood' => Scales::MOOD, 'energy' => Scales::ENERGY]);
    }

    public function update(Request $request, AlertSettingsService $svc)
    {
        $v = $request->validate(AlertSettingsService::rules());
        $svc->update($request->user(), array_map('intval', $v));

        return $request->header('HX-Request') ? view('settings._alerts_saved') : redirect('/ajustes/alertas');
    }
}
