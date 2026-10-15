<?php
use PHPUnit\Framework\TestCase;

/** V4.3.1: status real da IA, leitura de legal.controller e versão enviada pelo cliente. */
final class V431UnitTest extends TestCase
{
    public function testAiNotReadyWithoutOwnKeyAndWithoutFallback(): void
    {
        $old = $GLOBALS['CONFIG'];
        try {
            unset($GLOBALS['CONFIG']['xai']);
            $GLOBALS['CONFIG']['ai'] = ['secret_key' => base64_encode(str_repeat('k', 32)), 'operator_fallback' => ['provider' => '', 'api_key' => '', 'model' => '']];
            $this->assertTrue(ai_byok_available());
            $this->assertNull(ai_operator_fallback());
            $this->assertFalse(ai_ready(['ai_provider' => null, 'ai_key_enc' => null]), 'sem chave própria e sem fallback: não está configurada para a pessoa');
            $this->assertTrue(ai_ready(['ai_provider' => 'openai', 'ai_model' => '', 'ai_base_url' => null, 'ai_key_enc' => ai_encrypt_key('sk-propria-1234'), 'ai_key_last4' => '1234']));
        } finally {
            $GLOBALS['CONFIG'] = $old;
        }
    }

    public function testLegalControllerReadFromTopLevelKey(): void
    {
        $old = $GLOBALS['CONFIG'];
        try {
            $GLOBALS['CONFIG'] = ['legal' => ['controller' => 'Ronaldo Nadaf, Cuiabá/MT', 'contact_email' => 'x@y.z']] + $old;
            $this->assertSame('Ronaldo Nadaf, Cuiabá/MT', cfg('legal.controller'));
            // dentro de outro bloco (erro comum ao colar no config.php) não é lido
            $GLOBALS['CONFIG'] = ['ai' => ['legal' => ['controller' => 'X']]] + $old;
            unset($GLOBALS['CONFIG']['legal']);
            $this->assertSame('', (string)cfg('legal.controller', ''));
        } finally {
            $GLOBALS['CONFIG'] = $old;
        }
        $src = (string)file_get_contents(TestEnv::ROOT . '/public_html/privacidade.php');
        $this->assertStringContainsString("cfg('legal.controller'", $src);
    }

    public function testClientVersionParsing(): void
    {
        $this->assertNull(client_version([]));
        $this->assertNull(client_version(['version' => null]));
        $this->assertNull(client_version(['version' => 'abc']));
        $this->assertNull(client_version(['version' => -1]));
        $this->assertSame(0, client_version(['version' => 0]));
        $this->assertSame(7, client_version(['version' => '7']));
    }

    public function testMigrationIsPlainAndCommentsHaveNoSemicolon(): void
    {
        $sql = (string)file_get_contents(TestEnv::ROOT . '/migrations/2026-10-25-v4-3-1-versao-registros.sql');
        foreach (preg_split('/\n/', $sql) ?: [] as $line) {
            if (str_starts_with(trim($line), '--')) $this->assertStringNotContainsString(';', $line);
            elseif (trim($line) !== '') $this->assertMatchesRegularExpression('/^ALTER TABLE \w+ ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1;$/', $line);
        }
        $this->assertStringNotContainsStringIgnoringCase('information_schema', $sql);
    }
}
