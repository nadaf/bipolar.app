<?php

namespace App\Http\Controllers\Web;

use App\Domain\Account\StepUp;
use App\Domain\Care\CareGrantService;
use App\Http\Controllers\Controller;
use App\Models\CareGrant;
use App\Models\User;
use Illuminate\Http\Request;

/** Compartilhar: o paciente convida, escolhe categorias, prazo, revoga e ve quem acessou. */
class ShareController extends Controller
{
    public function index(Request $request, CareGrantService $svc)
    {
        $grants = $svc->list($request->user());
        $names = User::query()->whereIn('id', $grants->pluck('grantee_user_id')->filter())->pluck('name', 'id');

        return view('share.index', ['grants' => $grants, 'names' => $names, 'kinds' => CareGrantService::KINDS,
            'scopes' => CareGrantService::SCOPE_LABELS, 'sensitive' => $this->sensitiveNames(), 'link' => session('convite')]);
    }

    public function store(Request $request, CareGrantService $svc, StepUp $stepUp)
    {
        $v = $request->validate(CareGrantService::rules(true), ['scope.required' => 'Escolha ao menos uma categoria.']);
        if (CareGrantService::needsStepUp(CareGrantService::mask($v['scope'])) && ! $stepUp->active($request)) {
            return back()->withInput()->withErrors(['scope' => 'Categorias privadas exigem desbloqueio (PIN ou senha) antes.']);
        }
        [$g, $token] = $svc->invite($request->user(), $v);

        return redirect()->route('share.show', $g->id)->with('convite', url('/convite/'.$token));
    }

    public function show(Request $request, CareGrantService $svc, int $id)
    {
        $g = $this->grant($request, $svc, $id);
        $name = $g->getAttribute('grantee_user_id') ? User::query()->whereKey($g->getAttribute('grantee_user_id'))->value('name') : null;

        return view('share.show', ['g' => $g, 'name' => $name, 'status' => CareGrantService::status($g), 'kinds' => CareGrantService::KINDS,
            'scopes' => CareGrantService::SCOPE_LABELS, 'sensitive' => $this->sensitiveNames(), 'current' => CareGrantService::names($g->scope_mask),
            'log' => $svc->audit_log($request->user(), $g), 'link' => session('convite')]);
    }

    public function update(Request $request, CareGrantService $svc, StepUp $stepUp, int $id)
    {
        $g = $this->grant($request, $svc, $id);
        $v = $request->validate(CareGrantService::rules(false) + ['scope' => ['required', 'array', 'min:1']], ['scope.required' => 'Escolha ao menos uma categoria.']);
        $newMask = CareGrantService::mask($v['scope']);
        if (CareGrantService::needsStepUp($newMask & ~$g->scope_mask) && ! $stepUp->active($request)) {
            return back()->withErrors(['scope' => 'Para liberar categorias privadas, desbloqueie (PIN ou senha) antes.']);
        }
        $svc->update($request->user(), $g, $v);

        return redirect()->route('share.show', $g->id)->with('ok', 'Permissões atualizadas.');
    }

    public function revoke(Request $request, CareGrantService $svc, int $id)
    {
        $g = $this->grant($request, $svc, $id);
        $svc->revoke($request->user(), $g);

        return redirect()->route('share')->with('ok', 'Acesso revogado.');
    }

    private function grant(Request $request, CareGrantService $svc, int $id): CareGrant
    {
        $g = $svc->find($request->user(), $id);
        abort_if($g === null, 404);

        return $g;
    }

    /** @return list<string> */
    private function sensitiveNames(): array
    {
        return array_keys(array_filter(CareGrantService::SCOPE_NAMES, fn (int $b) => CareGrantService::needsStepUp($b)));
    }
}
