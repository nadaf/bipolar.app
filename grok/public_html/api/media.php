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

/**
 * V5.1: tipo real pelo conteúdo (finfo + assinatura dos primeiros bytes). Nunca usa o tipo informado pelo navegador
 * para decidir se o arquivo é aceito: ele só escolhe entre áudio e vídeo quando o contêiner (WebM/MP4/Ogg) já foi comprovado.
 * @param array<string,array{0:string,1:string}> $allowed
 * @return string|null tipo da lista permitida, ou null (recusar)
 */
function media_sniff(string $path, string $declared, array $allowed): ?string
{
    $fi = class_exists('finfo') ? (string)(new finfo(FILEINFO_MIME_TYPE))->file($path) : '';
    $h = (string)@file_get_contents($path, false, null, 0, 64);
    $family = null;
    if (strncmp($h, "\x1A\x45\xDF\xA3", 4) === 0 && strpos($h, 'webm') !== false) $family = 'webm';
    elseif (substr($h, 4, 4) === 'ftyp') $family = in_array(substr($h, 8, 4), ['qt  '], true) ? 'mov' : 'mp4';
    elseif (strncmp($h, 'OggS', 4) === 0) $family = 'ogg';
    elseif (strncmp($h, "\xFF\xD8\xFF", 3) === 0) $family = 'jpeg';
    elseif (strncmp($h, "\x89PNG\r\n\x1A\n", 8) === 0) $family = 'png';
    elseif (strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WAVE') $family = 'wav';
    elseif (strncmp($h, 'ID3', 3) === 0 || (strlen($h) > 1 && ord($h[0]) === 0xFF && (ord($h[1]) & 0xE0) === 0xE0)) $family = 'mp3';
    $audio = strpos($declared, 'audio/') === 0;
    $byFamily = ['webm' => $audio ? 'audio/webm' : 'video/webm', 'mp4' => $audio ? 'audio/mp4' : 'video/mp4', 'mov' => 'video/quicktime', 'ogg' => 'audio/ogg',
        'jpeg' => 'image/jpeg', 'png' => 'image/png', 'wav' => 'audio/wav', 'mp3' => 'audio/mpeg'];
    if ($family === null) return null; // conteúdo desconhecido (inclui application/octet-stream sem assinatura válida)
    $mime = $byFamily[$family];
    // finfo como segunda opinião: texto, HTML, SVG/XML, scripts ou executáveis nunca passam, mesmo com assinatura no começo
    if ($fi !== '' && preg_match('#^text/|html|xml|svg|javascript|php|x-sh|x-executable|x-msdownload#i', $fi)) return null;
    return isset($allowed[$mime]) ? $mime : null;
}

if ($m === 'GET' && isset($_GET['id'])) {
    $st = $pdo->prepare('SELECT * FROM media WHERE id = ? AND user_id = ?');
    $st->execute([(int)$_GET['id'], $u['id']]);
    $f = $st->fetch();
    $path = $f ? $dir . '/' . basename($f['stored_name']) : '';
    if (!$f || !is_file($path)) { http_response_code(404); exit; }
    $size = filesize($path);
    $start = 0; $end = $size - 1;
    // V5.1: só abre na página (inline) o que é comprovadamente do tipo guardado; o resto vai como download
    $real = media_sniff($path, (string)$f['mime'], $allowed);
    $strong = $real !== null && isset($allowed[(string)$f['mime']]) && $allowed[$real][1] === $allowed[(string)$f['mime']][1];
    header('Content-Type: ' . ($strong ? $f['mime'] : 'application/octet-stream'));
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($strong ? 'inline' : 'attachment') . '; filename="registro-' . (int)$f['id'] . '"');
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
    // V5.1: tipo decidido pelo conteúdo (assinatura + finfo); octet-stream ou desconhecido = 415
    $mime = media_sniff((string)$f['tmp_name'], (string)$f['type'], $allowed);
    if ($mime === null) json_out(['erro' => 'Tipo de arquivo não permitido. Envie áudio (WebM, Ogg, MP4/M4A, MP3, WAV), vídeo (WebM, MP4, MOV) ou imagem (JPEG, PNG).'], 415);
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
    // V5.1: confere que o arquivo sumiu antes de apagar o registro; se não sumiu, avisa e mantém (pode tentar de novo)
    require_once APP_ROOT . '/lib/storage_cleanup.php';
    if (!storage_remove_file($dir . '/' . basename((string)$n))) json_out(['erro' => 'Não foi possível apagar o arquivo agora. Tente de novo em alguns minutos.', 'completo' => false], 503);
    $pdo->prepare('DELETE FROM media WHERE id = ? AND user_id = ?')->execute([(int)$_GET['id'], $u['id']]);
    json_out(['ok' => true, 'completo' => true]);
}
json_out(['erro' => 'Método não permitido.'], 405);
