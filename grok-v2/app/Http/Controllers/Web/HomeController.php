<?php

namespace App\Http\Controllers\Web;

use App\Domain\Account\StepUp;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HomeController extends Controller
{
    /** Pagina inicial conforme o papel no tenant resolvido. */
    public function home(Request $request)
    {
        $m = $request->attributes->get('membership');

        return redirect(match ($m instanceof Membership ? $m->role : null) {
            Membership::PATIENT => route('diary.today'),
            Membership::ADMIN => route('clinic'),
            default => route('care.index'),
        });
    }

    /** Preferencia de tema (o botao ja aplicou no aparelho). */
    public function theme(Request $request)
    {
        $v = $request->validate(['theme' => ['required', 'in:sistema,claro,escuro']]);
        $request->user()->forceFill(['theme' => $v['theme']])->save();

        return $request->expectsJson() ? response()->noContent() : back();
    }

    /** Desbloqueio extra (PIN ou senha) por 10 minutos nesta sessao. */
    public function unlock(Request $request, StepUp $stepUp)
    {
        $v = $request->validate(['method' => ['required', 'in:pin,password'], 'secret' => ['required', 'string', 'max:200'], 'voltar' => ['nullable', 'string', 'max:300']]);
        if (! $stepUp->check($request->user(), $v['method'], $v['secret'])) {
            throw ValidationException::withMessages(['secret' => $v['method'] === 'pin' ? 'PIN incorreto.' : 'Senha incorreta.']);
        }
        $stepUp->markSession($request);

        return redirect($this->safeBack($request, $v['voltar'] ?? null))->with('ok', 'Desbloqueado por 10 minutos.');
    }

    public function lock(Request $request, StepUp $stepUp)
    {
        $stepUp->forget($request);

        return redirect($this->safeBack($request, $request->input('voltar')))->with('ok', 'Bloqueado de novo.');
    }

    /** So caminhos locais (evita redirecionamento aberto). */
    private function safeBack(Request $request, ?string $to): string
    {
        $to = (string) $to;
        if ($to === '') {
            return url()->previous('/');
        }
        $path = parse_url($to, PHP_URL_PATH);
        $host = parse_url($to, PHP_URL_HOST);
        if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//') || ($host !== null && $host !== $request->getHost())) {
            return '/';
        }
        $q = parse_url($to, PHP_URL_QUERY);
        $f = parse_url($to, PHP_URL_FRAGMENT);

        return $path.($q ? '?'.$q : '').($f ? '#'.$f : '');
    }
}
