<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Account\StepUp;
use App\Domain\Care\CareGrantService;
use App\Domain\Day\StepUpRequired;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CareGrant;
use App\Models\User;
use Illuminate\Http\Request;

/** Concessoes emitidas pelo PROPRIO paciente (tenant dele). Fora do alcance => 404. */
class CareGrantController extends Controller
{
    public function index(Request $request, CareGrantService $svc)
    {
        $grants = $svc->list($request->user());
        $names = User::query()->whereIn('id', $grants->pluck('grantee_user_id')->filter())->pluck('name', 'id');

        return ['data' => $grants->map(fn (CareGrant $g) => CareGrantService::toApi($g, $names[$g->grantee_user_id] ?? null))->values()];
    }

    public function store(Request $request, CareGrantService $svc, StepUp $stepUp)
    {
        $v = $request->validate(CareGrantService::rules(true));
        if (CareGrantService::needsStepUp(CareGrantService::mask($v['scope'])) && ! $stepUp->active($request)) {
            throw new StepUpRequired;
        }
        [$g, $token] = $svc->invite($request->user(), $v);

        return response()->json(['grant' => CareGrantService::toApi($g), 'invite_token' => $token,
            'invite_url' => url('/convite/'.$token)], 201);
    }

    public function update(Request $request, CareGrantService $svc, StepUp $stepUp, int $id)
    {
        $g = $svc->find($request->user(), $id);
        abort_if($g === null, 404);
        $v = $request->validate(CareGrantService::rules(false));
        if (isset($v['scope']) && CareGrantService::needsStepUp(CareGrantService::mask($v['scope']) & ~$g->scope_mask) && ! $stepUp->active($request)) {
            throw new StepUpRequired;
        }

        return ['data' => CareGrantService::toApi($svc->update($request->user(), $g, $v))];
    }

    public function destroy(Request $request, CareGrantService $svc, int $id)
    {
        $g = $svc->find($request->user(), $id);
        abort_if($g === null, 404);
        $svc->revoke($request->user(), $g);

        return response()->noContent();
    }

    public function audit(Request $request, CareGrantService $svc, int $id)
    {
        $g = $svc->find($request->user(), $id);
        abort_if($g === null, 404);
        $names = User::query()->whereIn('id', $svc->audit_log($request->user(), $g)->pluck('actor_id')->filter())->pluck('name', 'id');

        return ['data' => $svc->audit_log($request->user(), $g)->map(fn (AuditLog $a) => [
            'action' => $a->getAttribute('action'), 'label' => CareGrantService::ACTION_LABELS[$a->getAttribute('action')] ?? $a->getAttribute('action'),
            'actor_name' => $names[$a->getAttribute('actor_id')] ?? null, 'at' => $a->getAttribute('created_at')?->toIso8601String()])->values()];
    }
}
