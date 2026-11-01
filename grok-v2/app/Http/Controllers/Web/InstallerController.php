<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\EnvVapid;
use App\Support\LegacyDbGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Instalador/atualizador web para hospedagem sem SSH.
 * - So funciona se INSTALLER_TOKEN (>= 32 caracteres) estiver no .env.
 * - Compara o token em tempo constante; sem token valido => 404.
 * - APP_KEY e gerada antes do boot por App\Support\FirstRunKey. Roda as migrations (--force).
 * - Uso unico: grava storage/app/installer.lock com o hash do token; o MESMO token nao abre de novo
 *   (404). Para a proxima atualizacao, coloque um codigo NOVO em INSTALLER_TOKEN.
 * Roda fora do grupo "web" (nao depende de sessao/APP_KEY).
 */
class InstallerController extends Controller
{
    private function enabled(): bool
    {
        $token = (string) config('bipolar.installer_token');
        if (strlen($token) < 32) {
            return false;
        }
        $lock = storage_path('app/installer.lock');
        $used = is_file($lock) ? (string) file_get_contents($lock) : '';

        return ! str_contains($used, hash('sha256', $token));
    }

    public function form()
    {
        abort_unless($this->enabled(), 404);

        return response()->view('installer', ['done' => null, 'error' => null]);
    }

    public function run(Request $request)
    {
        abort_unless($this->enabled(), 404);
        if (! hash_equals((string) config('bipolar.installer_token'), (string) $request->input('token'))) {
            usleep(random_int(800_000, 1_500_000)); // freia forca bruta sem depender de cache

            return response()->view('installer', ['done' => null, 'error' => 'Código de instalação incorreto.'], 403);
        }

        if ($problem = LegacyDbGuard::problem()) {
            return response()->view('installer', ['done' => null, 'error' => $problem], 409);
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            $out = Artisan::output();
        } catch (\Throwable $e) {
            report($e);

            return response()->view('installer', ['done' => null, 'error' => 'Falha ao criar as tabelas. Confira os dados do banco no arquivo .env.'], 500);
        }
        try {
            // chaves de notificacao (so se vazias; a privada nunca aparece na tela)
            if (! app()->runningUnitTests() && EnvVapid::ensure(app()->environmentFilePath())) {
                $out = trim($out)."\nChaves de notificação criadas no .env.";
            }
        } catch (\Throwable $e) {
            report($e);
        }
        file_put_contents(storage_path('app/installer.lock'), now()->toIso8601String().' '.hash('sha256', (string) config('bipolar.installer_token'))."\n", FILE_APPEND);

        return response()->view('installer', ['done' => trim($out) ?: 'Nada a atualizar.', 'error' => null]);
    }
}
