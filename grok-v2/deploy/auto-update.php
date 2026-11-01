<?php

/*
 * Bipolar 2.0 - atualizacao automatica pelo Cron Jobs do cPanel (sem SSH).
 *
 * Linha do cron (1x por dia, 3h):
 *   0 3 * * * /usr/local/bin/php /home1/SEU_USUARIO/bipolar/deploy/auto-update.php >/dev/null 2>&1
 *
 * O que faz: consulta os releases publicos do GitHub; se houver versao mais nova que a instalada,
 * baixa o cpanel.zip, confere o sha256, faz backup (arquivos + banco) em storage/backups (guarda 3),
 * poe o site em manutencao, substitui os arquivos preservando .env, storage/ e .htaccess customizado,
 * roda as migrations, limpa caches e tira da manutencao. Em erro: restaura o backup e loga.
 * Log: bipolar/storage/logs/auto-update.log
 *
 * .env (opcional): AUTO_UPDATE_ENABLED=false desliga; AUTO_UPDATE_PRERELEASE=false ignora betas.
 * Nao usa exec/shell_exec: artisan roda dentro do proprio processo PHP.
 */

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

final class BipolarAutoUpdate
{
    private const REPO_API = 'https://api.github.com/repos/nadaf/bipolar.app/releases?per_page=20';

    private const ASSET = 'cpanel.zip';

    private const KEEP_BACKUPS = 3;

    private string $app;

    private string $home;

    private string $web;

    /** @var array<string, string> */
    private array $env = [];

    private string $log;

    public function __construct(string $deployDir)
    {
        $this->app = dirname($deployDir);
        $this->home = dirname($this->app);
        $this->env = $this->readEnv($this->app.'/.env');
        $webName = $this->opt('AUTO_UPDATE_WEB_DIR', 'bipolar-2-0-public');
        $this->web = $this->home.'/'.basename($webName);
        $this->log = $this->app.'/storage/logs/auto-update.log';
    }

    public function run(): int
    {
        @mkdir(dirname($this->log), 0755, true);
        if (! $this->flag('AUTO_UPDATE_ENABLED', true)) {
            $this->say('desligado (AUTO_UPDATE_ENABLED=false). Nada feito.');

            return 0;
        }
        if (! class_exists(ZipArchive::class)) {
            $this->say('ERRO: o PHP do servidor nao tem a extensao zip (ZipArchive).');

            return 1;
        }

        $lockFile = $this->app.'/storage/app/auto-update.lock';
        @mkdir(dirname($lockFile), 0755, true);
        $lock = fopen($lockFile, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->say('outra atualizacao ja esta rodando. Saindo.');

            return 0;
        }

        try {
            return $this->check();
        } catch (Throwable $e) {
            $this->say('ERRO: '.$e->getMessage());

            return 1;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function check(): int
    {
        $installed = $this->installedVersion();
        $release = $this->pickRelease();
        if ($release === null) {
            $this->say('nenhum release com '.self::ASSET." disponivel. Instalada: $installed.");

            return 0;
        }
        $tag = (string) $release['tag_name'];
        if (version_compare($this->norm($tag), $this->norm($installed), '<=')) {
            $this->say("ja esta na versao mais nova ($installed; ultimo release: $tag). Nada feito.", false);

            return 0;
        }
        $this->say("versao nova: $tag (instalada: $installed). Iniciando.");

        return $this->install($release, $tag, $installed);
    }

    /** @param array<string, mixed> $release */
    private function install(array $release, string $tag, string $from): int
    {
        $work = $this->app.'/storage/app/auto-update-tmp';
        $this->rmTree($work);
        @mkdir($work, 0755, true);

        $assets = [];
        foreach ((array) ($release['assets'] ?? []) as $a) {
            $assets[(string) $a['name']] = (string) $a['browser_download_url'];
        }
        if (! isset($assets[self::ASSET.'.sha256'])) {
            throw new RuntimeException('release sem '.self::ASSET.'.sha256; nao instalo sem conferir.');
        }
        $want = strtolower(substr(trim($this->http($assets[self::ASSET.'.sha256'])), 0, 64));
        $zipPath = $work.'/'.self::ASSET;
        file_put_contents($zipPath, $this->http($assets[self::ASSET]));
        $got = hash_file('sha256', $zipPath);
        if (! preg_match('/^[a-f0-9]{64}$/', $want) || ! hash_equals($want, (string) $got)) {
            $this->rmTree($work);
            throw new RuntimeException("sha256 nao confere (esperado $want, baixado $got). Nada alterado.");
        }
        $this->say('download ok, sha256 conferido.');

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('zip invalido.');
        }
        $new = $work.'/novo';
        $ok = $zip->extractTo($new);
        $zip->close();
        if (! $ok) {
            $this->rmTree($work);
            throw new RuntimeException('nao consegui extrair o zip (disco cheio?). Nada alterado.');
        }
        $webName = basename($this->web);
        if (! is_file("$new/bipolar/vendor/autoload.php") || ! is_file("$new/$webName/index.php")) {
            $this->rmTree($work);
            throw new RuntimeException("zip sem bipolar/ ou $webName/. Nada alterado.");
        }

        $backup = $this->backup($from);
        $this->say("backup: $backup");

        $this->maintenance(true);
        try {
            $this->replace($new.'/bipolar', $this->app, true);
            $this->replace($new.'/'.$webName, $this->web, false);
            $this->clearBootstrapCache();
            $this->artisan(['migrate', '--force' => true]);
            $this->artisan(['optimize:clear']);
            file_put_contents($this->app.'/storage/app/versao-instalada.txt', $tag."\n");
            $this->rememberHtaccess();
            $this->maintenance(false);
            $this->rmTree($work);
            $this->say("instalado $tag com sucesso.");

            return 0;
        } catch (Throwable $e) {
            $this->say('ERRO durante a instalacao: '.$e->getMessage().' -> restaurando backup.');
            try {
                $this->restore($backup);
                $this->say('backup restaurado (arquivos'.(is_file("$backup/banco.sql") ? ' e banco' : '').').');
            } catch (Throwable $r) {
                $this->say('ERRO ao restaurar: '.$r->getMessage().". Backup em $backup");
            }
            $this->maintenance(false);
            $this->rmTree($work);

            return 1;
        }
    }

    /** @return array<string, mixed>|null */
    private function pickRelease(): ?array
    {
        $url = $this->opt('AUTO_UPDATE_API_URL', self::REPO_API);
        $list = json_decode($this->http($url, 'application/vnd.github+json'), true);
        if (! is_array($list)) {
            throw new RuntimeException('resposta invalida da API do GitHub.');
        }
        $pre = $this->flag('AUTO_UPDATE_PRERELEASE', true);
        foreach ($list as $r) {
            if (! is_array($r) || ! empty($r['draft']) || (! $pre && ! empty($r['prerelease']))) {
                continue;
            }
            foreach ((array) ($r['assets'] ?? []) as $a) {
                if (($a['name'] ?? '') === self::ASSET) {
                    return $r;
                }
            }
        }

        return null;
    }

    private function installedVersion(): string
    {
        foreach ([$this->app.'/storage/app/versao-instalada.txt', $this->app.'/VERSION'] as $f) {
            if (is_file($f) && trim((string) file_get_contents($f)) !== '') {
                return trim((string) file_get_contents($f));
            }
        }

        return '0.0.0';
    }

    private function norm(string $v): string
    {
        return ltrim(trim($v), 'vV');
    }

    // ---------------------------------------------------------------- backup / restore

    private function backup(string $from): string
    {
        $root = $this->app.'/storage/backups';
        $dir = $root.'/'.date('Ymd-His').'-'.preg_replace('/[^A-Za-z0-9._-]/', '_', $from);
        @mkdir($dir, 0755, true);
        @file_put_contents($root.'/.htaccess', "Require all denied\nDeny from all\n");

        $zip = new ZipArchive;
        if ($zip->open("$dir/arquivos.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('nao consegui criar o backup.');
        }
        $this->zipDir($zip, $this->app, 'bipolar', true);
        $this->zipDir($zip, $this->web, basename($this->web), false);
        if (! $zip->close()) {
            throw new RuntimeException('falha ao gravar o backup (disco cheio?).');
        }

        try {
            $this->dumpDb("$dir/banco.sql");
        } catch (Throwable $e) {
            @unlink("$dir/banco.sql");
            $this->say('aviso: backup do banco nao feito ('.$e->getMessage().'). Continuando so com arquivos.');
        }

        $all = glob($root.'/*', GLOB_ONLYDIR) ?: [];
        sort($all);
        while (count($all) > self::KEEP_BACKUPS) {
            $this->rmTree((string) array_shift($all));
        }

        return $dir;
    }

    private function zipDir(ZipArchive $zip, string $src, string $prefix, bool $isApp): void
    {
        if (! is_dir($src)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if (! $f->isFile() || $f->isLink()) {
                continue;
            }
            $rel = substr($f->getPathname(), strlen($src) + 1);
            $rel = str_replace('\\', '/', $rel);
            if ($isApp && ($rel === '.env' || str_starts_with($rel, 'storage/'))) {
                continue; // nunca sao tocados pela atualizacao
            }
            $zip->addFile($f->getPathname(), $prefix.'/'.$rel);
        }
    }

    private function restore(string $backup): void
    {
        $tmp = $backup.'/restaurar';
        $this->rmTree($tmp);
        $zip = new ZipArchive;
        if ($zip->open("$backup/arquivos.zip") !== true) {
            throw new RuntimeException('backup de arquivos ilegivel.');
        }
        $ok = $zip->extractTo($tmp);
        $zip->close();
        if (! $ok) {
            throw new RuntimeException('nao consegui extrair o backup.');
        }
        $this->replace("$tmp/bipolar", $this->app, true, true);
        if (is_dir($tmp.'/'.basename($this->web))) {
            $this->replace($tmp.'/'.basename($this->web), $this->web, false, true);
        }
        $this->clearBootstrapCache();
        $this->rmTree($tmp);
        if (is_file("$backup/banco.sql")) {
            $this->restoreDb("$backup/banco.sql");
        }
    }

    // ---------------------------------------------------------------- arquivos

    /** Copia $src sobre $dst. No app, apaga arquivos antigos que sairam do pacote (fora de .env/storage). */
    private function replace(string $src, string $dst, bool $isApp, bool $restoring = false): void
    {
        @mkdir($dst, 0755, true);
        $keep = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
            if ($this->preserved($rel, $isApp, $restoring, $f->getPathname())) {
                if (! $isApp && $rel === '.htaccess') {
                    @copy($f->getPathname(), $dst.'/.htaccess.novo');
                    $this->say('.htaccess customizado mantido; o do pacote ficou em .htaccess.novo');
                }

                continue;
            }
            $target = $dst.'/'.$rel;
            if ($f->isDir()) {
                @mkdir($target, 0755, true);

                continue;
            }
            $keep[$rel] = true;
            @mkdir(dirname($target), 0755, true);
            if (! @copy($f->getPathname(), $target.'.au-new') || ! @rename($target.'.au-new', $target)) {
                throw new RuntimeException("nao consegui gravar $target");
            }
        }
        if (! $isApp) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dst, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dst) + 1));
            if ($this->preserved($rel, true, true) || str_starts_with($rel, 'bootstrap/cache')) {
                continue;
            }
            if ($f->isFile() && ! isset($keep[$rel])) {
                @unlink($f->getPathname());
            } elseif ($f->isDir() && ! $f->isLink()) {
                @rmdir($f->getPathname()); // so remove se vazia
            }
        }
    }

    private function preserved(string $rel, bool $isApp, bool $restoring, string $newFile = ''): bool
    {
        if ($isApp) {
            return $rel === '.env' || $rel === 'storage' || str_starts_with($rel, 'storage/');
        }
        if ($rel === 'storage' || str_starts_with($rel, 'storage/')) {
            return true; // link/pasta de uploads publicos
        }

        return $rel === '.htaccess' && ! $restoring && $this->htaccessCustomized($newFile);
    }

    private function htaccessCustomized(string $newFile): bool
    {
        $cur = $this->web.'/.htaccess';
        if (! is_file($cur)) {
            return false;
        }
        $rec = $this->app.'/storage/app/htaccess-instalado.sha256';
        if (is_file($rec)) {
            return trim((string) file_get_contents($rec)) !== hash_file('sha256', $cur);
        }

        // sem registro (1a atualizacao automatica): customizado se difere do que vem no pacote
        return $newFile === '' || ! is_file($newFile) || hash_file('sha256', $newFile) !== hash_file('sha256', $cur);
    }

    private function rememberHtaccess(): void
    {
        $cur = $this->web.'/.htaccess';
        if (is_file($cur)) {
            file_put_contents($this->app.'/storage/app/htaccess-instalado.sha256', hash_file('sha256', $cur)."\n");
        }
    }

    private function clearBootstrapCache(): void
    {
        foreach (glob($this->app.'/bootstrap/cache/*.php') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function rmTree(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            ($f->isDir() && ! $f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    // ---------------------------------------------------------------- Laravel

    private function maintenance(bool $down): void
    {
        $file = $this->app.'/storage/framework/down';
        @mkdir(dirname($file), 0755, true);
        if ($down) {
            file_put_contents($file, (string) json_encode([
                'except' => [], 'redirect' => null, 'retry' => 60, 'refresh' => 60,
                'secret' => null, 'status' => 503, 'template' => null,
            ]));
        } else {
            @unlink($file);
            @unlink($this->app.'/storage/framework/maintenance.php');
        }
    }

    /** @param array<int|string, mixed> $args */
    private function artisan(array $args): void
    {
        static $kernel = null;
        if ($kernel === null) {
            require $this->app.'/vendor/autoload.php';
            $app = require $this->app.'/bootstrap/app.php';
            $kernel = $app->make(Kernel::class);
            $kernel->bootstrap();
        }
        $cmd = (string) array_shift($args);
        $code = $kernel->call($cmd, $args);
        $out = trim($kernel->output());
        $this->say("artisan $cmd -> $code".($out !== '' ? ': '.preg_replace('/\s+/', ' ', substr($out, -500)) : ''));
        if ($code !== 0) {
            throw new RuntimeException("artisan $cmd falhou (codigo $code).");
        }
    }

    // ---------------------------------------------------------------- banco (PDO, sem mysqldump)

    private function pdo(): PDO
    {
        $e = $this->env;
        if (($e['DB_CONNECTION'] ?? 'mysql') !== 'mysql') {
            throw new RuntimeException('backup do banco so para mysql');
        }
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $e['DB_HOST'] ?? 'localhost', $e['DB_PORT'] ?? '3306', $e['DB_DATABASE'] ?? '');

        return new PDO($dsn, $e['DB_USERNAME'] ?? '', $e['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function dumpDb(string $file): void
    {
        $pdo = $this->pdo();
        $out = fopen($file, 'w');
        if ($out === false) {
            throw new RuntimeException('nao consegui criar banco.sql');
        }
        fwrite($out, "-- Bipolar auto-update backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as $row) {
            $t = (string) $row[0];
            $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
            fwrite($out, "DROP TABLE IF EXISTS `$t`;\n".$create[1].";\n");
            $rows = $pdo->query("SELECT * FROM `$t`", PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $vals = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($r));
                fwrite($out, "INSERT INTO `$t` VALUES (".implode(',', $vals).");\n");
            }
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($out);
    }

    private function restoreDb(string $file): void
    {
        $pdo = $this->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $row) {
            $pdo->exec('DROP TABLE IF EXISTS `'.$row[0].'`'); // tabelas novas criadas pela migration que falhou
        }
        $in = fopen($file, 'r');
        $stmt = '';
        while ($in !== false && ($line = fgets($in)) !== false) {
            if ($stmt === '' && str_starts_with($line, '--')) {
                continue;
            }
            $stmt .= $line;
            if (str_ends_with(rtrim($line), ';')) {
                $pdo->exec($stmt);
                $stmt = '';
            }
        }
        if ($in !== false) {
            fclose($in);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------------------- utilitarios

    private function http(string $url, string $accept = '*/*'): string
    {
        $headers = ['User-Agent: bipolar-auto-update', 'Accept: '.$accept];
        if (function_exists('curl_init')) {
            $c = curl_init($url);
            curl_setopt_array($c, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 600,
            ]);
            $body = curl_exec($c);
            $status = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
            $err = curl_error($c);
            curl_close($c);
            if ($body === false || $status >= 400) {
                throw new RuntimeException("falha ao baixar $url (HTTP $status $err)");
            }

            return (string) $body;
        }
        $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 600, 'ignore_errors' => false]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException("falha ao baixar $url");
        }

        return $body;
    }

    /** @return array<string, string> */
    private function readEnv(string $file): array
    {
        $out = [];
        if (! is_file($file)) {
            return $out;
        }
        foreach ((array) file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', (string) $line, $m)) {
                $v = trim($m[2]);
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                    $v = substr($v, 1, -1);
                }
                $out[$m[1]] = $v;
            }
        }

        return $out;
    }

    private function opt(string $key, string $default): string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return $v;
        }

        return ($this->env[$key] ?? '') !== '' ? $this->env[$key] : $default;
    }

    private function flag(string $key, bool $default): bool
    {
        $v = strtolower($this->opt($key, $default ? 'true' : 'false'));

        return ! in_array($v, ['false', '0', 'no', 'off', 'nao', 'não'], true);
    }

    private function say(string $msg, bool $important = true): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Fortaleza'));
        $line = '['.$now->format('Y-m-d H:i:s').' Fortaleza] '.$msg."\n";
        @file_put_contents($this->log, $line, FILE_APPEND);
        if ($important || getenv('AUTO_UPDATE_VERBOSE')) {
            fwrite(STDOUT, $line);
        }
    }
}

exit((new BipolarAutoUpdate(__DIR__))->run());
