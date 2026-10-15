<?php
require __DIR__ . '/../_boot.php';
$u = require_user_api();
$pdo = db();
$dir = STORAGE_DIR . '/media/' . (int)$u['id'];
$allowed = [
    'audio/webm' => ['audio', 'webm'], 'audio/ogg' => ['audio', 'ogg'], 'audio/mp4' => ['audio', 'm4a'], 'audio/x-m4a' => ['audio', 'm4a'],
    'audio/mpeg' => ['audio', 'mp3'], 'audio/wav' => ['audio', 'wav'], 'audio/x-wav' => ['audio', 'wav'],
    'video/webm' => ['video', 'webm'], 'video/mp4' => ['video', 'mp4'], 'video/quicktime' => ['video', 'mov'],
    'image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'],
];
$m = $_SERVER['REQUEST_METHOD'];

if ($m === 'GET' && isset($_GET['id'])) {
    $st = $pdo->prepare('SELECT * FROM media WHERE id = ? AND user_id = ?');
    $st->execute([(int)$_GET['id'], $u['id']]);
    $f = $st->fetch();
    $path = $f ? $dir . '/' . basename($f['stored_name']) : '';
    if (!$f || !is_file($path)) { http_response_code(404); exit; }
    $size = filesize($path);
    $start = 0; $end = $size - 1;
    header('Content-Type: ' . $f['mime']);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: inline; filename="registro-' . (int)$f['id'] . '"');
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $r)) {
        if ($r[1] !== '') { $start = (int)$r[1]; if ($r[2] !== '') $end = min((int)$r[2], $size - 1); }
        elseif ($r[2] !== '') { $start = max(0, $size - (int)$r[2]); }
        if ($start > $end) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    $fp = fopen($path, 'rb');
    if ($fp === false) { http_response_code(500); exit; }
    fseek($fp, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fp)) { $chunk = (string)fread($fp, max(1, min(8192, $left))); if ($chunk === '') break; echo $chunk; $left -= strlen($chunk); flush(); }
    fclose($fp);
    exit;
}
if ($m === 'GET') {
    $st = $pdo->prepare('SELECT m.id, m.kind, m.mime, m.size_bytes, m.original_name, m.created_at, e.entry_date, e.period FROM media m LEFT JOIN entries e ON e.id = m.entry_id WHERE m.user_id = ? ORDER BY m.created_at DESC LIMIT 100');
    $st->execute([$u['id']]);
    json_out(['media' => $st->fetchAll()]);
}
if ($m === 'POST') {
    if (!rate_limit('media_' . $u['id'], 30, 3600)) json_out(['erro' => 'Limite de envios atingido. Tente mais tarde.'], 429);
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $msg = ($f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) ? 'Arquivo grande demais para o servidor.' : 'Falha no envio do arquivo.';
        json_out(['erro' => $msg], 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    // WebM só de áudio às vezes é detectado como video/webm: confiar no tipo declarado entre áudio/vídeo webm.
    if ($mime === 'video/webm' && strpos((string)$f['type'], 'audio/webm') === 0) $mime = 'audio/webm';
    if ($mime === 'application/octet-stream' && preg_match('#^(audio|video)/(webm|mp4|ogg)#', (string)$f['type'], $mm)) $mime = $mm[0];
    if (!isset($allowed[$mime])) json_out(['erro' => 'Tipo de arquivo não permitido (' . $mime . ').'], 415);
    [$kind, $ext] = $allowed[$mime];
    $limitMb = (int)cfg("media.max_{$kind}_mb", 25);
    if ($f['size'] > $limitMb * 1024 * 1024) json_out(['erro' => "Arquivo acima do limite de {$limitMb} MB."], 413);
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) json_out(['erro' => 'Falha ao preparar armazenamento.'], 500);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) json_out(['erro' => 'Falha ao salvar arquivo.'], 500);
    $entryId = null;
    if (!empty($_POST['entry_id'])) {
        $st = $pdo->prepare('SELECT id FROM entries WHERE id = ? AND user_id = ?');
        $st->execute([(int)$_POST['entry_id'], $u['id']]);
        $entryId = $st->fetchColumn() ?: null;
    }
    $pdo->prepare('INSERT INTO media (user_id, entry_id, kind, mime, size_bytes, stored_name, original_name) VALUES (?,?,?,?,?,?,?)')
        ->execute([$u['id'], $entryId, $kind, $mime, (int)$f['size'], $name, mb_substr(basename((string)$f['name']), 0, 255)]);
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}
if ($m === 'DELETE') {
    $st = $pdo->prepare('SELECT stored_name FROM media WHERE id = ? AND user_id = ?');
    $st->execute([(int)($_GET['id'] ?? 0), $u['id']]);
    $n = $st->fetchColumn();
    if (!$n) json_out(['erro' => 'Arquivo não encontrado.'], 404); // V4.4
    @unlink($dir . '/' . basename((string)$n));
    $pdo->prepare('DELETE FROM media WHERE id = ? AND user_id = ?')->execute([(int)$_GET['id'], $u['id']]);
    json_out(['ok' => true]);
}
json_out(['erro' => 'Método não permitido.'], 405);
