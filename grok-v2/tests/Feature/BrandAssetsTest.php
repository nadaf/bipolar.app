<?php

it('manifesto aponta para icones que existem, com maskable e monocromatico', function () {
    $m = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);
    $purposes = collect($m['icons'])->pluck('purpose')->all();
    expect($purposes)->toContain('any', 'maskable', 'monochrome')
        ->and($m['background_color'])->toBe('#f8fafc');
    foreach ($m['icons'] as $i) {
        expect(file_exists(public_path(ltrim($i['src'], '/'))))->toBeTrue();
    }
});

it('cabecalho traz favicon, apple-touch, telas de abertura iOS e abertura do app sem inline', function () {
    $html = $this->get('/entrar')->assertOk()->getContent();
    expect($html)->toContain('href="/favicon.svg"', 'href="/apple-touch-icon.png"', 'apple-touch-startup-image', 'id="abertura"')
        ->and($html)->not->toMatch('/\sstyle\s*=/i');
    preg_match_all('/apple-touch-startup-image" href="([^"]+)"/', $html, $mm);
    expect($mm[1])->not->toBeEmpty();
    foreach ($mm[1] as $src) {
        expect(file_exists(public_path(ltrim($src, '/'))))->toBeTrue();
    }
});
