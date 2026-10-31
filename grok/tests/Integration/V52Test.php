<?php
/**
 * V5.2 (0.5.2-beta): Google Analytics 4 com privacidade.
 * Desligado por padrão (app público), ligado só no host principal com app/site-main.php, nunca em páginas com dados
 * de paciente ou token na URL, CSP restrita aos domínios do GA, sem script inline, aviso de consentimento acessível.
 */
final class V52Test extends IntegrationCase
{
    private const MAIN = ['Host' => 'bipolar.app.br'];
    private const ID = 'G-' . 'EN2F38RG3V'; // partido: o pacote público não contém o ID

    private function needSiteMain(): void
    {
        if (!is_file(TestEnv::ROOT . '/app/site-main.php')) $this->markTestSkipped('app/site-main.php ausente (pacote público): GA desligado.');
    }
    private function csp(Http $c, array $r): string { return (string)$c->header($r, 'Content-Security-Policy'); }
    private function assertNoInlineScript(string $html): void
    {
        preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $m, PREG_SET_ORDER);
        foreach ($m as $s) {
            $this->assertMatchesRegularExpression('/\bsrc="\/assets\/js\/[a-z0-9_-]+\.js\?v=\d+"/', $s[1], 'script sem src próprio');
            $this->assertSame('', trim($s[2]));
        }
    }

    public function testDisabledByDefaultNoGoogleAnywhere(): void
    {
        $c = $this->dev();
        foreach (['/', '/privacidade.php', '/termos.php'] as $p) {
            $r = $c->get($p);
            $this->assertDoesNotMatchRegularExpression('/google-analytics|googletagmanager/', $this->csp($c, $r), $p);
            $this->assertStringNotContainsString('bp-ga4', $r['body'], $p);
            $this->assertStringNotContainsString('consent-banner', $r['body'], $p);
            $this->assertStringNotContainsString('analytics.js', $r['body'], $p);
        }
        $this->assertStringNotContainsString('id="estatisticas"', $c->get('/privacidade.php')['body']);
        // subdomínio de clínica / auto-hospedagem não herda o ID, mesmo com o arquivo presente
        foreach (['clinica.bipolar.app.br', 'bipolar.app.br.evil.test', 'meusite.com.br'] as $h) {
            $r = $c->get('/', ['Host' => $h]);
            $this->assertStringNotContainsString(self::ID, $r['body'], $h);
            $this->assertDoesNotMatchRegularExpression('/google-analytics|googletagmanager/', $this->csp($c, $r), $h);
        }
    }

    public function testMainHostLoadsOnlyOwnScriptWithStrictCspAndBanner(): void
    {
        $this->needSiteMain();
        $c = $this->dev();
        $r = $c->get('/', self::MAIN);
        $this->assertStringContainsString('<meta name="bp-ga4" content="' . self::ID . '"', $r['body']);
        $this->assertStringContainsString('/assets/js/analytics.js?v=2', $r['body']);
        $this->assertStringNotContainsString('googletagmanager.com/gtag', $r['body'], 'gtag.js só é carregado pelo JS depois do aceite');
        $this->assertNoInlineScript($r['body']);
        $csp = $this->csp($c, $r);
        $this->assertMatchesRegularExpression("/script-src 'self' https:\/\/www\.googletagmanager\.com;/", $csp);
        $this->assertStringContainsString("connect-src 'self' https://*.google-analytics.com https://*.analytics.google.com https://www.googletagmanager.com;", $csp);
        $this->assertStringContainsString('img-src \'self\' data: blob: https://*.google-analytics.com', $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringNotContainsString('doubleclick', $csp);
        // aviso: oculto até o JS decidir, com Aceitar/Recusar e link para a política
        $this->assertMatchesRegularExpression('/<section id="consent-banner"[^>]*aria-labelledby="consent-title"[^>]*hidden>/', $r['body']);
        $this->assertStringContainsString('id="consent-accept">Aceitar</button>', $r['body']);
        $this->assertStringContainsString('id="consent-deny">Recusar</button>', $r['body']);
        $this->assertStringContainsString('href="/privacidade.php#estatisticas"', $r['body']);
        $this->assertStringContainsString('data-consent-open', $r['body']);
        $p = $c->get('/privacidade.php', self::MAIN)['body'];
        $this->assertStringContainsString('id="estatisticas"', $p);
        $this->assertStringContainsString('nunca é enviado', $p);
        // configurações (logado): botão para mudar a escolha
        // (o curl casa cookies pelo cabeçalho Host, então a página logada é conferida no código e no teste de navegador)
        $idx = (string)file_get_contents(TestEnv::ROOT . '/public_html/index.php');
        $this->assertMatchesRegularExpression('/if \(ga4_active\(\)\).*\n\s*<div class="card" id="conta-estatisticas">/', $idx);
    }

    public function testNeverOnPatientPagesOrUrlsWithTokens(): void
    {
        $this->needSiteMain();
        $c = $this->user('v52tok');
        foreach (['/paciente.php?s=1&t=abc', '/relatorio.php?de=2026-10-01&ate=2026-10-10', '/convite-profissional.php?t=abc', '/confirmar.php?t=abc',
            '/conta/verificar.php?t=abc', '/conta/redefinir.php?t=abc', '/?c=TOKEN123', '/?aba=conta&id=5'] as $p) {
            $r = $c->get($p, self::MAIN);
            $this->assertStringNotContainsString('bp-ga4', $r['body'], $p);
            $this->assertStringNotContainsString('analytics.js', $r['body'], $p);
            $this->assertDoesNotMatchRegularExpression('/google-analytics|googletagmanager/', $this->csp($c, $r), $p);
        }
        $this->assertStringContainsString('bp-ga4', $c->get('/?aba=registrar', self::MAIN)['body'], '?aba= é permitido (nome fixo de tela)');
    }

    public function testConfigOffDisablesAndInvalidIdIgnored(): void
    {
        require_once TestEnv::ROOT . '/app/lib/analytics.php';
        $src = (string)file_get_contents(TestEnv::ROOT . '/app/lib/analytics.php');
        $this->assertStringContainsString("if (\$cfg === 'off') return '';", $src);
        $this->assertSame(1, preg_match(GA4_ID_RE, self::ID));
        foreach (['', 'UA-1234-1', 'G-abc', 'G-ABCD1234"><script>', 'AW-123456'] as $bad) $this->assertSame(0, preg_match(GA4_ID_RE, $bad), $bad);
        $this->assertSame('Configurações', ga4_generic_title('Configurações'));
        $this->assertSame('Paciente Maria', ga4_generic_title('Paciente: Maria'));
    }

    public function testAnalyticsJsPrivacyContract(): void
    {
        $js = (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/js/analytics.js');
        $this->assertStringContainsString("gtag('consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied'", $js);
        $this->assertStringContainsString('allow_google_signals: false', $js);
        $this->assertStringContainsString('allow_ad_personalization_signals: false', $js);
        $this->assertStringContainsString('send_page_view: false', $js);
        $this->assertStringNotContainsString("ad_storage: 'granted'", $js);
        $this->assertMatchesRegularExpression("/const BLOCKED = new Set\(\['pensamentos_morte'/", $js);
        $this->assertMatchesRegularExpression("/const PRIVATE = new Set\(\['pensamentos', 'religiosidade', 'sexualidade', 'substancias'\]\)/", $js);
        $this->assertStringNotContainsString('innerHTML', $js);
        $this->assertStringNotContainsString('user_id', $js);
        $panels = (string)file_get_contents(TestEnv::ROOT . '/app/views/day_panels.php');
        $this->assertStringContainsString('<form id="form-death" data-ga-off ', $panels);
        // ID errado nunca aparece; ID certo só no arquivo do site principal (fora do pacote público)
        $hits = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(TestEnv::ROOT, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = (string)$f;
            if (preg_match('#/(tests/(vendor|tmp|js/node_modules)|\.git)/#', $p) || !$f->isFile() || $f->getSize() > 2000000) continue;
            $s = (string)file_get_contents($p);
            $this->assertStringNotContainsString('G-' . 'P8TYRDCC2G', $s, $p);
            if (str_contains($s, self::ID)) $hits[] = substr($p, strlen(TestEnv::ROOT) + 1);
        }
        $hits = array_values(array_filter($hits, fn ($h) => !str_starts_with($h, 'tests/') && !str_starts_with($h, 'ATUALIZACAO-V5-2') && $h !== 'CHANGELOG.md'));
        $this->assertContains($hits, [[], ['app/site-main.php']], implode(',', $hits));
    }
}
