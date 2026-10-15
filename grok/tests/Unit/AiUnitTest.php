<?php
use PHPUnit\Framework\TestCase;

/** "Reflexões com IA": transporte HTTP simulado, crise, extração do registro, custo e consentimento. */
final class AiUnitTest extends TestCase
{
    public function testChatUsesConfiguredModelEndpointAndKey(): void
    {
        $seen = [];
        $t = function (string $url, array $h, string $b) use (&$seen): array {
            $seen = [$url, $h, json_decode($b, true)];
            return ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => ' Olá! ']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]), 'error' => ''];
        };
        $r = ai_chat([['role' => 'user', 'content' => 'oi']], $t);
        $this->assertSame('Olá!', $r['text']);
        $this->assertSame(5, $r['usage']['completion_tokens']);
        $this->assertSame(cfg('xai.endpoint'), $seen[0]);
        $this->assertContains('Authorization: Bearer xai-test-key', $seen[1]);
        $this->assertSame('grok-4.7', $seen[2]['model']);
        $this->assertSame([['role' => 'user', 'content' => 'oi']], $seen[2]['messages']);
    }
    public function testRequestBodyForReasoningModels(): void
    {
        $seen = null;
        ai_chat([['role' => 'user', 'content' => 'oi']], function (string $u, array $h, string $b) use (&$seen): array { $seen = json_decode($b, true); return ['status' => 200, 'body' => '{"choices":[{"message":{"content":"ok"}}]}', 'error' => '']; });
        $this->assertSame('low', $seen['reasoning_effort'], 'esforço baixo por padrão: "high" (padrão da xAI) estoura o tempo');
        $this->assertSame(900, $seen['max_completion_tokens']);
        foreach (['max_tokens', 'temperature', 'presence_penalty', 'frequency_penalty', 'stop'] as $k) $this->assertArrayNotHasKey($k, $seen);
        $this->assertGreaterThanOrEqual(30, ai_config()['timeout']);
    }
    public function testLegacyEndpointBecomesBaseUrl(): void
    {
        $seen = '';
        $t = function (string $u) use (&$seen): array { $seen = $u; return ['status' => 200, 'body' => '{"choices":[{"message":{"content":"ok"}}]}', 'error' => '']; };
        foreach (['https://api.x.ai/v1/', 'https://api.x.ai/v1', 'https://api.x.ai/v1/chat/completions'] as $b) {
            ai_chat([['role' => 'user', 'content' => 'x']], $t, ['provider' => 'xai', 'key' => 'k', 'model' => 'grok-4.7', 'base_url' => $b, 'operator' => true]);
            $this->assertSame('https://api.x.ai/v1/chat/completions', $seen);
        }
    }
    public function testErrorsClassifiedAndLoggedWithoutSecrets(): void
    {
        $log = STORAGE_DIR . '/logs/ai.log';
        @unlink($log);
        $cases = [
            ['status' => 0, 'body' => '', 'error' => 'Operation timed out after 20001 milliseconds', 'errno' => 28, 'kind' => 'timeout'],
            ['status' => 401, 'body' => '{"error":"Incorrect API key provided: xai-test-key"}', 'error' => '', 'kind' => 'chave'],
            ['status' => 404, 'body' => '{"error":"The model grok-x does not exist"}', 'error' => '', 'kind' => 'modelo'],
            ['status' => 0, 'body' => '', 'error' => 'SSL certificate problem', 'errno' => 60, 'kind' => 'rede'],
        ];
        foreach ($cases as $c) {
            try { ai_chat([['role' => 'user', 'content' => 'x']], function () use ($c): array { return $c; }); $this->fail('esperava erro'); }
            catch (AiError $e) { $this->assertSame($c['kind'], $e->kind); $this->assertStringNotContainsString('xai-test-key', $e->getMessage()); }
        }
        $txt = (string)file_get_contents($log);
        $this->assertStringContainsString('erro=timeout', $txt);
        $this->assertStringContainsString('http=401', $txt);
        $this->assertStringContainsString('does not exist', $txt);
        $this->assertStringNotContainsString('xai-test-key', $txt, 'a chave nunca vai para o log');
        $this->assertStringContainsString('[chave]', $txt);
    }
    public function testHttpErrorsBecomeFriendlyMessages(): void
    {
        $this->expectExceptionMessage('Limite de uso do provedor');
        ai_chat([['role' => 'user', 'content' => 'x']], function (): array { return ['status' => 429, 'body' => '{}', 'error' => '']; });
    }
    public function testEmptyAnswerRejected(): void
    {
        $this->expectException(RuntimeException::class);
        ai_chat([['role' => 'user', 'content' => 'x']], function (): array { return ['status' => 200, 'body' => '{"choices":[{"message":{"content":""}}]}', 'error' => '']; });
    }
    public function testCrisisDetection(): void
    {
        foreach (['Eu quero me matar', 'tô pensando em suicídio', 'não aguento mais viver', 'Quero morrer hoje', 'vou tomar todos os comprimidos', 'acabar com a minha vida', 'pensei em me cortar'] as $t) {
            $this->assertTrue(ai_detect_crisis($t), $t);
        }
        foreach (['Estou bem, dormi 7 horas', 'matei a saudade da minha mãe', 'morri de rir com o filme', 'tomei o remédio no horário'] as $t) {
            $this->assertFalse(ai_detect_crisis($t), $t);
        }
        $this->assertStringContainsString('188', AI_CRISIS_MESSAGE);
        $this->assertStringContainsString('192', AI_CRISIS_MESSAGE);
    }
    public function testSystemPromptForbidsDiagnosisAndMedicationChanges(): void
    {
        $p = ai_system_prompt('Ronaldo');
        $this->assertStringContainsString('Nunca faça diagnóstico', $p);
        $this->assertStringContainsString('Nunca sugira iniciar, parar, trocar, aumentar ou reduzir medicação', $p);
        $this->assertStringContainsString('CVV 188', $p);
        $this->assertStringContainsString('Ronaldo', $p);
    }
    public function testEntryExtractionValidatesRanges(): void
    {
        [$t, $e] = ai_extract_entry("Que bom!\n<<REGISTRO {\"mood\":2,\"period\":\"noite\",\"sleep_hours\":6.3,\"energy\":3,\"anxiety\":9,\"note\":\"animado\"}>>");
        $this->assertSame('Que bom!', $t);
        $this->assertSame(['mood' => 2, 'period' => 'noite', 'sleep_hours' => 6.5, 'energy' => 3, 'note' => 'animado'], $e, 'ansiedade fora da faixa descartada; sono em meia hora');
        $this->assertNull(ai_extract_entry('<<REGISTRO {"mood":7}>>')[1]);
        $this->assertNull(ai_extract_entry('sem bloco')[1]);
    }
    public function testCostFromUsage(): void
    {
        $this->assertEqualsWithDelta(0.005, ai_cost(['prompt_tokens' => 1000, 'completion_tokens' => 500]), 1e-12, 'US$ 2/M entrada + US$ 6/M saída');
    }
    public function testSettingsDefaultsExcludeSensitiveCategories(): void
    {
        $s = ai_settings(['prefs' => null]);
        $this->assertFalse($s['consent']);
        foreach (['sexualidade', 'substancias', 'anotacoes'] as $k) $this->assertNotContains($k, $s['categories']);
        $this->assertContains('humor', $s['categories']);
        $s = ai_settings(['prefs' => json_encode(['ai' => ['consent' => true, 'categories' => ['humor', 'xyz', 'substancias']]])]);
        $this->assertSame(['humor', 'substancias'], $s['categories'], 'categorias desconhecidas ignoradas');
    }
    public function testDisabledWithoutKey(): void
    {
        $old = $GLOBALS['CONFIG']['xai'];
        $oldAi = $GLOBALS['CONFIG']['ai'];
        $GLOBALS['CONFIG']['ai'] = [];
        try {
            $GLOBALS['CONFIG']['xai']['api_key'] = '';
            $this->assertFalse(ai_configured());
            $GLOBALS['CONFIG']['xai']['api_key'] = 'SUA_CHAVE';
            $this->assertFalse(ai_configured());
        } finally { $GLOBALS['CONFIG']['xai'] = $old; $GLOBALS['CONFIG']['ai'] = $oldAi; }
        $this->assertTrue(ai_configured());
    }
}
