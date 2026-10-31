<?php

return [
    // Instalador web de uso unico. Vazio/curto => desligado.
    'installer_token' => env('INSTALLER_TOKEN'),
    // Edicao: 'community' (publico) ou 'saas' (quando o pacote privado estiver instalado).
    'edition' => env('BIPOLAR_EDITION', 'community'),
    // Dominio base dos subdominios <slug>.bipolar.app.br. O host do APP_URL tambem conta como principal.
    'base_domain' => env('BIPOLAR_BASE_DOMAIN', 'bipolar.app.br'),
    // Hosts principais extras (separados por virgula), ex.: dominio temporario da hospedagem.
    'main_hosts' => array_filter(explode(',', (string) env('BIPOLAR_MAIN_HOSTS', ''))),
    // Nomes reservados extras (o pacote privado pode somar os seus).
    'reserved_slugs_extra' => [],
];
