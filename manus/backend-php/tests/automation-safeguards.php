<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/private/bootstrap.php';
$dsn = getenv('BIPOLAR_TEST_DSN');
if (!$dsn || !str_contains($dsn, 'bipolar_test')) exit(1);
$pdo = new PDO($dsn, getenv('BIPOLAR_TEST_USER') ?: 'root', getenv('BIPOLAR_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec(file_get_contents(dirname(__DIR__) . '/schema.sql'));
$count = 0;
function verify(bool $value, string $label): void { global $count; if (!$value) throw new RuntimeException($label); $count++; echo 'OK: ' . $label . PHP_EOL; }
function rejected(callable $action, string $code): void { try { $action(); } catch (BipolarApiException $e) { verify($e->getMessage() === $code, $code); return; } throw new RuntimeException('Esperado ' . $code); }
$key = random_bytes(32); $users = [bipolar_uuid(), bipolar_uuid()]; $now = bipolar_db_now();
$config = ['app' => ['encryption_key' => $key, 'timezone' => 'America/Sao_Paulo', 'base_url' => 'https://bipolar.app.br'], 'smtp' => ['enabled' => true, 'host' => 'smtp.example.invalid', 'port' => 587, 'username' => 'teste', 'password' => 'SENHA-FICTICIA-DE-TESTE', 'from_email' => 'origem@example.invalid', 'from_name' => 'Teste'], 'automation' => []];
try {
    foreach ($users as $id) $pdo->prepare('INSERT INTO users(id,name,email,created_at,updated_at) VALUES(?,?,?,?,?)')->execute([$id,'Pessoa sintética',null,$now,$now]);
    $settings = bipolar_aut_defaults(); $recipient = ['id'=>bipolar_uuid(),'name'=>'Contato de teste','email'=>'destino@example.invalid','role'=>'family']; $settings['reports']['recipients']=[$recipient];
    bipolar_aut_save_settings($pdo,$config,$users[0],['settings'=>$settings]);
    verify(bipolar_aut_capabilities($pdo,$config)['smtp'] === true,'Autoloader privado e SMTP reconhecidos sem rede');
    $date=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    $entry=bipolar_save_entry($pdo,$config,$users[0],['date'=>$date,'period'=>'morning','mood'=>2,'note'=>'Uma nota sintética com acentuação.','sleep'=>7.5,'medication'=>'taken','mixed'=>false,'symptoms'=>[],'factors'=>[]]);
    $report=bipolar_aut_entries_for_report($pdo,$config,$users[0],$date,$date,false,false);
    verify(count($report['moodDistribution'])===7 && $report['records'][0]['moodLabel']==='Muito elevado','Sete níveis preservados no relatório PHP');
    $body=['from'=>$date,'to'=>$date,'recipientIds'=>[$recipient['id']],'includeNotes'=>false,'includeDetails'=>false,'consent'=>true,'idempotencyKey'=>bipolar_uuid(),'expectedFingerprint'=>bipolar_aut_report_fingerprint($report),'expectedRecipients'=>[['id'=>$recipient['id'],'email'=>$recipient['email']]]];
    $result=bipolar_aut_send_report($pdo,$config,$users[0],$body);
    verify($result['status']==='queued' && $result['queued']===1,'Envio manual autorizado não exige habilitar rotina automática');
    verify(bipolar_aut_send_report($pdo,$config,$users[0],$body)['queued']===0,'Retry com mesma chave não duplica o relatório');
    rejected(fn()=>bipolar_aut_send_report($pdo,$config,$users[0],array_merge($body,['expectedFingerprint'=>str_repeat('0',64)])),'report_changed');
    rejected(fn()=>bipolar_aut_send_report($pdo,$config,$users[0],array_merge($body,['expectedRecipients'=>[['id'=>$recipient['id'],'email'=>'outro@example.invalid']]])),'report_changed');
    $render=bipolar_aut_render_report($report,$recipient['name']);verify(!str_contains($render['html'],'Uma nota sintética') && str_contains($render['html'],'Muito elevado'),'Notas não autorizadas ficam de fora');
    $r=$settings['reports'];$r['enabled']=true;$r['consent']=true;$r['frequency']='daily';
    verify(bipolar_aut_report_period($r,new DateTimeImmutable('2026-10-14 17:59:00',new DateTimeZone('America/Sao_Paulo')))===null,'Relatório nunca é enviado antes do horário escolhido');
    $r['frequency']='monthly';$r['monthDay']=31;verify(bipolar_aut_report_period($r,new DateTimeImmutable('2026-02-28 18:00:00',new DateTimeZone('America/Sao_Paulo')))===['2026-01-01','2026-01-31'],'Mês curto ajusta o dia de envio');
    $r['frequency']='custom';$r['startDate']='2026-10-20';$r['intervalDays']=3;verify(bipolar_aut_report_period($r,new DateTimeImmutable('2026-10-14 18:00:00',new DateTimeZone('America/Sao_Paulo')))===null,'Programação personalizada não começa antes da data escolhida');
    $m=$settings['reminders'];$m['enabled']=true;$m['channel']='email';$m['slots'][0]['time']='09:00';verify(bipolar_aut_reminder_slots($m,new DateTimeImmutable('2026-10-14 08:59:00',new DateTimeZone('America/Sao_Paulo')))===[],'Lembrete nunca é antecipado');
    $m['slots'][0]['time']='06:59';verify(bipolar_aut_reminder_slots($m,new DateTimeImmutable('2026-10-14 07:00:00',new DateTimeZone('America/Sao_Paulo')))===[],'Horário dentro do silêncio não dispara após a pausa');
    $bad=$settings;$bad['reports']['recipients'][]=array_merge($recipient,['id'=>bipolar_uuid()]);rejected(fn()=>bipolar_aut_save_settings($pdo,$config,$users[0],['settings'=>$bad]),'invalid_recipient');
    $config['automation']['send_callback']=static fn()=>throw new RuntimeException('Resultado sintético inconclusivo');$worker=bipolar_aut_worker($pdo,$config);verify($worker['unknown']===1,'Tentativa inconclusiva fica marcada sem afirmar envio');verify(bipolar_aut_worker($pdo,$config)['processed']===0,'Tentativa inconclusiva não é repetida automaticamente');
    $history=bipolar_aut_delivery_history($pdo,$config,$users[0]);verify($history['deliveries'][0]['status']==='unknown','Histórico reflete resultado inconclusivo');
    $endpoint='https://fcm.googleapis.com/test/ownership-'.bin2hex(random_bytes(5));$subscription=['subscription'=>['endpoint'=>$endpoint,'keys'=>['p256dh'=>bipolar_base64url(str_repeat('P',65)),'auth'=>bipolar_base64url(str_repeat('A',16))]]];bipolar_aut_push_subscribe($pdo,$config,$users[0],$subscription);rejected(fn()=>bipolar_aut_push_subscribe($pdo,$config,$users[1],$subscription),'subscription_conflict');
    bipolar_aut_queue($pdo,$config,$users[0],'reminder','email','cancelled-claim',null,null,'Teste',['synthetic'=>true],false);
    $stmt=$pdo->prepare("SELECT * FROM automation_outbox WHERE user_id=? AND event_key='cancelled-claim'");$stmt->execute([$users[0]]);$job=$stmt->fetch();$pdo->prepare("UPDATE automation_outbox SET status='failed' WHERE id=?")->execute([$job['id']]);verify(!bipolar_aut_claim_delivery($pdo,$job),'Preclaim rejeita envio já cancelado');
    $response=bipolar_aut_response($pdo,$config,$users[0]);verify(in_array(hash('sha256',$endpoint),$response['capabilities']['pushEndpointHashes'],true),'Inscrição do dispositivo é reconhecida somente pelo proprietário');
    verify(bipolar_aut_response($pdo,$config,$users[1])['capabilities']['pushEndpointHashes']===[],'Outra conta não recebe hashes de inscrições alheias');
    for($i=0;$i<20;$i++)bipolar_aut_account_rate_limit($pdo,$config,$users[0],'send_report');rejected(fn()=>bipolar_aut_account_rate_limit($pdo,$config,$users[0],'send_report'),'rate_limited');bipolar_aut_account_rate_limit($pdo,$config,$users[1],'send_report');verify(true,'Cota de outra conta não é consumida pelo mesmo IP');
    rejected(fn()=>bipolar_aut_queue($pdo,$config,$users[0],'report','email','oversize-manual',null,null,'Teste',['padding'=>str_repeat('x',8*1024*1024)],false),'report_too_large');
    verify(!bipolar_aut_queue($pdo,$config,$users[0],'report','email','oversize-auto',null,null,'Teste',['padding'=>str_repeat('x',8*1024*1024)],true),'Relatório automático excessivo não é colocado para entrega');
    $stmt=$pdo->prepare("SELECT status FROM automation_outbox WHERE user_id=? AND event_key='oversize-auto'");$stmt->execute([$users[0]]);verify($stmt->fetchColumn()==='failed','Histórico da fila registra falha de tamanho');
    echo "TOTAL: $count verificações de proteção passaram. Sem rede, SMTP ou push reais.\n";
} finally { foreach($users as $id) $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]); }
