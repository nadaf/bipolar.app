<?php

namespace App\Http\Controllers\Web;

use App\Domain\Clinic\ClinicProfessionals;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ClinicController extends Controller
{
    public function index(ClinicProfessionals $c)
    {
        $id = $c->clinicId();
        abort_if($id === null, 404);

        return view('clinic.index', ['pros' => $c->list($id)]);
    }

    public function add(Request $request, ClinicProfessionals $c)
    {
        $id = $c->clinicId();
        abort_if($id === null, 404);
        $v = $request->validate(['email' => ['required', 'email', 'max:190']]);
        [$user, $created] = $c->add($request->user(), $id, $v['email']);
        if ($user === null) {
            return back()->withInput()->withErrors(['email' => 'Não encontramos uma conta profissional com esse e-mail.']);
        }

        return redirect()->route('clinic')->with('ok', $created ? 'Profissional vinculado(a).' : 'Essa pessoa já faz parte da clínica.');
    }

    public function remove(Request $request, ClinicProfessionals $c, int $user)
    {
        $id = $c->clinicId();
        abort_if($id === null || ! $c->remove($request->user(), $id, $user), 404);

        return redirect()->route('clinic')->with('ok', 'Vínculo removido.');
    }
}
