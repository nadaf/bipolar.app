<?php
/**
 * V5.1: remoção de arquivos com verificação (nada de @unlink sem conferir) e reconciliação de sobras.
 * Usado na exclusão de conta, na exclusão de mídia e pelo cron (analyze_signals.php) para apagar pastas órfãs.
 */

/** Apaga um arquivo e confere que ele sumiu. Arquivo inexistente conta como apagado. */
function storage_remove_file(string $path): bool
{
    clearstatcache(true, $path);
    if (!file_exists($path) && !is_link($path)) return true;
    if (is_dir($path) && !is_link($path)) return false;
    if (!@unlink($path)) { error_log('storage: não foi possível apagar ' . basename($path)); }
    clearstatcache(true, $path);
    return !file_exists($path) && !is_link($path);
}

/** Apaga uma pasta de mídia (só arquivos dentro dela) e confere. @return bool true se a pasta não existe mais */
function storage_remove_dir(string $dir): bool
{
    clearstatcache(true, $dir);
    if (!is_dir($dir)) return true;
    $ok = true;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        if (!storage_remove_file($dir . '/' . $f)) $ok = false;
    }
    if ($ok && !@rmdir($dir)) error_log('storage: não foi possível remover a pasta ' . basename($dir));
    clearstatcache(true, $dir);
    return !is_dir($dir);
}

/**
 * Reconciliação: pastas storage/media/<id> de contas que não existem mais (exclusão que falhou no meio)
 * e arquivos soltos sem linha na tabela media há mais de 1 hora. Nunca toca em arquivos ainda referenciados.
 * @return array{pastas:int, arquivos:int, falhas:int}
 */
function storage_reconcile(PDO $pdo, int $maxDirs = 500): array
{
    $out = ['pastas' => 0, 'arquivos' => 0, 'falhas' => 0];
    $base = STORAGE_DIR . '/media';
    if (!is_dir($base)) return $out;
    $users = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
    $known = $pdo->prepare('SELECT stored_name FROM media WHERE user_id = ?');
    $n = 0;
    $dirs = scandir($base) ?: [];
    shuffle($dirs); // com muitas contas, cada execução confere uma parte diferente
    foreach ($dirs as $d) {
        if (!ctype_digit($d) || !is_dir($base . '/' . $d)) continue;
        if (++$n > $maxDirs) break;
        $uid = (int)$d;
        $users->execute([$uid]);
        if ((int)$users->fetchColumn() === 0) {
            if (storage_remove_dir($base . '/' . $d)) $out['pastas']++; else $out['falhas']++;
            continue;
        }
        $known->execute([$uid]);
        $keep = array_flip(array_map('strval', $known->fetchAll(PDO::FETCH_COLUMN)));
        foreach (scandir($base . '/' . $d) ?: [] as $f) {
            if ($f === '.' || $f === '..' || isset($keep[$f])) continue;
            $p = $base . '/' . $d . '/' . $f;
            if (!is_file($p) || (int)@filemtime($p) > time() - 3600) continue; // envio em andamento
            if (storage_remove_file($p)) $out['arquivos']++; else $out['falhas']++;
        }
    }
    return $out;
}
