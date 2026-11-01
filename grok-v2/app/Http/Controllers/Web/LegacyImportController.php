<?php

namespace App\Http\Controllers\Web;

use App\Domain\Legacy\LegacyImporter;
use App\Domain\Legacy\LegacyReader;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * /migrar-dados: copia os dados do Bipolar 0.5 (banco antigo, so leitura) para o 2.0.
 * - So roda pelo botao (POST) com o INSTALLER_TOKEN certo. Nunca roda sozinho na instalacao/atualizacao.
 * - Sem as linhas OLD_DB_ no .env: mostra uma mensagem e nao faz nada.
 * - Pode ser repetido: o que ja foi copiado e pulado. Cada clique trabalha ~20 s e para (hospedagem compartilhada).
 * - A tela mostra so contagens e avisos, nunca conteudo.
 * Fora do grupo "web" como o instalador (sem sessao).
 */
class LegacyImportController extends Controller
{
    private function token(): string
    {
        return (string) config('bipolar.installer_token');
    }

    /** @return array<string, mixed> */
    private function base(Request $request): array
    {
        $tokenOk = strlen($this->token()) >= 32;
        $message = null;
        if (! $tokenOk) {
            $message = 'A página está desligada. Para usar, coloque no .env a linha INSTALLER_TOKEN= com um código de 32 letras/números ou mais.';
        } elseif (! LegacyReader::configured()) {
            $message = 'Faltam as linhas OLD_DB_ no arquivo .env (dados do banco antigo). Veja o passo a passo no LEIA-ME. Nada foi feito.';
        } elseif (! LegacyImporter::ready()) {
            $message = 'O banco do 2.0 ainda não foi atualizado para esta versão. Aguarde a atualização automática ou abra /atualizar. Nada foi feito.';
        }

        return ['message' => $message, 'error' => null, 'report' => null, 'notCopied' => [],
            'token' => $tokenOk ? (string) $request->query('token', '') : ''];
    }

    public function form(Request $request, LegacyImporter $importer)
    {
        $v = $this->base($request);
        if ($v['message'] === null && hash_equals($this->token(), (string) $request->query('token'))) {
            $v['report'] = $importer->report(); // so leitura do 2.0, mostra progresso de rodadas anteriores
        }

        return response()->view('legacy-import', $v)->header('Referrer-Policy', 'no-referrer');
    }

    public function run(Request $request, LegacyReader $old, LegacyImporter $importer)
    {
        $v = $this->base($request);
        $v['token'] = '';
        if ($v['message'] !== null) {
            return response()->view('legacy-import', $v);
        }
        if (! hash_equals($this->token(), (string) $request->input('token'))) {
            usleep(random_int(800_000, 1_500_000));
            $v['error'] = 'Código incorreto. Use o mesmo código do INSTALLER_TOKEN do .env.';

            return response()->view('legacy-import', $v, 403);
        }
        $v['token'] = (string) $request->input('token');
        if ($problem = $old->problem()) {
            $v['error'] = $problem;

            return response()->view('legacy-import', $v, 409);
        }
        @set_time_limit(90);
        try {
            $v['report'] = $importer->run(20.0);
            if ($v['report']['done']) {
                $v['notCopied'] = $importer->notCopied();
            }
        } catch (\Throwable $e) {
            report($e);
            $v['error'] = 'Parou no meio por um erro. O que já foi copiado está salvo. Clique de novo para continuar; se repetir, me mande o arquivo storage/logs/laravel.log.';
            $v['report'] = $importer->report();
        }

        return response()->view('legacy-import', $v);
    }
}
