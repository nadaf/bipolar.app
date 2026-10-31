<?php

namespace App\Http\Controllers\Web;

use App\Domain\Clinic\ClinicInvites;
use App\Domain\Clinic\ClinicProfessionals;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Http\Request;

class ClinicController extends Controller
{
    public function index(ClinicProfessionals $c, ClinicInvites $inv)
    {
        $id = $c->clinicId();
        abort_if($id === null, 404);

        return view('clinic.index', ['pros' => $c->list($id), 'invites' => $inv->pending(), 'clinic' => Tenant::query()->findOrFail($id)]);
    }

    /** Convite por e-mail (a pessoa aceita pelo link; nada de vinculo sem consentimento). */
    public function add(Request $request, ClinicProfessionals $c, ClinicInvites $inv)
    {
        $id = $c->clinicId();
        abort_if($id === null, 404);
        $v = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);
        [$invite] = $inv->invite($request->user(), $id, $v['email']);
        if ($invite === null) {
            return redirect()->route('clinic')->with('ok', 'Essa pessoa já faz parte da clínica.');
        }

        return redirect()->route('clinic')->with('ok', 'Convite enviado para '.$invite->email.'. Vale por 7 dias.');
    }

    public function revokeInvite(Request $request, ClinicProfessionals $c, ClinicInvites $inv, int $invite)
    {
        abort_if($c->clinicId() === null || ! $inv->revoke($request->user(), $invite), 404);

        return redirect()->route('clinic')->with('ok', 'Convite cancelado. O link não vale mais.');
    }

    public function security(Request $request, ClinicProfessionals $c)
    {
        $id = $c->clinicId();
        abort_if($id === null, 404);
        $on = $request->boolean('require_mfa');
        Tenant::query()->whereKey($id)->update(['require_mfa' => $on]);
        AuditLog::create(['actor_id' => $request->user()->id, 'action' => $on ? 'clinic.mfa_required' : 'clinic.mfa_optional', 'subject_type' => 'tenant', 'subject_id' => $id]);

        return redirect()->route('clinic')->with('ok', $on ? 'Verificação em duas etapas obrigatória para a equipe.' : 'Verificação em duas etapas opcional para a equipe.');
    }

    public function remove(Request $request, ClinicProfessionals $c, int $user)
    {
        $id = $c->clinicId();
        abort_if($id === null || ! $c->remove($request->user(), $id, $user), 404);

        return redirect()->route('clinic')->with('ok', 'Vínculo removido.');
    }
}
