<?php

namespace App\Http\Controllers\Web;

use App\Domain\Push\PushSubscriptionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Inscrever/cancelar notificacoes deste aparelho (so do proprio usuario, no tenant do contexto). */
class PushController extends Controller
{
    public function subscribe(Request $request, PushSubscriptionService $svc): JsonResponse
    {
        $v = $request->validate(PushSubscriptionService::rules());
        $svc->subscribe($request->user(), $v['endpoint'], $v['keys']['p256dh'], $v['keys']['auth']);

        return response()->json(['ok' => true, 'devices' => $svc->count($request->user())], 201);
    }

    public function unsubscribe(Request $request, PushSubscriptionService $svc): JsonResponse
    {
        $v = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        $svc->unsubscribe($request->user(), $v['endpoint']);

        return response()->json(['ok' => true, 'devices' => $svc->count($request->user())]);
    }
}
