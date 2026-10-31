<?php

namespace App\Http\Controllers\Web;

use App\Domain\Care\CareGrantService;
use App\Domain\Care\GrantDirectory;
use App\Domain\Reports\ReportBuilder;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/** Visao de quem cuida (profissional ou familiar): somente leitura, filtrada pelas categorias concedidas. */
class CareController extends Controller
{
    public function index(Request $request, GrantDirectory $dir)
    {
        $grants = $dir->activeFor($request->user());
        $names = User::query()->whereIn('id', $grants->pluck('patient_id'))->pluck('name', 'id');

        return view('care.index', ['grants' => $grants, 'names' => $names, 'scopes' => CareGrantService::SCOPE_LABELS]);
    }

    public function show(Request $request, GrantDirectory $dir, int $patient)
    {
        $u = $request->user();
        $preset = (string) $request->query('periodo', '30');
        [$from, $to] = ReportBuilder::range($u, $preset, $request->query('de'), $request->query('ate'));
        $r = $dir->report($u, $patient, $from, $to);
        abort_if($r === null, 404);

        return view('reports.index', ['r' => $r, 'preset' => in_array($preset, ['7', '30', '90', 'personalizado'], true) ? $preset : '30',
            'who' => $r['patient_name'], 'readOnly' => true,
            'granted' => array_map(fn ($n) => CareGrantService::SCOPE_LABELS[$n], CareGrantService::names($r['grant']->scope_mask))]);
    }

    public function invite(Request $request, string $token)
    {
        return view('care.invite', ['token' => $token]);
    }

    public function accept(Request $request, GrantDirectory $dir, string $token)
    {
        $g = $dir->accept($request->user(), $token);
        abort_if($g === null, 404);

        return redirect()->route('care.index')->with('ok', 'Convite aceito. Você tem acesso somente leitura.');
    }
}
