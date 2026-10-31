<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Clinic\ClinicProfessionals;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** API fina sobre App\Domain\Clinic\ClinicProfessionals (mesma regra da tela). */
class ClinicProfessionalController extends Controller
{
    public function __construct(private ClinicProfessionals $svc) {}

    public function index()
    {
        return ['data' => $this->svc->list($this->clinicId())];
    }

    public function store(Request $request)
    {
        $v = $request->validate(['email' => ['required', 'email']]);
        [$user, $created] = $this->svc->add($request->user(), $this->clinicId(), $v['email']);
        if ($user === null) {
            // convite por e-mail fica para a proxima etapa (sem envio automatico nesta versao)
            return response()->json(['message' => 'Peça para a pessoa criar a conta primeiro e tente de novo.'], 422);
        }

        return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]], $created ? 201 : 200);
    }

    public function destroy(Request $request, int $userId)
    {
        abort_unless($this->svc->remove($request->user(), $this->clinicId(), $userId), 404);

        return response()->noContent();
    }

    private function clinicId(): int
    {
        $id = $this->svc->clinicId();
        abort_if($id === null, 404);

        return $id;
    }
}
