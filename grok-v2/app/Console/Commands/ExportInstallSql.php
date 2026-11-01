<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;

/**
 * Gera database/sql/install-mysql57.sql a partir das migrations (alternativa ao instalador web,
 * para importar no phpMyAdmin). Regras: sem information_schema, sem ';' em comentarios.
 * Precisa de um banco VAZIO acessivel (build/CI) porque usa o modo --pretend.
 */
class ExportInstallSql extends Command
{
    protected $signature = 'bipolar:export-sql {--out=database/sql/install-mysql57.sql} {--update-out=database/sql/update-mysql57.sql}';

    protected $description = 'Exporta as migrations como SQL puro compativel com MySQL 5.7';

    public function handle(Migrator $migrator): int
    {
        $migrator->setOutput($this->output);
        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }
        $files = $migrator->getMigrationFiles(database_path('migrations'));
        $header = '-- Bipolar 2.x - MySQL 5.7+ - gerado por bipolar:export-sql em '.now()->toDateString()."\n";
        $sql = ['SET NAMES utf8mb4', "SET time_zone = '+00:00'"];
        $batch = 1;
        $blocks = [];
        foreach ($files as $name => $path) {
            $migration = require $path;
            $conn = $migrator->resolveConnection($migration->getConnection());
            $mine = [];
            foreach ($conn->pretend(fn () => $migration->up()) as $q) {
                $mine[] = trim($this->interpolate($q['query'], $q['bindings']));
            }
            array_push($sql, ...$mine);
            $sql[] = sprintf("INSERT INTO migrations (migration, batch) VALUES ('%s', %d)", addslashes($name), $batch);
            $blocks[$name] = $mine;
        }
        $this->writeUpdate($blocks);
        array_splice($sql, 2, 0, ['CREATE TABLE IF NOT EXISTS migrations (id int unsigned not null auto_increment primary key, migration varchar(255) not null, batch int not null) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci']);

        foreach ($sql as $s) {
            if (stripos($s, 'information_schema') !== false) {
                $this->error('SQL usa information_schema (proibido): '.$s);

                return self::FAILURE;
            }
        }
        $out = base_path($this->option('out'));
        @mkdir(dirname($out), 0755, true);
        $guard = "-- PROTECAO: para (sem alterar nada) se o banco nao estiver vazio ou for do Bipolar 0.5.\n"
            .self::guardSql('instalar')."CALL bipolar_verificar('instalar');\nDROP PROCEDURE bipolar_verificar;\n";
        file_put_contents($out, $header.$guard.implode(";\n", array_map('trim', $sql)).";\n");
        $this->info('Gerado: '.$this->option('out'));

        return self::SUCCESS;
    }

    /** Tabelas que so existem no Bipolar 0.5.x (app antigo). Se alguma existir, o 2.0 NAO pode usar esse banco. */
    public const LEGACY_TABLES = ['entries', 'care_shares', 'care_codes', 'auth_tokens', 'outbox', 'period_markers', 'daily_markers', 'recipients'];

    /**
     * Procedimento bipolar_verificar(modo): so le o banco. Para com mensagem clara (SIGNAL) se:
     * - for banco do Bipolar 0.5 (tabelas antigas, ou users sem remember_token);
     * - tenants/migrations existirem com outro formato (outro sistema);
     * - modo 'instalar' e o banco nao estiver vazio.
     * Testa tabelas/colunas consultando-as (erro 1146/1054 capturado), sem information_schema,
     * porque em hospedagem compartilhada o information_schema pode nao mostrar tudo.
     */
    public static function guardSql(string $mode): string
    {
        $has = fn (string $expr) => "  SET ok = 1;\n  DO (SELECT COUNT({$expr}));\n";
        $stop = fn (string $msg) => "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}';\n";
        $old = 'PARE: banco do Bipolar 0.5 (antigo). NADA foi alterado. Crie um banco NOVO vazio e importe instalar-banco-novo.sql';
        $notEmpty = 'PARE: banco nao esta vazio. NADA foi alterado. Para instalar, use um banco NOVO e vazio. Para atualizar, use atualizar-banco.sql';
        $b = "DROP PROCEDURE IF EXISTS bipolar_verificar;\nDELIMITER $$\nCREATE PROCEDURE bipolar_verificar(IN modo VARCHAR(10))\nBEGIN\n"
            ."  DECLARE ok INT DEFAULT 1;\n  DECLARE CONTINUE HANDLER FOR 1146, 1054 SET ok = 0;\n";
        foreach (self::LEGACY_TABLES as $t) {
            $b .= "  SET ok = 1;\n  DO (SELECT COUNT(*) FROM `{$t}`);\n  IF ok = 1 THEN\n".$stop($old)."  END IF;\n";
        }
        $b .= "  SET ok = 1;\n  DO (SELECT COUNT(*) FROM `users`);\n  IF ok = 1 THEN\n    SET ok = 1;\n    DO (SELECT COUNT(`remember_token`) FROM `users`);\n"
            ."    IF ok = 0 THEN\n  ".$stop($old)."    END IF;\n  END IF;\n";
        foreach (['tenants' => 'kind', 'migrations' => 'migration'] as $t => $col) {
            $b .= "  SET ok = 1;\n  DO (SELECT COUNT(*) FROM `{$t}`);\n  IF ok = 1 THEN\n    SET ok = 1;\n    DO (SELECT COUNT(`{$col}`) FROM `{$t}`);\n"
                ."    IF ok = 0 THEN\n  ".$stop("PARE: a tabela {$t} e de outro sistema. NADA foi alterado. Use um banco NOVO e vazio para o Bipolar 2.0")."    END IF;\n";
            $b .= "    IF modo = 'instalar' THEN\n  ".$stop($notEmpty)."    END IF;\n  END IF;\n";
        }
        $b .= "  IF modo = 'instalar' THEN\n    SET ok = 1;\n    DO (SELECT COUNT(*) FROM `users`);\n    IF ok = 1 THEN\n    ".$stop($notEmpty)."    END IF;\n  END IF;\n";
        $b .= "  IF modo = 'atualizar' THEN\n    SET ok = 1;\n    DO (SELECT COUNT(*) FROM `migrations`);\n    IF ok = 0 THEN\n"
            ."      SET ok = 1;\n      DO (SELECT COUNT(*) FROM `users`);\n      IF ok = 1 THEN\n    ".$stop('PARE: banco sem controle de versao do Bipolar 2.0. NADA foi alterado. Use um banco NOVO e importe instalar-banco-novo.sql')
            ."      END IF;\n    END IF;\n  END IF;\n";

        return $b."END$$\nDELIMITER ;\n";
    }

    /**
     * SQL de ATUALIZACAO para phpMyAdmin (so para bancos que JA sao do Bipolar 2.0):
     * 1) bipolar_verificar('atualizar') para tudo se o banco for do 0.5 ou de outro sistema (nada e alterado);
     * 2) aplica so as migrations que faltam (tabela migrations), na ordem;
     * 3) retomavel: se uma importacao anterior parou no meio, "ja existe" (tabela, coluna, indice, FK)
     *    e ignorado e o resto continua. Pode ser importado varias vezes.
     *
     * @param  array<string, list<string>>  $blocks
     */
    private function writeUpdate(array $blocks): void
    {
        $text = '-- Bipolar 2.x - ATUALIZACAO do banco (MySQL 5.7+) - gerado por bipolar:export-sql em '.now()->toDateString()."\n"
            ."-- SO para bancos que JA sao do Bipolar 2.0. Banco do Bipolar 0.5 (antigo): o script PARA e nao altera nada.\n"
            ."-- Importe no phpMyAdmin (aba Importar). Aplica so o que falta. Pode importar de novo sem problema.\n"
            ."SET NAMES utf8mb4;\nSET time_zone = '+00:00';\n"
            .self::guardSql('atualizar')."CALL bipolar_verificar('atualizar');\nDROP PROCEDURE bipolar_verificar;\n"
            ."DROP PROCEDURE IF EXISTS bipolar_atualizar;\nDELIMITER $$\nCREATE PROCEDURE bipolar_atualizar()\nBEGIN\n"
            ."  DECLARE lote INT DEFAULT 1;\n"
            ."  -- ja existe: tabela 1050, coluna 1060, indice 1061, chave primaria 1068, FK 1022/1826\n"
            ."  DECLARE CONTINUE HANDLER FOR 1050, 1060, 1061, 1068, 1022, 1826 BEGIN END;\n"
            ."  CREATE TABLE IF NOT EXISTS migrations (id int unsigned not null auto_increment primary key, migration varchar(255) not null, batch int not null) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n"
            ."  SELECT COALESCE(MAX(batch), 0) + 1 INTO lote FROM migrations;\n";
        foreach ($blocks as $name => $stmts) {
            $n = addslashes($name);
            $text .= "  IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '{$n}') THEN\n";
            foreach ($stmts as $st) {
                $text .= '    '.$st.";\n";
            }
            $text .= "    INSERT INTO migrations (migration, batch) VALUES ('{$n}', lote);\n  END IF;\n";
        }
        $text .= "END$$\nDELIMITER ;\nCALL bipolar_atualizar();\nDROP PROCEDURE bipolar_atualizar;\n";
        $path = base_path((string) $this->option('update-out'));
        file_put_contents($path, $text);
        $this->info('Gerado: '.$this->option('update-out'));
    }

    private function interpolate(string $q, array $b): string
    {
        foreach ($b as $v) {
            $q = preg_replace('/\?/', is_numeric($v) ? (string) $v : "'".addslashes((string) $v)."'", $q, 1);
        }

        return $q;
    }
}
