<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Account\IssueToken;
use App\Domain\Clinic\ClinicInvites;
use App\Domain\Clinic\ClinicProfessionals;
use App\Http\Controllers\Controller;
use App\Models\ClinicInvite;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** Convites de clinica: admin gerencia; o convidado aceita (conta existente) ou se cadastra pelo token. */
class ClinicInviteController extends Controller
{
    public function index(ClinicProfessionals $c, ClinicInvites $svc)
    {
        abort_if($c->clinicId() === null, 404);

        return ['data' => $svc->pending()->map(fn (ClinicInvite $i) => ['id' => $i->id, 'email' => $i->email, 'role' => $i->role,
            'expires_at' => $i->expires_at?->toIso8601String()])->values()];
    }

    public function store(Request $request, ClinicProfessionals $c, ClinicInvites $svc)
    {
        $id = $c->clinicId();
        abort_if($id === null, 404);
        [$inv] = $svc->invite($request->user(), $id, $request->validate(['email' => ['required', 'email:rfc', 'max:190']])['email']);
        if ($inv === null) {
            return response()->json(['message' => 'Essa pessoa já faz parte da clínica.'], 409);
        }

        return response()->json(['data' => ['id' => $inv->id, 'email' => $inv->email, 'expires_at' => $inv->expires_at?->toIso8601String()]], 201);
    }

    public function destroy(Request $request, ClinicProfessionals $c, ClinicInvites $svc, int $id)
    {
        abort_if($c->clinicId() === null || ! $svc->revoke($request->user(), $id), 404);

        return response()->noContent();
    }

    /** Publico (com o token): dados minimos para a tela de aceite do app. */
    public function show(ClinicInvites $svc, string $token)
    {
        $inv = $svc->find($token);
        abort_if($inv === null, 404);

        return ['data' => ['clinic' => $svc->clinicName($inv), 'email' => $inv->email, 'has_account' => User::query()->where('email', $inv->email)->exists()]];
    }

    /** Usuario autenticado (token de qualquer tenant) aceita: e-mail tem de ser o convidado. */
    public function accept(Request $request, ClinicInvites $svc, string $token)
    {
        $inv = $svc->find($token);
        abort_if($inv === null || ! $svc->accept($inv, $request->user()), 404);

        return ['data' => ['tenant_id' => $inv->tenant_id, 'clinic' => Tenant::query()->whereKey($inv->tenant_id)->value('name')]];
    }

    /** Conta nova pelo convite: devolve token ja amarrado a clinica. */
    public function register(Request $request, ClinicInvites $svc, IssueToken $issue, string $token)
    {
        $inv = $svc->find($token);
        abort_if($inv === null, 404);
        $v = $request->validate(['name' => ['required', 'string', 'min:2', 'max:150'], 'password' => ['required', Password::min(10)], 'device_name' => ['required', 'string', 'max:100']]);
        $u = $svc->register($inv, $v['name'], $v['password']);
        abort_if($u === null, 409, 'Já existe conta com esse e-mail: entre e aceite.');

        return response()->json(['token' => $issue($u, $v['device_name'], $inv->tenant_id)], 201);
    }
}
