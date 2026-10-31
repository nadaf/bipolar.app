<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Instalador/atualizador web para hospedagem sem SSH.
 * - So funciona se INSTALLER_TOKEN (>= 32 caracteres) estiver no .env.
 * - Compara o token em tempo constante; sem token valido => 404.
 * - APP_KEY e gerada antes do boot por App\Support\FirstRunKey. Roda as migrations (--force).
 * - Grava storage/app/installer.lock com a data; o usuario deve apagar INSTALLER_TOKEN depois.
 * Roda fora do grupo "web" (nao depende de sessao/APP_KEY).
 */
class InstallerController extends Controller
{
    private function enabled(): bool
    {
        return strlen((string) config('bipolar.installer_token')) >= 32;
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

        try {
            Artisan::call('migrate', ['--force' => true]);
            $out = Artisan::output();
        } catch (\Throwable $e) {
            report($e);

            return response()->view('installer', ['done' => null, 'error' => 'Falha ao criar as tabelas. Confira os dados do banco no arquivo .env.'], 500);
        }
        file_put_contents(storage_path('app/installer.lock'), now()->toIso8601String()."\n");

        return response()->view('installer', ['done' => trim($out) ?: 'Nada a atualizar.', 'error' => null]);
    }
}
