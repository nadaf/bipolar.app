<?php

namespace App\Console\Commands;

use App\Domain\Legacy\LegacyImporter;
use App\Domain\Legacy\LegacyReader;
use Illuminate\Console\Command;

/** Mesma importacao da pagina /migrar-dados, para quem tem terminal. Mostra so contagens. */
class ImportLegacy extends Command
{
    protected $signature = 'bipolar:import-legacy {--seconds=0 : parar depois de N segundos (0 = ate terminar)}';

    protected $description = 'Copia os dados do Bipolar 0.5 (conexao "legado", so leitura) para o 2.0. Pode repetir.';

    public function handle(LegacyReader $old, LegacyImporter $importer): int
    {
        if (! LegacyImporter::ready()) {
            $this->error('Rode as migrations do 2.0 antes (php artisan migrate).');

            return self::FAILURE;
        }
        if ($problem = $old->problem()) {
            $this->error($problem);

            return self::FAILURE;
        }
        $limit = (float) $this->option('seconds');
        do {
            $r = $importer->run($limit > 0 ? $limit : 20.0);
        } while (! $r['done'] && $limit <= 0);
        foreach ($r['steps'] as $s) {
            $this->line(sprintf('%s: %d copiados, %d ignorados%s', $s['label'], $s['copied'], $s['skipped'], $s['done'] ? '' : ' (em andamento)'));
            foreach ($s['notes'] as $why => $n) {
                $this->line("   {$n} — {$why}");
            }
        }
        if ($r['done']) {
            foreach ($importer->notCopied() as $what => $n) {
                $this->line("Não copiado: {$what}: {$n}");
            }
        }
        $this->info($r['done'] ? 'Pronto.' : 'Falta uma parte: rode de novo.');

        return self::SUCCESS;
    }
}
