<?php
// Copie este arquivo para config.php e preencha os valores. NUNCA publique config.php.
return [
    'app_url'   => 'https://bipolar.app.br',
    'app_name'  => 'Bipolar — Diário de Humor',
    'default_timezone' => 'America/Cuiaba',
    'dev_login' => false, // apenas para testes locais! Nunca ative em produção.

    // Login por e-mail e senha: exigir confirmação do e-mail antes do primeiro acesso.
    // Mantenha true em produção. Use false SOMENTE para um teste rápido enquanto o SMTP
    // ainda não está configurado (qualquer pessoa poderia cadastrar e-mails de terceiros).
    'require_email_verification' => true,

    // TESTE DE CARGA (deixe VAZIO no dia a dia — vazio = desativado).
    // Com 32+ caracteres, requisições com o cabeçalho "X-Loadtest-Bypass: <este valor>" ignoram TODOS os
    // limites de tentativas (login, cadastro, registros...). APAGUE o valor assim que o teste terminar.
    'loadtest_bypass_ratelimit_token' => '',

    // Páginas públicas de Privacidade e Termos (V4.3). Preencha com os dados de quem opera ESTA instalação.
    'legal' => [
        'controller' => '',          // ex.: 'Fulano de Tal (pessoa física), Cuiabá/MT'
        'contact_email' => 'privacidade@bipolar.app.br',
        'hosting' => 'hospedagem compartilhada HostGator (cPanel)',
        'source_url' => 'https://github.com/SEU_USUARIO/bipolar-app',
        // V4.6: transferência internacional (LGPD art. 33). Ligue SÓ quando dados de saúde forem guardados fora do Brasil
        // (ex.: VPS no Texas). Mostra a seção correspondente na Política de Privacidade.
        'international_transfer' => [
            'enabled' => false,
            'country' => 'Estados Unidos (Texas)',
            'provider' => '',            // ex.: 'Nome do provedor da VPS'
            'backup_provider' => '',     // ex.: 'Backblaze B2 (EUA)'
            'mechanism' => 'cláusulas-padrão contratuais aprovadas pela ANPD (Resolução CD/ANPD nº 19/2024)',
        ],
    ],

    // V4.6: marca e módulos DESTA instalação. Personalização é só configuração, nunca cópia do código.
    'brand' => ['name' => '', 'logo_url' => '', 'color' => ''],   // vazio = padrão Bipolar; color = '#RRGGBB'
    'modules' => [],   // ex.: ['sexualidade' => false, 'substancias' => false] desliga o módulo para todos

    // V4.7: ponto de extensão. O app público não traz módulo nenhum (pasta app/ext/ ausente = desligado).
    // Com um módulo instalado em app/ext/, 'enabled' => false o desliga sem apagar arquivos.
    'extensions' => ['enabled' => true],

    'db' => [
        'host' => 'localhost',
        'name' => 'cpaneluser_bipolar',
        'user' => 'cpaneluser_bipolar',
        'pass' => 'TROQUE_ESTA_SENHA',
        'port' => 3306,
    ],

    // Deixe client_id/client_secret vazios ('') ou com os textos de exemplo (SEU_...) para ocultar o botão do provedor.
    // O site funciona só com e-mail e senha enquanto você não configurar nenhum OAuth.
    // URI de retorno para cadastrar nos 3 consoles (V4.4, sem ?provider=): https://bipolar.app.br/auth/callback.php
    // Passo a passo completo: docs/LOGIN-SOCIAL.md. Login com Google/Microsoft/X não pede o código da verificação em
    // duas etapas do Bipolar: a segunda etapa fica a cargo do provedor (ative-a na sua conta Google/Microsoft/X).
    'oauth' => [
        'google' => [
            'client_id' => 'SEU_CLIENT_ID.apps.googleusercontent.com',
            'client_secret' => 'SEU_CLIENT_SECRET',
        ],
        'microsoft' => [
            'client_id' => 'SEU_APPLICATION_CLIENT_ID',
            'client_secret' => 'SEU_CLIENT_SECRET_VALUE',
            'tenant' => 'common',
        ],
        'x' => [
            'client_id' => 'SEU_X_CLIENT_ID',
            'client_secret' => 'SEU_X_CLIENT_SECRET', // app "confidential"; deixe '' se for app público
        ],
    ],

    'smtp' => [
        'host' => 'mail.bipolar.app.br',
        'port' => 465,            // 465 = SSL implícito; 587 = STARTTLS
        'secure' => 'ssl',        // 'ssl' | 'tls' | ''
        'user' => 'nao-responda@bipolar.app.br',
        'pass' => 'SENHA_DO_EMAIL',
        'from_email' => 'nao-responda@bipolar.app.br',
        'from_name' => 'Bipolar — Diário de Humor',
    ],

    // Notificações push. Sem SSH: rode tools/generate_vapid.php UMA vez pelo Cron do cPanel — ele grava
    // storage/vapid.php e o app usa essas chaves automaticamente se os campos abaixo estiverem vazios.
    'vapid' => [
        'subject' => 'mailto:contato@bipolar.app.br',
        'public_key' => '', // base64url (65 bytes, formato não comprimido)
        'private_key_pem' => "", // PEM completo (-----BEGIN EC PRIVATE KEY----- ...)
    ],

    // Biometria do aparelho (WebAuthn/passkeys). rp_id = domínio do site, sem https:// (vazio = domínio de app_url).
    'webauthn' => [
        'rp_id' => 'bipolar.app.br',
    ],

    // IA opcional "Reflexões com IA" (V4.3): cada pessoa escolhe o provedor e cola a PRÓPRIA chave em Conta.
    'ai' => [
        // Chave do servidor que cifra as chaves das pessoas (libsodium). Sem ela, a IA fica indisponível.
        // V4.4: também cifra (com uma subchave derivada) a chave da verificação em duas etapas. Sem ela, ninguém consegue ativar.
        // ATENÇÃO: se trocar ou perder este valor, quem ativou a verificação em duas etapas só entra com um código de recuperação.
        // Gere UMA vez e guarde com cuidado:  php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
        // Se você perder ou trocar este valor, as chaves já salvas deixam de abrir e cada pessoa precisa colar de novo.
        'secret_key' => '',
        // OPCIONAL e desligado por padrão: chave compartilhada do operador, usada só por quem não salvou chave própria.
        // Projeto aberto: o padrão recomendado é NÃO ter chave compartilhada (quem paga é você, e os dados de quem
        // não tem chave iriam para o provedor desta chave). Provedores: xai, openai, anthropic, google, deepseek, mistral, openrouter.
        'operator_fallback' => ['provider' => '', 'api_key' => '', 'model' => ''],
        'reasoning_effort' => 'low',     // low = respostas em poucos segundos
        'timeout_seconds' => 60,         // tempo máximo de espera pelo provedor (10 a 120)
        'requests_per_hour' => 20,       // por pessoa
        'max_output_tokens' => 900,
        // preços e teto valem só para a chave compartilhada do operador
        'price_in_per_mtok' => 2.00,
        'price_out_per_mtok' => 6.00,
        'monthly_cap_usd' => 2.00,
        // 'allow_private_urls' => false, // true SÓ em testes locais (permite URL própria http://127.0.0.1)
    ],
    // O bloco antigo 'xai' => ['api_key' => ...] das versões V4–V4.2 ainda é lido como chave compartilhada do
    // operador. Para seguir o padrão do projeto aberto (sem chave compartilhada), apague-o do seu config.php.

    // 'storage_dir' => '/home/USUARIO/storage', // opcional; padrão: pasta storage ao lado de app

    'media' => [
        'max_audio_mb' => 25,
        'max_video_mb' => 100,
        'max_image_mb' => 10,
    ],
];
