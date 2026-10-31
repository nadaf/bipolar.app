<?php

namespace App\Http\Controllers\Web;

use App\Domain\Care\CareScope;
use App\Domain\Care\GrantDirectory;
use App\Domain\Export\RecordExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Http\Request;

/** Exportar registros (CSV/PDF). Paciente: os proprios. Profissional: paciente que concedeu acesso. */
class ExportController extends Controller
{
    public function form(Request $request)
    {
        $today = Dates::today($request->user()->timezone);

        return view('export.index', ['de' => $today->subDays(29)->toDateString(), 'ate' => $today->toDateString(),
            'action' => route('export.download'), 'who' => null]);
    }

    public function download(Request $request, RecordExport $x)
    {
        $v = $request->validate(RecordExport::rules());

        return self::file($x->own($request->user(), $v['formato'], $v['de'], $v['ate']), $v['formato']);
    }

    public function careForm(Request $request, GrantDirectory $dir, int $patient)
    {
        abort_if($dir->find($request->user(), $patient, CareScope::MOOD) === null, 404);
        $today = Dates::today($request->user()->timezone);

        return view('export.index', ['de' => $today->subDays(29)->toDateString(), 'ate' => $today->toDateString(),
            'action' => route('care.export.download', $patient), 'who' => User::query()->whereKey($patient)->value('name')]);
    }

    public function careDownload(Request $request, RecordExport $x, int $patient)
    {
        $v = $request->validate(RecordExport::rules());
        $out = $x->granted($request->user(), $patient, $v['formato'], $v['de'], $v['ate']);
        abort_if($out === null, 404);

        return self::file($out, $v['formato']);
    }

    public static function file(string $body, string $format)
    {
        return response($body, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.RecordExport::filename($format).'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
