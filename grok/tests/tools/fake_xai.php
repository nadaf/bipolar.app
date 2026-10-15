<?php
// Servidor falso da API xAI (php -S ... fake_xai.php) — grava a última requisição e devolve uma resposta fixa.
$dir = getenv('FAKE_XAI_DIR') ?: sys_get_temp_dir();
$body = (string)file_get_contents('php://input');
file_put_contents($dir . '/last.json', json_encode(['uri' => $_SERVER['REQUEST_URI'], 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'body' => json_decode($body, true)]));
$n = (int)@file_get_contents($dir . '/count') + 1;
file_put_contents($dir . '/count', (string)$n);
header('Content-Type: application/json');
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (strpos($auth, 'chave-invalida') !== false) { http_response_code(401); echo '{"error":{"message":"Incorrect API key provided: ' . substr($auth, 7) . '"}}'; exit; }
if (strpos($auth, 'sem-credito') !== false) { http_response_code(429); echo '{"error":{"type":"insufficient_quota","message":"You exceeded your current quota"}}'; exit; }
if (strpos($body, 'FORCE429') !== false) { http_response_code(429); echo '{"error":"rate"}'; exit; }
$req = json_decode($body, true);
$sys = (string)($req['messages'][1]['content'] ?? '');
$text = strpos($sys, '<<REGISTRO') !== false
    ? "Obrigado por contar. Parece uma tarde tranquila.\n<<REGISTRO {\"mood\":1,\"period\":\"tarde\",\"sleep_hours\":7.5,\"energy\":2,\"note\":\"tarde boa\"}>>"
    : 'Resumo: semana com humor estável e sono regular. Pergunta: o que ajudou você a manter a rotina?';
echo json_encode(['id' => 'x', 'object' => 'chat.completion', 'model' => $req['model'] ?? '', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
    'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 500, 'total_tokens' => 1500]]);
