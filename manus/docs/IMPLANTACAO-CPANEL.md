# Implantação do backend PHP no cPanel

Este pacote entrega um backend PHP sem Composer para o PWA de diário de humor. Ele assume **PHP 8.2 ou mais recente**, Apache, PDO MySQL, cURL, fileinfo e sodium. O esquema usa apenas recursos disponíveis no **MySQL 5.7**: InnoDB, `utf8mb4`, `DATETIME(6)`, chaves estrangeiras e blobs; não usa tipo JSON nem `CHECK`.

Não há credenciais neste repositório, acesso ao cPanel ou cadastro de aplicativo OAuth. Portanto, nenhum login de provedor foi afirmado ou testado aqui. A publicação do domínio e o teste de cada provedor são etapas do operador.

## Arquivos e separação de confiança

- `backend-php/public/index.php` é o único arquivo PHP que deve ficar em `public_html/api/index.php`.
- `backend-php/private/bootstrap.php`, `media-range.php`, `config.example.php` renomeado para `config.php` e o diretório `storage/` devem ficar fora de `public_html`, preferencialmente em `/home/SEU_USUARIO/bipolar-private/`.
- `backend-php/schema.sql` é importado uma vez no banco MySQL.
- Nunca coloque `config.php`, a chave sodium, tokens OAuth ou arquivos de mídia dentro da árvore pública.
- `index.php` procura primeiro `BIPOLAR_PRIVATE_PATH`; sem essa variável, procura `../../bipolar-private` a partir de `public_html/api/index.php`. Assim, `/home/SEU_USUARIO/public_html/api/index.php` encontra `/home/SEU_USUARIO/bipolar-private/`. O caminho alternativo relativo `backend-php/private` é útil apenas para uma cópia local do projeto.

O localizador pode ser fixado no ambiente do PHP/cPanel com `BIPOLAR_PRIVATE_PATH=/home/SEU_USUARIO/bipolar-private` se o provedor permitir variáveis de ambiente. Se não permitir, use exatamente o layout padrão acima; não copie o bootstrap para `public_html`.

## Build e cópia para o cPanel

O pacote final `bipolar-cpanel-deploy.zip` já inclui o frontend compilado e os arquivos PHP. Para reconstruí-lo a partir do código-fonte, execute:

```bash
pnpm install --frozen-lockfile
pnpm build:static
python3 scripts/package-release.py
```

A saída contém `public_html/` com a interface e `api/index.php`, `bipolar-private/` com o código privado e modelo de configuração e `schema.sql` fora da raiz pública. Nunca copie segredos para o build. O script cria uma pasta nova de release em uma área temporária, sem apagar arquivos do servidor.

No cPanel, crie `/home/SEU_USUARIO/bipolar-private/` e extraia `bootstrap.php`, `media-range.php` e `config.example.php` para lá. Crie também `storage/` fora da área pública. Copie `index.php` para `/home/SEU_USUARIO/public_html/api/index.php` e confira que o Apache não criou um `api/api/` acidental.

Depois:

1. Copie `config.example.php` para `config.php` dentro de `bipolar-private`.
2. Gere uma chave nova, sem colar a chave em chat, Git ou frontend:

   ```bash
   php -r "echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), PHP_EOL;"
   ```

   Cole a saída em `app.encryption_key_b64`. A aplicação recusa chaves ausentes ou diferentes de 32 bytes. Perder essa chave torna payloads e mídias cifrados irrecuperáveis; armazene uma cópia de recuperação separada do backup do banco.

3. Ajuste `app.base_url` para `https://bipolar.app.br`, o timezone inicial para `America/Sao_Paulo`, `storage_path` para um caminho absoluto como `/home/SEU_USUARIO/bipolar-private/storage` e o DSN/usuário/senha do banco.
4. Mantenha `session.cookie_secure=true` e `session.cookie_samesite=Lax`. Não derive `secure` de `NODE_ENV`. `SameSite=None` é uma exceção explícita para preview HTTPS embutido: requer `allow_none_secure=true` e `cookie_secure=true`; não habilite em uma página HTTP.
5. Proteja permissões: diretório privado `0700`, `storage` `0700`, `config.php` `0600` quando o cPanel permitir. Não habilite listagem de diretórios.

O `app-config.js` deve ser servido pelo frontend com o contrato já combinado, sem segredo:

```js
window.BIPOLAR_CONFIG = { serverEnabled: true, apiBase: '/api/index.php' };
```

O pacote já inclui `app-config.js` com `serverEnabled:false`. Ative `true` somente depois de instalar e validar a API; o modo local fica explicitamente identificado na interface.

## Banco pelo phpMyAdmin

1. No cPanel, crie um banco MySQL e um usuário com senha exclusiva. Conceda ao usuário somente os privilégios necessários nesse banco.
2. O servidor deve ser compatível com `utf8mb4` e InnoDB. Importe `backend-php/schema.sql` pelo phpMyAdmin, com o banco correto selecionado e conexão em UTF-8.
3. Confira as tabelas `users`, `identities`, `entries`, `attachments` e `rate_limits`. Não remova as chaves estrangeiras ou o índice único de `entries`; ele impede dois registros do mesmo usuário na mesma data/período.
4. Configure no `config.php` um DSN semelhante a `mysql:host=localhost;dbname=cpuser_bipolar;charset=utf8mb4`. O prefixo real de banco/usuário do cPanel costuma incluir o nome da conta.
5. Não envie `schema.sql` para `public_html`. Depois do primeiro teste, remova cópias temporárias de configuração com senhas.

O MySQL 5.7 é aceito por compatibilidade do esquema, mas é legado. Para uma instalação nova, use somente uma versão ainda suportada pelo provedor que preserve o contrato; planeje migrar para MySQL 8 ou MariaDB suportado. A compatibilidade com MySQL 5.7 não é uma recomendação de mantê-lo indefinidamente.

## HTTPS e Apache

Ative AutoSSL no cPanel e valide o certificado para `bipolar.app.br` antes de ligar OAuth. Force HTTPS no domínio e confira que o proxy não altera a URL pública. O backend exige URL canônica HTTPS para montar callbacks e envia `no-store`, `nosniff`, `frame-ancestors 'none'` e `Referrer-Policy: no-referrer` nas respostas.

O frontend estático pode usar um `.htaccess` próprio. A regra de SPA precisa excluir a API para não transformar o JSON em `index.html`:

```apache
RewriteEngine On
RewriteRule ^api(?:/|$) - [L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ /index.html [L]
```

Use as regras somente na raiz pública onde estão `index.html` e `api/`. Não coloque regras de rewrite ou fallback dentro de `bipolar-private`.

No seletor de PHP do cPanel, habilite `curl`, `pdo_mysql`, `fileinfo` e `sodium`. Confirme também `session` e `openssl` disponíveis na instalação PHP. Ajuste `upload_max_filesize` e `post_max_size` para pelo menos 26 MiB, além de margem para o multipart; `memory_limit` deve acomodar a leitura de um arquivo de até 25 MiB mais a cifra temporária. A aplicação impõe 25 MiB por arquivo e 250 MiB por usuário, independentemente de um limite maior do cPanel. Se o PHP rejeitar o multipart antes de executar o script, o cliente recebe erro genérico; corrija o limite do host sem elevar os limites do produto sem decisão operacional.

## Registro dos provedores OAuth

Os três aplicativos devem usar exatamente estes callbacks HTTPS, incluindo a query string:

```text
https://bipolar.app.br/api/index.php?action=oauth_callback&provider=google
https://bipolar.app.br/api/index.php?action=oauth_callback&provider=x
https://bipolar.app.br/api/index.php?action=oauth_callback&provider=microsoft
```

Não use callback local, URL com `#`, curinga ou outra ordem de parâmetros. A URL cadastrada e `app.base_url` precisam coincidir com o domínio efetivamente acessado.

### Google

No Google Cloud Console, crie um cliente OAuth para aplicação web, cadastre o callback Google acima e coloque o Client ID/Secret em `oauth.google`. O fluxo usa PKCE e os endpoints oficiais:

- autorização: `https://accounts.google.com/o/oauth2/v2/auth`;
- token: `https://oauth2.googleapis.com/token`;
- userinfo OIDC: `https://openidconnect.googleapis.com/v1/userinfo`;
- escopos: `openid email profile`.

A identidade usada é `sub` retornada pelo userinfo. O email não é chave de vínculo com outro provedor.

### Microsoft

No Microsoft Entra, registre uma aplicação web, use o fluxo de autorização v2, permita contas conforme a política do produto e cadastre o callback Microsoft acima. Coloque Client ID/Secret em `oauth.microsoft`. O fluxo usa:

- autorização: `https://login.microsoftonline.com/common/oauth2/v2.0/authorize`;
- token: `https://login.microsoftonline.com/common/oauth2/v2.0/token`;
- userinfo: `https://graph.microsoft.com/v1.0/me`;
- escopos: `openid profile email https://graph.microsoft.com/User.Read`.

A identidade é o `id` do Graph. `mail` ou `userPrincipalName` é apenas informação exibida quando fornecida; não faz auto-link.

### X

No portal de desenvolvedores X, habilite autenticação de usuário OAuth 2.0, configure o callback X acima e coloque Client ID/Secret em `oauth.x`. O token usa autenticação confidencial Basic com Client ID/Secret e PKCE:

- autorização: `https://x.com/i/oauth2/authorize`;
- token: `https://api.x.com/2/oauth2/token`;
- userinfo: `https://api.x.com/2/users/me`;
- escopos solicitados: `users.read tweet.read`.

O acesso, permissões e condições de uso disponíveis para X podem variar por conta, produto e momento. Verifique os requisitos atuais no portal e na documentação oficial; este pacote não estima preço nem promete um nível de acesso. A aplicação identifica a conta pelo `data.id` e não espera email do X.

Em todos os provedores, `state` aleatório, curto e de uso único fica preso à sessão; o verificador PKCE também fica somente na sessão. O callback remove a transação antes da troca do código. O token de acesso nunca é persistido. Após a identidade ser encontrada ou criada, o PHP regenera o ID da sessão e redireciona para `/`.

## Contrato REST

A URL base é `/api/index.php`. Respostas JSON e streaming de mídia são privados e `no-store`. O cliente deve enviar `X-CSRF-Token` em toda mutação. O token vem de `GET session`; não é um segredo de login, mas impede mutações cross-site em conjunto com cookie HttpOnly/SameSite.

### Sessão e autenticação

- `GET session` retorna `{user:null|{id,name,email},csrf:string,providers:{google:boolean,x:boolean,microsoft:boolean}}`.
- `GET oauth_start&provider=google|x|microsoft` retorna `{url:string}`. O cliente navega para essa URL; provedor desconfigurado retorna erro sem simular login.
- `GET oauth_callback&provider=...` é somente o callback fixo e, em sucesso, redireciona para `/`.
- `POST logout` com `X-CSRF-Token` retorna `{ok:true}`.

A sessão usa cookie `HttpOnly`, `Secure` e `SameSite=Lax` por padrão, TTL de inatividade e TTL absoluto. O PHP não confia em `NODE_ENV` para decidir `Secure`.

### Diário

`GET entries` exige login e retorna `{entries:Entry[]}`. `POST entry` exige login, CSRF e JSON, e retorna `{entry:Entry}`. O corpo canônico é:

```json
{
  "id": "uuid-opcional-para-upsert",
  "date": "YYYY-MM-DD",
  "period": "morning|afternoon|night",
  "mood": -3,
  "note": "",
  "sleep": 7.5,
  "medication": "taken|not-taken|not-applicable|",
  "symptoms": [],
  "factors": [],
  "mixed": false
}
```

`Entry` retornado tem `id`, `date`, `period`, `mood`, `note`, `sleep` (número ou `null`), `medication`, `symptoms`, `factors`, `mixed`, `createdAt` ISO-8601 e `attachments:Attachment[]`.

A data não pode ser futura no timezone `America/Sao_Paulo`; o documento de produto orienta o frontend a inicializar a data pelo relógio do navegador. O servidor ainda valida o limite. `mood` é inteiro de -3 a 3; sono é finito de 0 a 24; texto é UTF-8 e listas têm limites; valores fora do enum de medicação são recusados.

O `id` é UUID e sempre é validado com o usuário autenticado. Sem `id`, uma colisão de data/período atualiza o registro único existente. Com `id`, a edição mantém os anexos consultados do banco e nunca aceita URLs de anexo do cliente; alterar para uma data/período já ocupado retorna conflito. O payload, incluindo humor, notas e campos opcionais, é cifrado com `sodium_crypto_secretbox` antes de ser gravado.

`POST delete_entry` aceita `id` na query ou no JSON `{ "id": "uuid" }`, exige login/CSRF e retorna `{ok:true}`. A exclusão é por propriedade, usa transação e tenta remover os arquivos físicos associados.

### Mídia

`POST upload` usa `multipart/form-data` com `entryId`, `file`, `consent=1`, `clientId` UUID para idempotência, `mediaKind` (audio/video), login e CSRF. Retorna:

```json
{"attachment":{"id":"uuid","name":"...","type":"audio|video","url":"/api/index.php?action=media&id=...","mime":"..."}}
```

O servidor identifica MIME com `finfo` e exige correspondência com extensão permitida: `webm`, `mp4`, `ogg`, `wav`, `mp3` ou `m4a`. Nome original não é caminho nem nome de armazenamento. O arquivo é cifrado, recebe nome aleatório fora da web e só depois é associado em transação. Falha de insert remove o temporário/final para evitar órfão. Há limite de 25 MiB por arquivo e quota inicial de 250 MiB por usuário. Repetir um `clientId` já confirmado na mesma entrada retorna o anexo existente, sem duplicar mídia. A classificação audio/video enviada pelo cliente é utilizada somente para contêineres ambíguos já validados por MIME e extensão, não para dispensar validação de conteúdo.

`GET media&id=...` exige login e propriedade, decifra e faz streaming integral com MIME armazenado. Há suporte a um intervalo HTTP de cada vez (`Range`, resposta 206 e intervalos de sufixo). A mídia é decifrada na memória antes de servir o intervalo; mantenha o limite de 25 MiB e monitore memória. Compatibilidade de codecs continua dependente do navegador. `POST delete_media` com JSON `{ "id": "uuid" }` exige login/CSRF e retorna `{ok:true}`.

### Exclusão da conta e saúde

`POST delete_account` exige login/CSRF e JSON `{ "confirmation":"EXCLUIR" }`. Remove identidade, usuário, entradas e anexos pela cascata/limpeza física e encerra a sessão atual; retorna `{ok:true}`. Como sessões PHP são cookies/arquivos do servidor, qualquer sessão antiga que encontrar um usuário inexistente perde autenticação no próximo request; configure limpeza de sessões expiradas do host.

`GET health` retorna apenas `{ok:true}` e não expõe versão, DSN, extensões ou estado de credenciais. Erros públicos são códigos genéricos; detalhes não são enviados ao navegador nem gravados pelo código em logs de aplicação.

## Segurança e operação

O backend usa PDO preparado e valida propriedade em toda consulta de entrada/anexo. Há contador em `rate_limits` por hash HMAC de IP, sem guardar IP em claro, para início/callback OAuth e upload. É uma proteção simples, não substitui WAF, limites do provedor nem monitoramento.

Backups devem ser cifrados e a chave sodium deve ser armazenada separadamente, com controle de acesso. Faça uma restauração de ensaio antes de confiar no backup. Um cron operacional pode procurar nomes `.bin` que não aparecem em `attachments` e remover órfãos após revisão; isso é necessário porque, após um commit de delete, uma falha de `unlink` não deve ressuscitar um registro. Não apague arquivos sem comparar com o banco e sem preservar a política de retenção.

O diário contém dado potencialmente relacionado à saúde. Faça minimização, consentimento explícito para mídia, retenção definida, exportação voluntária, exclusão e revisão jurídica de base legal, avisos e contratos. Cifra em repouso, HTTPS e controle de acesso são medidas técnicas, **não são uma afirmação de conformidade LGPD**. A chave, backups, logs de acesso e suporte também entram na análise.

Para um diário privado pequeno, cPanel com PHP moderno e banco suportado pode ser suficiente e reduz operação. MySQL 5.7 deve ser tratado como legado, não como destino de longo prazo. O primeiro gargalo provável é mídia cifrada e seus backups, não uma capacidade de usuários que possa ser inventada sem medição. Só migre para VPS gerenciado com armazenamento privado de objetos, Redis e jobs assíncronos quando métricas reais mostrarem necessidade; esses serviços também acrescentam gestão, custo e superfície de segurança.

O service worker do PWA não deve colocar entradas, tokens, respostas de API ou mídia em cache. Cache deve conter apenas shell/recursos públicos e deve ser revisto quando houver nova versão. A instalação varia por navegador; no Safari iOS, documente o caminho de compartilhar/adicionar à Tela de Início em vez de prometer um prompt universal.

## Verificação realizada e limites

Foram executados: verificação TypeScript, build estático de produção, 14 testes automatizados JavaScript/TypeScript (8 específicos do diário/API e 6 do template), lint PHP e 28 verificações de integração PHP com MariaDB local e usuários sintéticos. As verificações PHP abrangem criação/atualização/listagem, isolamento entre usuários, cifra e chave incorreta, valores inválidos, CSRF, metadados e remoção de mídias, intervalos de reprodução e rejeição/reuso de state. O esquema foi importado em um banco de teste exclusivo; os registros sintéticos foram removidos ao terminar.

Não foram testados com provedores reais: login social, token exchange, permissões e callbacks externos. Também não houve publicação ou teste no seu cPanel/MySQL 5.7, nem teste de upload multipart e reprodução audiovisual em dispositivos reais. MariaDB local não equivale a certificar sua versão MySQL 5. O teste de produção deve cobrir os três provedores, sessões expiradas, isolamento, upload multipart, quota, codecs, restauração de backup e exclusão. Sem credenciais, os provedores ficam desabilitados; não há login simulado.

No modo servidor, uma falha de mídia mantém os blobs pendentes em memória e exibe aviso. O app avisa ao tentar fechar/recarregar quando há gravação, salvamento ou anexos pendentes; não há fila durável offline. O usuário deve concluir o salvamento dos anexos antes de fechar; encerramento forçado do navegador/sistema pode perder uma mídia ainda não enviada. No modo local, mídia salva é persistida no IndexedDB.

## Referências de implantação

- [Google OAuth para aplicações web](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Microsoft OAuth 2.0 authorization code flow](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow)
- [X OAuth 2.0 authorization code](https://docs.x.com/fundamentals/authentication/oauth-2-0/authorization-code)
- [Escala editorial e referências clínicas](REFERENCIAS-E-ESCALA.md)

## Atualização 1.1 — relatórios e notificações

O pacote 1.1 inclui `automation.php`, `report-render.php`, `cron.php` e `vendor/` no diretório privado. O backend completo agora requer PHP 8.2 ou superior, com curl, mbstring, openssl, sodium e pdo_mysql. Não é necessário instalar Composer no cPanel para a primeira implantação do pacote já compilado; as bibliotecas e licenças vêm incluídas. O código-fonte usa o lockfile para reconstrução.

Faça backup antes de qualquer atualização. Para uma instalação existente, aplique apenas `migration-v2.sql`, que cria tabelas novas; preserve o `config.php` e a chave SecretBox antigos. Adicione as seções SMTP/VAPID/automation do exemplo novo sem sobrescrever OAuth, banco ou credenciais existentes. Para uma instalação nova, o `schema.sql` já contém todas as tabelas.

O empacotamento gera `bipolar-private/composer.json` com `vendor-dir: vendor`, adequado ao layout final. No código-fonte, `backend-php/composer.json` usa `private/vendor`. Se precisar atualizar bibliotecas no cPanel, execute Composer dentro de `bipolar-private`, com o PHP correto; não mova vendor para a raiz pública.

Relatórios manuais no navegador e lembretes in-app podem ser usados localmente. Programação por e-mail e push com aplicativo fechado dependem do SMTP/VAPID e de uma entrada cron por minuto na hospedagem. Leia [Relatórios e notificações](RELATORIOS-E-NOTIFICACOES.md) para os passos completos, horários, consentimento, privacidade e limites. Nenhuma entrega real foi efetuada nesta tarefa.
