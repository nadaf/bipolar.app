<?php
use PHPUnit\Framework\TestCase;

/** V4.3: adaptadores de provedores (HTTP simulado), cifra da chave, erros em pt-BR e redação de segredos. */
final class AiProvidersTest extends TestCase
{
    /** @return array{0:callable,1:array<int,mixed>} */
    private function capture(string $reply, int $status = 200): array
    {
        $seen = new ArrayObject();
        $t = function (string $url, array $h, string $b) use ($seen, $reply, $status): array {
            $seen['url'] = $url; $seen['h'] = $h; $seen['b'] = json_decode($b, true);
            return ['status' => $status, 'body' => $reply, 'error' => ''];
        };
        return [$t, $seen];
    }
    private function msgs(): array
    {
        return [['role' => 'system', 'content' => 'SIS1'], ['role' => 'system', 'content' => 'SIS2'], ['role' => 'user', 'content' => 'oi'], ['role' => 'user', 'content' => 'tudo bem?'], ['role' => 'assistant', 'content' => 'sim']];
    }

    public function testAnthropicMessagesAdapter(): void
    {
        [$t, $seen] = $this->capture('{"content":[{"type":"thinking","thinking":"x"},{"type":"text","text":" Olá "}],"usage":{"input_tokens":12,"output_tokens":3},"stop_reason":"end_turn"}');
        $r = ai_chat($this->msgs(), $t, ['provider' => 'anthropic', 'key' => 'sk-ant-TESTE', 'model' => 'claude-sonnet-5-5', 'base_url' => '']);
        $this->assertSame('https://api.anthropic.com/v1/messages', $seen['url']);
        $this->assertContains('x-api-key: sk-ant-TESTE', $seen['h']);
        $this->assertContains('anthropic-version: 2023-06-01', $seen['h']);
        foreach ($seen['h'] as $h) $this->assertStringStartsNotWith('Authorization', $h);
        $this->assertSame("SIS1\n\nSIS2", $seen['b']['system']);
        $this->assertSame([['role' => 'user', 'content' => "oi\n\ntudo bem?"], ['role' => 'assistant', 'content' => 'sim']], $seen['b']['messages'], 'mensagens alternadas, sem system');
        $this->assertSame(900, $seen['b']['max_tokens']);
        $this->assertSame('Olá', $r['text']);
        $this->assertSame(['prompt_tokens' => 12, 'completion_tokens' => 3], $r['usage']);
    }

    public function testGeminiAdapter(): void
    {
        [$t, $seen] = $this->capture('{"candidates":[{"content":{"parts":[{"text":"pensando","thought":true},{"text":"Resposta"}]},"finishReason":"STOP"}],"usageMetadata":{"promptTokenCount":7,"candidatesTokenCount":2}}');
        $r = ai_chat($this->msgs(), $t, ['provider' => 'google', 'key' => 'AIzaTESTE', 'model' => 'gemini-3.8-flash', 'base_url' => '']);
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent', $seen['url']);
        $this->assertContains('x-goog-api-key: AIzaTESTE', $seen['h']);
        $this->assertStringNotContainsString('AIzaTESTE', $seen['url'], 'chave no cabeçalho, nunca na URL');
        $this->assertSame("SIS1\n\nSIS2", $seen['b']['systemInstruction']['parts'][0]['text']);
        $this->assertSame('model', $seen['b']['contents'][1]['role']);
        $this->assertSame(900, $seen['b']['generationConfig']['maxOutputTokens']);
        $this->assertSame('Resposta', $r['text']);
        $this->assertSame(7, $r['usage']['prompt_tokens']);
    }

    public function testOpenAiCompatibleProviders(): void
    {
        $ok = '{"choices":[{"message":{"content":"ok"}}],"usage":{"prompt_tokens":1,"completion_tokens":1}}';
        $cases = ['openai' => 'https://api.openai.com/v1/chat/completions', 'xai' => 'https://api.x.ai/v1/chat/completions', 'deepseek' => 'https://api.deepseek.com/v1/chat/completions',
            'mistral' => 'https://api.mistral.ai/v1/chat/completions', 'openrouter' => 'https://openrouter.ai/api/v1/chat/completions'];
        foreach ($cases as $p => $url) {
            [$t, $seen] = $this->capture($ok);
            ai_chat($this->msgs(), $t, ['provider' => $p, 'key' => 'k-' . $p, 'model' => 'm', 'base_url' => '']);
            $this->assertSame($url, $seen['url'], $p);
            $this->assertContains('Authorization: Bearer k-' . $p, $seen['h']);
            $this->assertSame('system', $seen['b']['messages'][0]['role']);
            $this->assertArrayHasKey(in_array($p, ['openai', 'xai'], true) ? 'max_completion_tokens' : 'max_tokens', $seen['b'], $p);
            if (!in_array($p, ['openai', 'xai'], true)) $this->assertArrayNotHasKey('reasoning_effort', $seen['b'], $p);
        }
        [$t, $seen] = $this->capture($ok);
        $GLOBALS['CONFIG']['ai']['allow_private_urls'] = true;
        ai_chat($this->msgs(), $t, ['provider' => 'custom', 'key' => 'k', 'model' => 'llama', 'base_url' => 'http://127.0.0.1:9/v1/']);
        $this->assertSame('http://127.0.0.1:9/v1/chat/completions', $seen['url']);
    }

    public function testErrorKindsAndFriendlyMessages(): void
    {
        $cases = [
            [401, '{"error":{"message":"invalid x-api-key"}}', 'chave', 'Chave inválida'],
            [400, '{"error":{"message":"API key not valid. Please pass a valid API key."}}', 'chave', 'Chave inválida'],
            [429, '{"error":{"type":"insufficient_quota"}}', 'credito', 'sem crédito'],
            [400, '{"type":"error","error":{"message":"Your credit balance is too low"}}', 'credito', 'sem crédito'],
            [402, '{"error":{"message":"Insufficient Balance"}}', 'credito', 'sem crédito'],
            [429, '{"error":{"message":"Rate limit reached"}}', 'limite', 'Limite de uso'],
            [404, '{"error":{"message":"model not found"}}', 'modelo', 'modelo'],
            [0, '', 'timeout', 'demorou demais'],
        ];
        foreach ($cases as [$st, $body, $kind, $msg]) {
            $t = function () use ($st, $body): array { return ['status' => $st, 'body' => $body, 'error' => $st === 0 ? 'Operation timed out' : '', 'errno' => $st === 0 ? 28 : 0]; };
            try { ai_chat([['role' => 'user', 'content' => 'x']], $t, ['provider' => 'openai', 'key' => 'sk-NUNCA-NO-LOG-123456', 'model' => 'm', 'base_url' => '']); $this->fail('esperava erro'); }
            catch (AiError $e) { $this->assertSame($kind, $e->kind, $body); $this->assertStringContainsString($msg, $e->getMessage(), $body); }
        }
        $log = (string)file_get_contents(STORAGE_DIR . '/logs/ai.log');
        $this->assertStringNotContainsString('sk-NUNCA-NO-LOG', $log);
        $this->assertStringContainsString('chave=propria', $log);
    }

    public function testKeyIsRedactedEvenWhenProviderEchoesIt(): void
    {
        $t = function (): array { return ['status' => 401, 'body' => '{"error":"Incorrect API key provided: sk-proj-ECOADA-abcdefghijklmnop"}', 'error' => '']; };
        try { ai_chat([['role' => 'user', 'content' => 'x']], $t, ['provider' => 'openai', 'key' => 'sk-proj-ECOADA-abcdefghijklmnop', 'model' => 'm', 'base_url' => '']); } catch (AiError $e) { $this->assertStringNotContainsString('ECOADA', $e->getMessage()); }
        $this->assertStringNotContainsString('ECOADA', (string)file_get_contents(STORAGE_DIR . '/logs/ai.log'));
        $this->assertSame('x [chave] y', ai_redact('x sk-ant-api03-AAAAAAAAAAAAAAAAAA y', ''));
    }

    public function testSecretboxRoundTripAndTamper(): void
    {
        $enc = ai_encrypt_key('sk-segredo-123');
        $this->assertStringStartsWith('v1:', $enc);
        $this->assertNotSame($enc, ai_encrypt_key('sk-segredo-123'), 'nonce aleatório');
        $this->assertSame('sk-segredo-123', ai_decrypt_key($enc));
        $bin = base64_decode(substr($enc, 3));
        $bin[strlen($bin) - 1] = chr(ord($bin[strlen($bin) - 1]) ^ 1);
        $this->assertNull(ai_decrypt_key('v1:' . base64_encode($bin)), 'adulterado não abre');
        $old = $GLOBALS['CONFIG']['ai']['secret_key'];
        $GLOBALS['CONFIG']['ai']['secret_key'] = base64_encode(str_repeat('z', 32));
        try { $this->assertNull(ai_decrypt_key($enc), 'outra chave do servidor não abre'); } finally { $GLOBALS['CONFIG']['ai']['secret_key'] = $old; }
        $GLOBALS['CONFIG']['ai']['secret_key'] = 'curta';
        try { $this->assertFalse(ai_byok_available()); } finally { $GLOBALS['CONFIG']['ai']['secret_key'] = $old; }
    }

    public function testCredentialPrefersOwnKeyThenOperator(): void
    {
        $u = ['ai_provider' => 'anthropic', 'ai_model' => '', 'ai_base_url' => null, 'ai_key_enc' => ai_encrypt_key('sk-ant-PROPRIA'), 'ai_key_last4' => 'RIA'];
        $c = ai_credential($u);
        $this->assertSame('anthropic', $c['provider']);
        $this->assertSame('sk-ant-PROPRIA', $c['key']);
        $this->assertSame('claude-sonnet-5-5', $c['model'], 'modelo padrão do provedor');
        $this->assertFalse($c['operator']);
        $op = ai_credential(['ai_provider' => null, 'ai_key_enc' => null]);
        $this->assertTrue($op['operator'], 'sem chave própria: chave do operador (config de teste)');
        $this->assertEqualsWithDelta(0.0, ai_cost(['prompt_tokens' => 1000, 'completion_tokens' => 1000], false), 1e-12);
    }

    public function testModelListsAreNonEmptyAndValid(): void
    {
        foreach (ai_providers() as $k => $p) {
            if ($k !== 'custom') $this->assertNotEmpty($p['models'], $k);
            foreach ($p['models'] as $m) $this->assertTrue(ai_valid_model($m), $m);
        }
        $this->assertFalse(ai_valid_model('a b'));
        $this->assertFalse(ai_valid_model(str_repeat('a', 101)));
        $this->assertFalse(ai_valid_key('curta'));
        $this->assertFalse(ai_valid_key("sk-abc\ndef12"));
    }
}
