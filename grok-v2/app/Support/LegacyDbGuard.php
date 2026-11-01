<?php

namespace App\Support;

use App\Console\Commands\ExportInstallSql;
use Illuminate\Support\Facades\DB;

/**
 * Impede instalar/atualizar o 2.0 em cima de um banco do Bipolar 0.5 (dados reais de pacientes)
 * ou de outro sistema. Mesmas regras do bipolar_verificar() dos arquivos SQL.
 * Testa consultando as tabelas (sem information_schema).
 */
final class LegacyDbGuard
{
    public static function problem(): ?string
    {
        foreach (ExportInstallSql::LEGACY_TABLES as $t) {
            if (self::probe("SELECT COUNT(*) FROM `{$t}`")) {
                return 'PARE: este banco e do Bipolar 0.5 (antigo). NADA foi alterado. Crie um banco NOVO e vazio para o 2.0 e coloque os dados dele no .env.';
            }
        }
        if (self::probe('SELECT COUNT(*) FROM `users`') && ! self::probe('SELECT COUNT(`remember_token`) FROM `users`')) {
            return 'PARE: este banco e do Bipolar 0.5 (antigo). NADA foi alterado. Crie um banco NOVO e vazio para o 2.0 e coloque os dados dele no .env.';
        }
        foreach (['tenants' => 'kind', 'migrations' => 'migration'] as $t => $col) {
            if (self::probe("SELECT COUNT(*) FROM `{$t}`") && ! self::probe("SELECT COUNT(`{$col}`) FROM `{$t}`")) {
                return "PARE: a tabela {$t} e de outro sistema. NADA foi alterado. Use um banco NOVO e vazio para o Bipolar 2.0.";
            }
        }

        return null;
    }

    private static function probe(string $sql): bool
    {
        try {
            DB::select($sql);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
