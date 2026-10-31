<?php

use Illuminate\Support\Facades\Route;

it('toda rota /api/v1 esta documentada em openapi/v1.yaml', function () {
    $spec = file_get_contents(base_path('openapi/v1.yaml'));
    $missing = [];
    foreach (Route::getRoutes() as $r) {
        if (! str_starts_with($r->uri(), 'api/v1/')) {
            continue;
        }
        $path = '/'.substr($r->uri(), strlen('api/v1/'));
        if (! str_contains($spec, "\n  {$path}:")) {
            $missing[] = $path;
        }
        foreach (array_diff($r->methods(), ['HEAD']) as $m) {
            // verificacao simples: o metodo aparece no arquivo
            expect($spec)->toContain('    '.strtolower($m).':');
        }
    }
    expect($missing)->toBe([]);
});
