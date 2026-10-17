# Login com Google, Microsoft e X: passo a passo

Guia para quem **não é desenvolvedor**. Ao final, os botões “Entrar com Google”, “Entrar com Microsoft” e “Entrar com X” aparecem na página inicial do Bipolar. Cada provedor é independente: você pode ativar só um, dois ou os três. O login por **e-mail e senha** continua funcionando com ou sem eles.

Tempo estimado: 20 a 30 minutos por provedor. Conferido em **outubro de 2026**. Os painéis dos provedores mudam de vez em quando: se um nome de menu estiver diferente, procure pelo termo em inglês que aparece entre parênteses.

---

## 0. Antes de começar

### Os valores que este app usa (tirados do código)

| Item | Valor |
|---|---|
| **URL de retorno (callback / redirect URI)**, igual para os três | `https://bipolar.app.br/auth/callback.php` |
| Site (homepage) | `https://bipolar.app.br` |
| Política de Privacidade | `https://bipolar.app.br/privacidade.php` |
| Termos de Uso | `https://bipolar.app.br/termos.php` |
| Domínio autorizado | `bipolar.app.br` |
| E-mail de suporte | `contato@bipolar.app.br` |
| Permissões no Google | `openid`, `email`, `profile` |
| Permissões na Microsoft | `openid`, `email`, `profile`, `User.Read` |
| Permissões no X | `users.read`, `tweet.read` (sem e-mail, veja a seção 3) |
| Segurança do fluxo | Authorization Code + **PKCE (S256)** + `state`, nos três |
| Microsoft | locatário (`tenant`) **`common`**: contas pessoais **e** de trabalho/escola |
| X | app **confidencial** (“Web App, Automated App or Bot”), com Client Secret e PKCE |

> **Atenção à URL de retorno.** Copie e cole exatamente `https://bipolar.app.br/auth/callback.php`: com `https`, **sem** `www`, **sem** barra no final e **sem** `?provider=...`. A partir da V4.4 a URL não tem parâmetros, porque a Microsoft não aceita parâmetros na URL de apps que atendem contas pessoais. Se você já tinha cadastrado no Google ou no X a URL antiga (`.../auth/callback.php?provider=google`), troque pela nova (ou adicione a nova e apague a antiga depois de testar).

### Recomendações de conta

- Crie as credenciais com uma **conta pessoal sua** (Google, Microsoft e X), e não com uma conta de terceiros ou de “mão em mão”. Quem controla essa conta controla o login do site.
- Deixe a **verificação em duas etapas ligada** nessas três contas (no Google: myaccount.google.com → Segurança; na Microsoft: account.microsoft.com → Segurança; no X: Configurações → Segurança e acesso à conta → Segurança → Autenticação de dois fatores). Prefira app autenticador ou chave de acesso (passkey) em vez de SMS.
- Use **`contato@bipolar.app.br`** como e-mail de suporte/contato sempre que o console pedir. Se o console só aceitar o e-mail da própria conta (o Google faz isso no campo “E-mail para suporte do usuário”), use o e-mail da conta ali e coloque `contato@bipolar.app.br` nos campos de contato do desenvolvedor e no site.
- Guarde os segredos (Client Secret) num **gerenciador de senhas**. Eles nunca devem ir para e-mail, WhatsApp, print ou repositório público.

### Onde os valores vão no servidor

Tudo vai no arquivo **`/home/SEU_USUARIO/app/config.php`** (fora da pasta pública). Edite pelo **cPanel → Gerenciador de Arquivos**: clique com o botão direito em `config.php` → **Edit**. Faça uma cópia antes (botão **Copy**, nome `config.php.bak`) e apague a cópia depois que tudo funcionar.

O bloco a preencher é este (ele já existe no seu `config.php`; troque só o que está entre aspas):

```php
'oauth' => [
    'google' => [
        'client_id' => 'COLE_AQUI_o_ID_do_cliente_do_Google',      // termina em .apps.googleusercontent.com
        'client_secret' => 'COLE_AQUI_a_Chave_secreta_do_Google',   // começa com GOCSPX-
    ],
    'microsoft' => [
        'client_id' => 'COLE_AQUI_o_ID_do_aplicativo_(cliente)',    // formato 1a2b3c4d-....
        'client_secret' => 'COLE_AQUI_o_VALOR_do_segredo',          // o "Valor", não o "ID do segredo"
        'tenant' => 'common',                                        // não mude
    ],
    'x' => [
        'client_id' => 'COLE_AQUI_o_OAuth_2.0_Client_ID_do_X',
        'client_secret' => 'COLE_AQUI_o_OAuth_2.0_Client_Secret_do_X',
    ],
],
```

- **Enquanto um provedor não estiver pronto, deixe os valores dele vazios (`''`) ou como vieram do exemplo.** O app esconde o botão de qualquer provedor com campo vazio ou com texto de exemplo (`SEU_CLIENT_ID...`, `SEU_CLIENT_SECRET`, `COLE_AQUI...`, `TROQUE...`, `...`). Assim ninguém vê um botão que não funciona.
- Cuidado com aspas: o valor fica **entre aspas simples**, sem espaço antes ou depois, e cada linha termina com vírgula.
- Depois de salvar, abra `https://bipolar.app.br` numa **janela anônima** e confira se o botão apareceu. Se o site mostrar “erro 500”, o `config.php` ficou com algum erro de digitação: restaure a cópia `config.php.bak` e tente de novo.

---

## 1. Google

Console: **https://console.cloud.google.com** (o menu chama-se **Google Auth Platform**, que substituiu a antiga “Tela de consentimento OAuth”).

### 1.1 Criar o projeto
1. Entre com sua conta Google pessoal (com verificação em duas etapas ligada).
2. No topo, clique no seletor de projetos → **Novo projeto** (*New project*). Nome: `Bipolar`. Organização: “Sem organização” (*No organization*) se aparecer. **Criar**.
3. Confira no topo que o projeto **Bipolar** está selecionado.

### 1.2 Configurar a marca e a tela de consentimento
1. Menu ☰ → **Google Auth Platform** → **Visão geral** (*Overview*) → **Primeiros passos** (*Get started*), se aparecer.
2. **Informações do app** (*App information*):
   - **Nome do app** (*App name*): `Bipolar — Diário de Humor` (sem as palavras “Google” ou “Gmail”, que são proibidas no nome).
   - **E-mail para suporte do usuário** (*User support email*): o e-mail da sua conta (o Google só oferece os e-mails que você controla).
3. **Público-alvo** (*Audience*): **Externo** (*External*). “Interno” só existe para empresas com Google Workspace e limitaria o login a elas.
4. **Dados de contato** (*Contact information*): `contato@bipolar.app.br`.
5. Aceite a política de dados de usuário dos serviços de API do Google → **Criar**.
6. Agora abra **Branding** (Marca) e complete:
   - **Logotipo do app** (*App logo*): **opcional, veja o quadro abaixo antes de enviar**. Se enviar, use um PNG quadrado de 120×120 px (pode exportar o `public_html/assets/icons/icon-192.png` redimensionado).
   - **Página inicial do app** (*Application home page*): `https://bipolar.app.br`
   - **Link da Política de Privacidade** (*Privacy policy link*): `https://bipolar.app.br/privacidade.php`
   - **Link dos Termos de Serviço** (*Terms of service link*): `https://bipolar.app.br/termos.php`
   - **Domínios autorizados** (*Authorized domains*): `bipolar.app.br`
   - **Contato do desenvolvedor** (*Developer contact information*): `contato@bipolar.app.br`
   - **Salvar**.

> **Preciso de verificação do Google?**
> - **Para os escopos, não.** `openid`, `email` e `profile` são **não sensíveis**: não exigem a verificação de escopos, vídeo nem avaliação de segurança, e qualquer pessoa com conta Google consegue entrar depois que o app estiver **Em produção** (passo 1.4).
> - **Para mostrar o nome e o logotipo na tela do Google, sim (verificação da marca, *brand verification*).** Sem ela, a tela de login mostra o **domínio** (`bipolar.app.br`) em vez do nome e do logo. Funciona do mesmo jeito.
> - Se quiser o nome e o logo: em **Branding**, clique em **Verificar marca** (*Verify branding*). O Google confere automaticamente, em geral em minutos, se o domínio é seu (pelo **Google Search Console**), se os links de privacidade e termos abrem e se o nome bate com o site. Para provar o domínio: https://search.google.com/search-console → **Adicionar propriedade** → **Domínio** → `bipolar.app.br` → copie o registro **TXT** → cPanel → **Zone Editor** → `bipolar.app.br` → **+ Add Record** → tipo **TXT**, nome `bipolar.app.br.`, valor colado → salvar → volte ao Search Console e clique em **Verificar** (pode levar de minutos a algumas horas). Depois, em **Branding**, clique em **Publicar marca** (*Publish branding*) quando aparecer “Pronto para publicar”.
> - Dica: se você **enviar um logotipo**, o Google passa a exigir a verificação da marca. Se não quiser esse passo agora, deixe o logo para depois.

### 1.3 Escopos
1. **Acesso a dados** (*Data access*) → **Adicionar ou remover escopos** (*Add or remove scopes*).
2. Marque somente: `openid`, `.../auth/userinfo.email` e `.../auth/userinfo.profile`. Todos aparecem na lista “escopos não sensíveis”. **Atualizar** → **Salvar**.
3. Não adicione nenhum outro escopo: o app não precisa e qualquer escopo sensível exigiria verificação demorada.

### 1.4 Publicar (sair do modo de teste)
1. **Público-alvo** (*Audience*) → **Status de publicação** (*Publishing status*): se estiver **Em teste** (*Testing*), clique em **Publicar app** (*Publish app*) → **Confirmar**. O status passa a **Em produção** (*In production*).
2. Em modo de teste só entram os até 100 “usuários de teste” cadastrados, e as outras pessoas recebem “acesso bloqueado / app em teste” (erro 403 `access_denied`).

### 1.5 Criar o cliente OAuth (as credenciais)
1. **Clientes** (*Clients*) → **Criar cliente** (*Create client*).
2. **Tipo de aplicativo** (*Application type*): **Aplicativo da Web** (*Web application*). Nome: `Bipolar (site)`.
3. **Origens JavaScript autorizadas** (*Authorized JavaScript origins*): `https://bipolar.app.br` (opcional para este app, mas não atrapalha).
4. **URIs de redirecionamento autorizados** (*Authorized redirect URIs*) → **Adicionar URI**: `https://bipolar.app.br/auth/callback.php`
5. **Criar**.
6. **Copie na hora o ID do cliente e a Chave secreta do cliente** (ou clique em **Baixar JSON**). Desde 2025 o Google mostra a chave secreta **só neste momento**: depois aparecem apenas os últimos caracteres. Se perder, abra o cliente e crie uma nova chave secreta (*Add secret*), atualize o `config.php` e desative a antiga.
7. No `config.php`: **ID do cliente** → `oauth.google.client_id`; **Chave secreta** → `oauth.google.client_secret`.

Mudanças na URI de redirecionamento podem levar **de 5 minutos a algumas horas** para valer no Google.

---

## 2. Microsoft (contas pessoais e de trabalho/escola)

Console: **https://entra.microsoft.com** (Centro de administração do Microsoft Entra). O antigo “Azure AD” agora se chama **Microsoft Entra ID**.

### 2.1 Ter um diretório (uma vez só)
- Para registrar um app é preciso um **locatário** (diretório) do Entra. Quem só tem uma conta pessoal (Outlook/Hotmail) consegue isso criando uma **conta gratuita do Azure** em https://azure.microsoft.com/free com a mesma conta: ela cria o “Diretório padrão” (*Default Directory*). Pode pedir cartão para confirmar identidade, mas registrar o app **não gera cobrança**.
- Se o Entra disser que você não tem acesso para registrar apps, é sinal de que está no diretório errado: clique na engrenagem (**Configurações**) no topo e troque para o **Diretório padrão**.

### 2.2 Registrar o app
1. Entre em https://entra.microsoft.com com a sua conta pessoal (com verificação em duas etapas ligada).
2. **Entra ID** → **Registros de aplicativo** (*App registrations*) → **Novo registro** (*New registration*).
3. **Nome**: `Bipolar — Diário de Humor` (aparece para quem faz login).
4. **Tipos de conta com suporte** (*Supported account types*): escolha **Qualquer locatário do Entra ID + contas Microsoft pessoais** (*Any Entra ID Tenant + Personal Microsoft accounts*; em telas antigas: “Contas em qualquer diretório organizacional e contas Microsoft pessoais”). É o único tipo compatível com o `tenant` **`common`** que o app usa (contas pessoais **e** de trabalho/escola).
5. **URI de redirecionamento** (*Redirect URI*): plataforma **Web** → `https://bipolar.app.br/auth/callback.php`
6. **Registrar**.
7. Na **Visão geral** (*Overview*), copie o **ID do aplicativo (cliente)** (*Application (client) ID*) → `oauth.microsoft.client_id`. **Não** use o “ID do objeto” nem o “ID do diretório (locatário)”.

Se a URI não foi cadastrada no passo 5: **Autenticação** (*Authentication*) → **Adicionar uma plataforma** / **Adicionar URI de redirecionamento** → **Web** → cole a URL → **Configurar/Salvar**. Não marque “Tokens de acesso” nem “Tokens de ID” (fluxo implícito): o app usa o fluxo de código com PKCE.

### 2.3 Criar o segredo do cliente
1. **Certificados e segredos** (*Certificates & secrets*) → aba **Segredos do cliente** (*Client secrets*) → **Novo segredo do cliente** (*New client secret*).
2. **Descrição**: `bipolar-2026-10`. **Expira em** (*Expires*): escolha entre as opções oferecidas (a Microsoft recomenda **180 dias**; o máximo costuma ser **24 meses**).
3. **Adicionar**. Copie **na hora** a coluna **Valor** (*Value*) → `oauth.microsoft.client_secret`. **Não** copie o “ID do segredo” (*Secret ID*): esse é o erro mais comum. O valor fica oculto depois que você sai da página.

> **⏰ Lembrete de renovação.** Quando o segredo vence, o botão “Entrar com Microsoft” para de funcionar (erro `AADSTS7000222`). Anote a data de validade e **crie um lembrete no seu calendário para 2 semanas antes**. Para renovar: crie um **novo** segredo, troque o valor no `config.php`, teste o login e só então apague o segredo antigo.

### 2.4 Permissões
- **Permissões de API** (*API permissions*): o registro já vem com **Microsoft Graph → User.Read (delegada)**. O app pede também `openid`, `email` e `profile`, que não precisam ser adicionados à mão: a pessoa aceita na primeira entrada. Não é preciso “consentimento do administrador”.
- Algumas empresas e escolas bloqueiam apps de terceiros: nesse caso, quem usa conta de trabalho/escola vê “Precisa da aprovação do administrador”. Não é um erro do Bipolar: a pessoa pode entrar com conta pessoal, Google ou e-mail e senha.

### 2.5 No config.php
`client_id` = ID do aplicativo (cliente); `client_secret` = **Valor** do segredo; `tenant` = `'common'`.

---

## 3. X (antigo Twitter)

Console: **https://console.x.com** (o antigo developer.x.com redireciona para lá).

> **Sobre o custo (outubro de 2026).** O antigo plano gratuito (*Free tier*) **não está mais disponível para contas novas de desenvolvedor**: o padrão agora é **pago por uso** (*Pay-Per-Use*), com créditos comprados antes. O login em si (OAuth) não é cobrado, mas depois dele o Bipolar consulta o perfil da pessoa (`GET /2/users/me`), que conta como **1 leitura de usuário** (cerca de **US$ 0,01** pela tabela pública; leituras repetidas do mesmo perfil em 24 h não são cobradas de novo). Sem créditos, o login pelo X falha com “Falha ao obter dados do perfil.”. Confira os preços atuais no próprio console antes de decidir. Se você já tem uma conta antiga com plano gratuito ou Basic ativo, ela continua valendo nas condições dela. **Se não quiser custo, deixe o X desligado** (valores vazios): Google, Microsoft e e-mail e senha não têm custo.

### 3.1 Conta de desenvolvedor e app
1. Entre em https://console.x.com com sua conta pessoal do X (com autenticação de dois fatores ligada).
2. Na primeira vez, preencha o cadastro de desenvolvedor: descreva o uso em inglês, por exemplo: *“Sign in with X for a personal mood diary web app (bipolar.app.br). We only read the user id and name to create the account. No posting, no data resale.”* Aceite o *Developer Agreement*.
3. **Apps** → **Create App**. Nome: `Bipolar Diario de Humor` (o X não aceita alguns caracteres especiais). Ambiente: **Production**.
4. Feche a tela que mostra *API Key*, *API Key Secret* e *Bearer Token*: **o Bipolar não usa essas chaves** (são do OAuth 1.0a).
5. Se usar o modelo pago por uso, compre um valor pequeno de créditos em **Billing → Credits** e defina um **limite de gastos** (*spending limit*).

### 3.2 Configurações de autenticação do usuário
1. Abra o app → aba **Keys and tokens** → seção **User authentication settings** → **Set up** (ou **Edit**).
2. **App permissions**: **Read** (somente leitura). O Bipolar nunca publica nada.
3. **Request email from users**: deixe **desligado**. Veja “Limitação do e-mail” abaixo.
4. **Type of App**: **Web App, Automated App or Bot**. Esse é o **cliente confidencial** (com Client Secret), que é o recomendado para sites com servidor. O app também usa PKCE.
5. **App info**:
   - **Callback URI / Redirect URL**: `https://bipolar.app.br/auth/callback.php`
   - **Website URL**: `https://bipolar.app.br`
   - **Organization name** (se pedir): `Bipolar — Diário de Humor`
   - **Organization URL**: `https://bipolar.app.br`
   - **Terms of service**: `https://bipolar.app.br/termos.php`
   - **Privacy policy**: `https://bipolar.app.br/privacidade.php`
6. **Save**. O X mostra o **OAuth 2.0 Client ID** e o **Client Secret**: copie **na hora** (o segredo não aparece de novo; se perder, use **Regenerate** em *Keys and tokens* e atualize o `config.php`).
7. No `config.php`: `oauth.x.client_id` = **OAuth 2.0 Client ID**; `oauth.x.client_secret` = **OAuth 2.0 Client Secret**. Não use a *API Key*/*Consumer Key*.

> **App público em vez de confidencial?** Se você escolher **Native App** (cliente público, sem segredo), deixe `client_secret` vazio (`''`): o app funciona só com PKCE. Mas o **recomendado** é o confidencial (Web App), porque o segredo fica guardado no servidor. O tipo escolhido no console e o `config.php` precisam combinar: confidencial **com** segredo, público **sem** segredo. Se não combinarem, o X recusa com `unauthorized_client`.

### 3.3 Limitação do e-mail
- O Bipolar pede só `users.read` e `tweet.read` e **não recebe o e-mail** de quem entra pelo X. Isso é intencional: as contas de provedores diferentes **não são unificadas** pelo e-mail (proteção contra sequestro de conta), então o e-mail não é necessário para entrar.
- Consequência: quem entra pelo X precisa informar um e-mail em **Conta → Preferências** para receber lembretes, alertas e relatórios por e-mail.
- O X até oferece o escopo `users.email` (com “Request email from users” ligado e links de privacidade e termos preenchidos), mas o Bipolar **não o usa** nesta versão. Ligar a opção no console não muda nada no app.

---

## 4. Lista de testes (faça para cada provedor)

Use uma **janela anônima** a cada teste.

1. [ ] Abra `https://bipolar.app.br`: o botão do provedor aparece. Os provedores ainda não configurados **não** aparecem.
2. [ ] Clique no botão: a página do provedor abre (Google, Microsoft ou X), mostrando o nome do app (ou o domínio `bipolar.app.br`, no Google sem verificação de marca).
3. [ ] Entre e autorize: você volta para `https://bipolar.app.br/` **já dentro do app**, sem mensagem de erro.
4. [ ] Em **Conta**, confira nome e e-mail (no X o e-mail fica vazio: preencha e salve).
5. [ ] Faça um registro de humor, clique em **Sair** e entre de novo com o mesmo botão: o registro continua lá (mesma conta, não criou outra).
6. [ ] **Microsoft:** teste uma conta **pessoal** (Outlook/Hotmail) e, se tiver, uma de **trabalho/escola**.
7. [ ] **Google:** teste com uma conta Google que **não** é a dona do projeto (para confirmar que o app saiu do modo de teste).
8. [ ] Clique no botão e, na tela do provedor, **cancele**: o app mostra “Login cancelado ou negado pelo provedor.” e o botão “Voltar e tentar novamente”.
9. [ ] Se você tiver ativado a verificação em duas etapas do Bipolar numa conta de e-mail e senha: entrar com Google/Microsoft/X **não** pede o código do Bipolar (a segunda etapa é a do provedor). Isso é esperado.
10. [ ] Anote no calendário a **data de vencimento do segredo da Microsoft**.

---

## 5. Erros comuns

| O que aparece | Causa provável | Como resolver |
|---|---|---|
| Google: **Erro 400: redirect_uri_mismatch** | A URL de retorno no console não é idêntica | Em **Clientes** → seu cliente → URIs de redirecionamento: exatamente `https://bipolar.app.br/auth/callback.php` (sem `www`, sem barra final, sem `?provider=`). Aguarde alguns minutos. |
| Google: **Erro 403: access_denied** / “O app está sendo testado” | Status **Em teste** | **Público-alvo** → **Publicar app**. |
| Google: **Erro 401: invalid_client** ou “Falha ao obter token do provedor.” | ID ou chave secreta errados, com espaço, ou chave antiga | Copie de novo (ou crie uma nova chave secreta) e cole sem espaços no `config.php`. |
| Google mostra `bipolar.app.br` em vez do nome e do logo | Marca não verificada | Normal. Para mostrar nome e logo, faça a verificação da marca (quadro da seção 1.2). |
| Microsoft: **AADSTS50011** (*redirect URI ... does not match*) | URL de retorno ausente ou diferente, ou cadastrada em plataforma errada (SPA/celular) | **Autenticação** → plataforma **Web** → `https://bipolar.app.br/auth/callback.php`. |
| Microsoft: **AADSTS700016** (*application ... was not found in the directory*) | `client_id` errado (copiou o ID do objeto ou do locatário) | Use o **ID do aplicativo (cliente)** da Visão geral. |
| Microsoft: **AADSTS7000215** (*invalid client secret*) | Copiou o **ID do segredo** em vez do **Valor**, ou o valor ficou cortado | Crie um novo segredo e copie a coluna **Valor**. |
| Microsoft: **AADSTS7000222** (*client secret keys ... are expired*) | O segredo venceu | Crie um novo segredo, troque no `config.php`, teste e apague o antigo. |
| Microsoft: *unauthorized_client: The client does not exist or is not enabled for consumers* / **AADSTS50020** | Tipo de conta do registro não aceita contas pessoais | O tipo precisa ser **Any Entra ID Tenant + Personal Microsoft accounts**. Se o registro foi criado com outro tipo, o mais simples é criar um registro novo com o tipo certo. |
| Microsoft: “Precisa da aprovação do administrador” | A empresa/escola da pessoa bloqueia apps de terceiros | Não depende do Bipolar. A pessoa pode usar conta pessoal, Google ou e-mail e senha. |
| X: “**Something went wrong** / You weren't able to give access to the App” | Callback diferente do cadastrado, ou *User authentication settings* não configurado | Revise a seção 3.2: Callback exatamente `https://bipolar.app.br/auth/callback.php`, **Save**. |
| X: “Falha ao obter token do provedor.” | Tipo de app e segredo não combinam, ou segredo regenerado | Web App ⇒ segredo preenchido; Native App ⇒ segredo vazio. Cole o Client Secret mais recente. |
| X: “Falha ao obter dados do perfil.” | Sem créditos no modelo pago por uso (ou app sem acesso à API v2) | **Billing → Credits** no console do X, ou desligue o X no `config.php`. |
| Bipolar: “**Sessão de login expirada. Tente novamente.**” | Passaram mais de 10 minutos na tela do provedor, ou o login começou em `www.bipolar.app.br` e voltou para `bipolar.app.br` (ou o contrário) | Tente de novo, sempre a partir de `https://bipolar.app.br` (sem `www`). |
| Bipolar: “**Parâmetro state inválido.**” | Duas abas de login abertas ao mesmo tempo, ou link de login antigo | Feche as outras abas e clique no botão de novo. |
| Bipolar: “**Muitas tentativas. Aguarde alguns minutos.**” | Limite de segurança (20 inícios de login a cada 5 minutos por conexão) | Aguarde 5 minutos. |
| O botão não aparece | Valor vazio ou ainda de exemplo no `config.php`, ou `'tenant'` apagado | Confira o bloco `'oauth'` (seção 0). Microsoft e Google precisam de ID **e** segredo. |
| Site com **erro 500** depois de editar | Erro de digitação no `config.php` (aspas, vírgula) | Restaure `config.php.bak` e edite de novo com calma. |

Os detalhes técnicos de cada falha ficam no log de erros do PHP: **cPanel → Métricas → Erros** (ou o arquivo `error_log` na pasta do site), em linhas que começam com `OAuth google:`, `OAuth microsoft:` ou `OAuth x:`. Nenhum segredo é gravado no log.

---

## 6. Perguntas frequentes

**Posso usar a mesma URL de retorno nos três?** Sim. O app sabe qual provedor está voltando pelo login iniciado na sessão (parâmetro `state`).

**Já tinha cadastrado a URL antiga com `?provider=google`. Preciso mudar?** Sim, a partir da V4.4 o app envia a URL nova. Adicione `https://bipolar.app.br/auth/callback.php` no console (pode manter a antiga até testar). Se por algum motivo precisar manter a antiga por um tempo, dá para forçar por provedor no `config.php` com `'redirect_uri' => 'https://bipolar.app.br/auth/callback.php?provider=google'` dentro do bloco do Google. **Na Microsoft isso não funciona** (ela não aceita parâmetros com contas pessoais).

**A pessoa entrou com Google e depois com e-mail e senha usando o mesmo e-mail. Por que viraram duas contas?** É proposital: contas de provedores diferentes não são unificadas automaticamente pelo e-mail, para impedir que alguém crie uma conta num provedor com o e-mail de outra pessoa e entre na conta dela. Oriente a pessoa a usar sempre o mesmo jeito de entrar.

**E a verificação em duas etapas do Bipolar?** Ela vale para contas de **e-mail e senha** (Conta → Verificação em duas etapas). Quem entra com Google, Microsoft ou X usa a verificação em duas etapas da própria conta do provedor, e o Bipolar não pede um segundo código.

**Que dados o Bipolar recebe?** Do Google e da Microsoft: um identificador da conta, o nome e o e-mail (no Google, só se o e-mail for verificado). Do X: o identificador e o nome. Nunca a senha, os contatos, os posts ou qualquer outra coisa. Isso está descrito em `https://bipolar.app.br/privacidade.php`.
