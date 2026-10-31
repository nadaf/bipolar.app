<?php
/**
 * V5.2.1 (0.5.3-beta): o aviso de consentimento some depois de "Aceitar".
 * Bug em produção: o aviso usava a classe ".consent", que já existia no checkbox LGPD do cadastro; o CSS novo
 * (position: fixed) transformava esse checkbox numa caixa flutuante que continuava na tela depois do aceite.
 */
final class V53Test extends IntegrationCase
{
    private const MAIN = ['Host' => 'bipolar.app.br'];

    private function css(): string { return (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/css/app.css'); }
    private function js(): string { return (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/js/analytics.js'); }

    public function testVersionAndCacheBumped(): void
    {
        $this->assertSame('0.5.3-beta', trim((string)file_get_contents(TestEnv::ROOT . '/VERSION')));
        $sw = (string)file_get_contents(TestEnv::ROOT . '/public_html/sw.js');
        $this->assertStringContainsString("const VERSION = 'bp-v18';", $sw);
        $this->assertStringContainsString('/assets/css/app.css?v=16', $sw);
        $layout = (string)file_get_contents(TestEnv::ROOT . '/app/views/layout.php');
        $this->assertStringContainsString('/assets/css/app.css?v=16', $layout);
        $this->assertStringContainsString('/assets/js/analytics.js?v=2', $layout);
    }

    public function testBannerCssDoesNotLeakToOtherConsentElements(): void
    {
        $css = $this->css();
        // Nenhuma regra com o seletor genérico .consent pode posicionar algo como fixo.
        preg_match_all('/([^{}]+)\{([^}]*)\}/', $css, $m, PREG_SET_ORDER);
        foreach ($m as $rule) {
            foreach (array_map('trim', explode(',', $rule[1])) as $sel) {
                if (preg_match('/(^|\s)\.consent(\b|$)(?!-)/', $sel) && !str_contains($sel, '#consent-banner')) {
                    $this->assertStringNotContainsString('position', $rule[2], "regra '$sel' não pode posicionar");
                    $this->assertStringNotContainsString('fixed', $rule[2], "regra '$sel' não pode ser fixa");
                }
            }
        }
        $this->assertMatchesRegularExpression('/#consent-banner\[hidden\][^{]*#consent-banner\.is-hidden[^{]*\{\s*display:\s*none\s*!important/', $css);
        // o checkbox LGPD do cadastro continua com a classe antiga (só alinhamento)
        $this->assertStringContainsString('class="check consent"', (string)file_get_contents(TestEnv::ROOT . '/public_html/conta/cadastro.php'));
    }

    public function testAnalyticsJsHidesFirstAndSurvivesMissingStorage(): void
    {
        $js = $this->js();
        $this->assertStringContainsString("const CK = 'bp_consent';", $js);
        $this->assertStringContainsString("classList.add('is-hidden')", $js);
        $this->assertMatchesRegularExpression('/function choose\(v\) \{\s*hideBanner\(\);\s*try \{/', $js, 'esconde antes de qualquer coisa');
        $this->assertMatchesRegularExpression('/\} finally \{\s*hideBanner\(\);/', $js, 'e de novo no finally');
        $this->assertStringContainsString('window.localStorage.getItem', $js);
        $this->assertDoesNotMatchRegularExpression('/[^.]\blocalStorage\.(get|set)Item/', str_replace('window.localStorage', '', $js), 'localStorage só dentro do try');
        // stop() apaga só cookies _ga, nunca a própria escolha
        $this->assertStringContainsString('/^_ga/.test(n)', $js);
    }

    public function testServerRendersBannerHiddenWithOwnClass(): void
    {
        if (!is_file(TestEnv::ROOT . '/app/site-main.php')) $this->markTestSkipped('app/site-main.php ausente (pacote público).');
        $c = $this->dev();
        foreach (['/', '/conta/cadastro.php', '/privacidade.php'] as $p) {
            $r = $c->get($p, self::MAIN);
            $this->assertMatchesRegularExpression('/<section id="consent-banner" class="ga-consent is-hidden"[^>]*hidden>/', $r['body'], $p);
            $this->assertSame(1, substr_count($r['body'], 'id="consent-banner"'), $p);
            $this->assertStringNotContainsString('class="consent"', $r['body'], $p);
        }
        $this->assertStringContainsString('bp_consent', $c->get('/privacidade.php', self::MAIN)['body']);
    }
}
