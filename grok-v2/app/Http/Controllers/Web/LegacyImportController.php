<?php

namespace App\Http\Controllers\Web;

use App\Domain\Legacy\LegacyImporter;
use App\Domain\Legacy\LegacyReader;
use App\Domain\Legacy\LegacyRepair;
use App\Http\Controllers\Controller;
use App\Support\DataKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * /migrar-dados: copia os dados do Bipolar 0.5 (banco antigo, so leitura) para o 2.0 e conserta dados cifrados.
 * - So roda pelo botao (POST) com o INSTALLER_TOKEN certo. Nunca roda sozinho na instalacao/atualizacao.
 * - Sem as linhas OLD_DB_ no .env: mostra uma mensagem; "Consertar" continua disponivel.
 * - Qualquer erro vira uma mensagem com codigo curto e e registrado (tipo + mensagem, sem conteudo nem segredos).
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
        } elseif (! LegacyImporter::ready()) {
            $message = 'O banco do 2.0 ainda não foi atualizado para esta versão. Aguarde a atualização automática ou abra /atualizar. Nada foi feito.';
        }

        return ['message' => $message, 'error' => null, 'report' => null, 'notCopied' => [], 'repair' => null,
            'noOld' => ! LegacyReader::configured(), 'refreshing' => LegacyImporter::ready() && app(LegacyImporter::class)->refreshing(), 'appKeyProblem' => DataKeys::appKeyProblem(), 'oldKeyProblem' => DataKeys::oldKeyProblem(),
            'token' => $tokenOk ? (string) $request->query('token', '') : ''];
    }

    /** Executa e desenha a pagina; qualquer erro vira mensagem com codigo e vai para o log. */
    private function guard(callable $fn): Response
    {
        try {
            [$v, $status] = $fn();

            return response(view('legacy-import', $v)->render(), $status)->header('Referrer-Policy', 'no-referrer');
        } catch (\Throwable $e) {
            $code = strtoupper(Str::random(6));
            $this->logError($code, $e);
            $msg = 'Algo deu errado nesta página (código '.$code.'). O que já foi feito está salvo. '
                .'Me mande este código e o arquivo storage/logs/ mais recente.';

            return response('<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Trazer dados</title><main><h1>Trazer os dados da versão antiga</h1><p role="alert"><strong>'
                .e($msg).'</strong></p><p><a href="/migrar-dados">Voltar</a></p></main></html>', 500)->header('Referrer-Policy', 'no-referrer');
        }
    }

    /** Registra so tipo, mensagem (sem segredos) e local. Se o log falhar, usa o error_log do PHP. */
    private function logError(string $code, \Throwable $e): void
    {
        $ctx = ['code' => $code, 'type' => $e::class, 'message' => DataKeys::redact(mb_substr($e->getMessage(), 0, 300)),
            'at' => basename($e->getFile()).':'.$e->getLine()];
        try {
            Log::error('migrar-dados falhou', $ctx);
        } catch (\Throwable) {
        }
        error_log('[bipolar] migrar-dados falhou '.json_encode($ctx));
    }

    public function form(Request $request, LegacyImporter $importer, LegacyRepair $repair): Response
    {
        return $this->guard(function () use ($request, $importer, $repair) {
            $v = $this->base($request);
            if ($v['message'] === null && hash_equals($this->token(), (string) $request->query('token'))) {
                $v['report'] = $importer->report(); // so leitura do 2.0
                $v['repair'] = $repair->report();
            }

            return [$v, 200];
        });
    }

    public function run(Request $request, LegacyReader $old, LegacyImporter $importer, LegacyRepair $repair): Response
    {
        return $this->guard(function () use ($request, $old, $importer, $repair) {
            $v = $this->base($request);
            $v['token'] = '';
            if ($v['message'] !== null) {
                return [$v, 200];
            }
            if (! hash_equals($this->token(), (string) $request->input('token'))) {
                usleep(random_int(800_000, 1_500_000));
                $v['error'] = 'Código incorreto. Use o mesmo código do INSTALLER_TOKEN do .env.';

                return [$v, 403];
            }
            $v['token'] = (string) $request->input('token');
            $acao = (string) $request->input('acao', 'copiar');
            $keyBlock = $acao === 'recopiar' ? $v['appKeyProblem'] : ($v['appKeyProblem'] ?? $v['oldKeyProblem']);
            if ($keyBlock !== null) {
                $v['error'] = 'Nada foi feito: arrume primeiro o aviso sobre a chave acima.';
                $v['report'] = $importer->report();
                $v['repair'] = $repair->report();

                return [$v, 409];
            }
            @set_time_limit(90);
            if ($acao === 'consertar' || $acao === 'limpar') {
                $repair->restartIfDone();
                $v['repair'] = $repair->run(20.0, $acao === 'limpar');
                $v['report'] = $importer->report();

                return [$v, 200];
            }
            if ($v['noOld']) {
                $v['error'] = 'Faltam as linhas OLD_DB_ no arquivo .env (dados do banco antigo). Veja o passo a passo no LEIA-ME. Nada foi copiado.';

                return [$v, 409];
            }
            if ($problem = $old->problem()) {
                $v['error'] = $problem;

                return [$v, 409];
            }
            $start = microtime(true);
            if ($acao === 'recopiar') {
                $importer->startRefresh(); // nao recomeca se ja estiver em andamento
            }
            $wasDone = $importer->report()['done'];
            $v['report'] = $importer->run(20.0);
            if ($v['report']['done']) {
                $v['notCopied'] = $importer->notCopied();
                if ($wasDone) {
                    $repair->restartIfDone(); // "Copiar" de novo tambem confere/conserta
                }
                $v['repair'] = $repair->run(max(1.0, 20.0 - (microtime(true) - $start)));
            }

            return [$v, 200];
        });
    }
}
