<?php
declare(strict_types=1);
// Somente CLI. Use exclusivamente um banco vazio de teste. Nunca é copiado para public_html.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/private/bootstrap.php';
$dsn = getenv('BIPOLAR_TEST_DSN');
if (!$dsn || !str_contains($dsn, 'bipolar_test')) { fwrite(STDERR, "Use BIPOLAR_TEST_DSN com um banco exclusivo bipolar_test.\n"); exit(1); }
$pdo = new PDO($dsn, getenv('BIPOLAR_TEST_USER') ?: 'root', getenv('BIPOLAR_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec(file_get_contents(dirname(__DIR__) . '/schema.sql'));
$count = 0;
function check(bool $condition, string $label): void { global $count; if (!$condition) throw new RuntimeException('Falhou: ' . $label); $count++; echo "OK: $label\n"; }
function throws(callable $fn, string $message): void { try { $fn(); } catch (BipolarApiException $e) { check($e->getMessage() === $message, $message); return; } throw new RuntimeException('Era esperado erro: ' . $message); }
$storage = sys_get_temp_dir() . '/bipolar-test-media-' . bin2hex(random_bytes(8)); mkdir($storage,0700);
$key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$config = ['app' => ['encryption_key' => $key, 'timezone' => 'America/Sao_Paulo', 'storage_path' => $storage]];
$GLOBALS['bipolar_storage_path'] = $storage;
$users = [bipolar_uuid(), bipolar_uuid()];
try {
    $now = bipolar_db_now();
    foreach ($users as $i => $id) { $stmt = $pdo->prepare('INSERT INTO users(id,name,email,created_at,updated_at) VALUES(?,?,?,?,?)'); $stmt->execute([$id, 'Pessoa de teste ' . $i, null,$now,$now]); }
    check(bipolar_is_uuid($users[0]), 'UUID válido');
    $plain = 'Nota sintética de teste, não é informação pessoal.';
    $cipher = bipolar_encrypt($plain,$key);
    check(!str_contains($cipher,$plain) && bipolar_decrypt($cipher,$key)===$plain, 'Conteúdo cifrado e recuperado');
    try { bipolar_decrypt($cipher,random_bytes(32)); throw new RuntimeException('Chave incorreta aceita'); } catch (RuntimeException $e) { check($e->getMessage()==='cipher','Chave incorreta recusada'); }
    $input=['date'=>'2026-01-01','period'=>'morning','mood'=>0,'note'=>$plain,'sleep'=>7.5,'medication'=>'taken','symptoms'=>['Cansaço'],'factors'=>['Trabalho'],'mixed'=>false];
    $entry=bipolar_save_entry($pdo,$config,$users[0],$input);
    check($entry['note']===$plain && $entry['sleep']===7.5,'Criar registro e devolver payload');
    $stmt=$pdo->prepare('SELECT payload_cipher FROM entries WHERE id=?');$stmt->execute([$entry['id']]); check(!str_contains($stmt->fetchColumn(),$plain),'Banco não contém nota em claro');
    $other=bipolar_save_entry($pdo,$config,$users[1],$input); check($other['id']!==$entry['id'],'Mesmo período isolado por usuário');
    check(count(bipolar_fetch_entries($pdo,$config,$users[0]))===1,'Listagem isolada');
    $input['mood']=1;$updated=bipolar_save_entry($pdo,$config,$users[0],$input);check($updated['id']===$entry['id'] && $updated['mood']===1,'Upsert único por data e período');
    throws(fn()=>bipolar_save_entry($pdo,$config,$users[1],array_merge($input,['id'=>$entry['id']])),'not_found');
    throws(fn()=>bipolar_save_entry($pdo,$config,$users[0],array_merge($input,['date'=>'2026-02-30'])),'invalid_entry');
    throws(fn()=>bipolar_save_entry($pdo,$config,$users[0],array_merge($input,['date'=>'2099-01-01'])),'invalid_entry');
    throws(fn()=>bipolar_save_entry($pdo,$config,$users[0],array_merge($input,['mood'=>4])),'invalid_entry');
    throws(fn()=>bipolar_save_entry($pdo,$config,$users[0],array_merge($input,['sleep'=>25])),'invalid_entry');
    throws(fn()=>bipolar_save_entry($pdo,$config,$users[0],array_merge($input,['medication'=>'alterar-dose'])),'invalid_entry');
    $_SESSION=['auth'=>['user_id'=>$users[0]],'csrf'=>'token-test'];check(bipolar_require_user($pdo)['id']===$users[0],'Sessão resolve usuário do banco');
    $_SERVER['HTTP_X_CSRF_TOKEN']='token-test';$_SERVER['HTTP_ORIGIN']='https://bipolar.app.br';$_SERVER['HTTP_HOST']='bipolar.app.br';bipolar_require_csrf();check(true,'CSRF válido');
    $_SERVER['HTTP_X_CSRF_TOKEN']='errado';throws(fn()=>bipolar_require_csrf(),'csrf_failed');
    $_SERVER['HTTP_X_CSRF_TOKEN']='token-test';$_SERVER['HTTP_ORIGIN']='https://outro.example';throws(fn()=>bipolar_require_csrf(),'csrf_failed');
    $attachment=bipolar_uuid();$name=bin2hex(random_bytes(32)).'.bin';$content='Conteúdo sintético de mídia';file_put_contents($storage.'/'.$name,bipolar_encrypt($content,$key));
    $stmt=$pdo->prepare('INSERT INTO attachments(id,entry_id,user_id,storage_name,metadata_cipher,size_bytes,created_at) VALUES(?,?,?,?,?,?,?)');$stmt->execute([$attachment,$entry['id'],$users[0],$name,bipolar_encrypt(json_encode(['name'=>'teste.mp3','type'=>'audio','mime'=>'audio/mpeg']),$key),strlen($content),$now]);
    check(count(bipolar_fetch_attachments($pdo,$config,$entry['id'],$users[0]))===1,'Mídia cifrada e nome de 68 caracteres');
    check(bipolar_fetch_attachments($pdo,$config,$entry['id'],$users[1])===[],'Mídia isolada por proprietário');
    check(bipolar_media_range('bytes=2-5',10)===[2,5],'Intervalo de reprodução');
    check(bipolar_media_range('bytes=-3',10)===[7,9],'Intervalo de sufixo');
    throws(fn()=>bipolar_media_range('bytes=11-',10),'invalid_range');
    $_GET=['id'=>$entry['id']];throws(fn()=>bipolar_delete_entry($pdo,$users[1]),'not_found');
    bipolar_delete_entry($pdo,$users[0]);check(!file_exists($storage.'/'.$name),'Exclusão remove mídia física');check(bipolar_fetch_entries($pdo,$config,$users[0])===[],'Exclusão remove registro');
    $_SESSION=['oauth_tx'=>['provider'=>'google','state'=>'correto','verifier'=>'teste','expires'=>time()+300]];$_GET=['provider'=>'google','state'=>'incorreto','code'=>'falso'];throws(fn()=>bipolar_oauth_callback($pdo,$config),'oauth_state_invalid');check(!isset($_SESSION['oauth_tx']),'State removido após uma tentativa');
    echo "TOTAL: $count verificações passaram. OAuth com provedores e upload HTTP não foram exercitados.\n";
} finally {
    foreach ($users as $id) { $stmt=$pdo->prepare('DELETE FROM users WHERE id=?');$stmt->execute([$id]); }
    foreach (glob($storage.'/*') ?: [] as $file) unlink($file);rmdir($storage);
}
