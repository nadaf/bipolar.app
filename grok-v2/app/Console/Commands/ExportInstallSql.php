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
        file_put_contents($out, $header.implode(";\n", array_map('trim', $sql)).";\n");
        $this->info('Gerado: '.$this->option('out'));

        return self::SUCCESS;
    }

    /**
     * SQL de ATUALIZACAO para phpMyAdmin: aplica so as migrations que faltam (consulta a tabela
     * migrations), na ordem, dentro de um procedimento temporario. Pode ser importado varias vezes.
     *
     * @param  array<string, list<string>>  $blocks
     */
    private function writeUpdate(array $blocks): void
    {
        $out = ['-- Bipolar 2.x - ATUALIZACAO do banco (MySQL 5.7+) - gerado por bipolar:export-sql em '.now()->toDateString(),
            '-- Importe no phpMyAdmin (aba Importar). Aplica so o que falta. Pode importar de novo sem problema.',
            'SET NAMES utf8mb4',
            "SET time_zone = '+00:00'",
            'CREATE TABLE IF NOT EXISTS migrations (id int unsigned not null auto_increment primary key, migration varchar(255) not null, batch int not null) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'DROP PROCEDURE IF EXISTS bipolar_atualizar'];
        $body = ["CREATE PROCEDURE bipolar_atualizar()\nBEGIN\n  DECLARE lote INT DEFAULT 1;\n  SELECT COALESCE(MAX(batch), 0) + 1 INTO lote FROM migrations;"];
        foreach ($blocks as $name => $stmts) {
            $n = addslashes($name);
            $b = "  IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '{$n}') THEN\n";
            foreach ($stmts as $st) {
                $b .= '    '.$st.";\n";
            }
            $b .= "    INSERT INTO migrations (migration, batch) VALUES ('{$n}', lote);\n  END IF;";
            $body[] = $b;
        }
        $body[] = 'END$$';
        $text = $out[0]."\n".$out[1]."\n".implode(";\n", array_slice($out, 2)).";\n"
            ."DELIMITER $$\n".implode("\n", $body)."\nDELIMITER ;\n"
            ."CALL bipolar_atualizar();\nDROP PROCEDURE bipolar_atualizar;\n";
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
