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
    protected $signature = 'bipolar:export-sql {--out=database/sql/install-mysql57.sql}';

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
        foreach ($files as $name => $path) {
            $migration = require $path;
            $conn = $migrator->resolveConnection($migration->getConnection());
            foreach ($conn->pretend(fn () => $migration->up()) as $q) {
                $sql[] = $this->interpolate($q['query'], $q['bindings']);
            }
            $sql[] = sprintf("INSERT INTO migrations (migration, batch) VALUES ('%s', %d)", addslashes($name), $batch);
        }
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

    private function interpolate(string $q, array $b): string
    {
        foreach ($b as $v) {
            $q = preg_replace('/\?/', is_numeric($v) ? (string) $v : "'".addslashes((string) $v)."'", $q, 1);
        }

        return $q;
    }
}
