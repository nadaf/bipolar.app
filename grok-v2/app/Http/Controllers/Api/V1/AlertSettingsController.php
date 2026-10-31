<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alerts\AlertSettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Alerta personalizado: o paciente escolhe a partir de onde humor/energia viram sinal. */
class AlertSettingsController extends Controller
{
    public function show(Request $request, AlertSettingsService $svc)
    {
        return ['data' => $svc->for($request->user())->toArray()];
    }

    public function update(Request $request, AlertSettingsService $svc)
    {
        $v = $request->validate(AlertSettingsService::rules());

        return ['data' => $svc->update($request->user(), array_map('intval', $v))->toArray()];
    }
}
