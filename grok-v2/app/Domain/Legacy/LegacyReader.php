<?php

namespace App\Domain\Legacy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Acesso SOMENTE LEITURA ao banco do Bipolar 0.5 (conexao "legado").
 * - So aceita SELECT (qualquer outra coisa lanca excecao antes de chegar ao banco).
 * - Abre a sessao MySQL em modo READ ONLY (protecao extra alem do usuario so com SELECT).
 * - Nao usa information_schema: colunas opcionais sao testadas com SELECT ... LIMIT 0.
 */
final class LegacyReader
{
    public const CONNECTION = 'legado';

    private bool $ready = false;

    /** @var array<string, bool> */
    private array $columns = [];

    public static function configured(): bool
    {
        return (string) config('database.connections.'.self::CONNECTION.'.database') !== '';
    }

    private function db(): ConnectionInterface
    {
        $c = DB::connection(self::CONNECTION);
        if (! $this->ready) {
            if ($c->getDriverName() === 'mysql') {
                $c->statement('SET SESSION TRANSACTION READ ONLY');
            }
            $this->ready = true;
        }

        return $c;
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<object>
     */
    public function select(string $sql, array $bindings = []): array
    {
        if (! preg_match('/^\s*SELECT\s/i', $sql) || str_contains($sql, ';') || stripos($sql, 'information_schema') !== false) {
            throw new \LogicException('LegacyReader aceita so SELECT simples.');
        }

        return array_values($this->db()->select($sql, $bindings));
    }

    public function count(string $table): int
    {
        $r = $this->select("SELECT COUNT(*) AS n FROM `{$table}`");

        return (int) ($r[0]->n ?? 0);
    }

    public function hasTable(string $table): bool
    {
        try {
            $this->select("SELECT 1 FROM `{$table}` LIMIT 0");

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function hasColumn(string $table, string $column): bool
    {
        $k = "$table.$column";
        if (! isset($this->columns[$k])) {
            try {
                $this->select("SELECT `{$column}` FROM `{$table}` LIMIT 0");
                $this->columns[$k] = true;
            } catch (\Throwable) {
                $this->columns[$k] = false;
            }
        }

        return $this->columns[$k];
    }

    /** null = parece banco da 0.5; texto = motivo para nao importar. */
    public function problem(): ?string
    {
        if (! self::configured()) {
            return 'Faltam as linhas OLD_DB_ no arquivo .env.';
        }
        try {
            $this->select('SELECT 1');
        } catch (\Throwable) {
            return 'Não consegui entrar no banco antigo. Confira OLD_DB_HOST, OLD_DB_DATABASE, OLD_DB_USERNAME e OLD_DB_PASSWORD no .env e se o usuário foi adicionado ao banco antigo no cPanel.';
        }
        if (! $this->hasColumn('users', 'password_hash') || ! $this->hasColumn('entries', 'mood')) {
            return 'O banco informado em OLD_DB_DATABASE não parece ser do Bipolar 0.5. Nada foi copiado.';
        }

        return null;
    }
}
