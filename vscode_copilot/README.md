# bipolar.app

Aplicativo em português brasileiro para acompanhamento pessoal e organização de conversas com a equipe de cuidado. Esta versão é estática e instalável (PWA), com dados locais; ainda não inclui API, contas, autenticação OAuth, sincronização ou envio agendado. A cobertura de funcionalidades de outros produtos é uma meta de produto, não uma alegação de superioridade clínica.

## Acompanhamento nesta versão

- Registros por período de humor, energia, cognição percebida, velocidade e conteúdo dos pensamentos, sono e qualidade do sono, atividade física, tempo aproximado ao ar livre/luz do dia e horário aproximado, refeições com ultraprocessados, notas e anexos de áudio/vídeo.
- Gráfico pessoal de humor, energia e clareza mental percebida, com janelas de 7, 30 e 90 dias.
- Lista de medicamentos conforme prescrição, marcação diária de registro da dose e lembretes locais opcionais enquanto o app está aberto; agenda pessoal de consultas.
- Resumos revisáveis com seleção explícita para incluir medicamentos e consultas. O espaço íntimo cifrado nunca é incluído.
- Biblioteca inicial com fontes públicas e indicação de limites de apoio/emergência.
- Área íntima opcional, acessada por botão discreto, protegida por frase-senha local e cifragem AES-GCM. Ela bloqueia após cinco minutos sem atividade e quando a página fica oculta.

Os valores do gráfico são descrições de autorrelato, não escalas clínicas validadas. O tempo ao ar livre/luz do dia é anotado manualmente: o navegador não mede exposição ao sol nem fornece dados de clima. Não use o campo para definir metas de exposição; proteja-se de UV e siga orientações profissionais locais.

## Abrir localmente

O app não depende de Node.js ou de bibliotecas externas. Sirva a pasta por HTTP local (necessário para testar o service worker e a instalação):

```powershell
py -m http.server 8000
```

Abra `http://localhost:8000`. Para instalar como aplicativo, publique em HTTPS e use um navegador compatível. O service worker mantém a interface disponível offline; os registros ficam apenas no `localStorage` do navegador deste dispositivo.

## Escala de humor

A escala inicial tem sete opções subjetivas, de depressão intensa a euforia. Os rótulos são um ponto de partida, não diagnóstico nem substituto de avaliação clínica. Revise a escala com sua psicóloga e adapte os nomes ao vocabulário que vocês combinarem.

## Privacidade e estado desta versão

- Registros gerais, tratamentos, consultas e preferências são armazenados localmente no navegador, sem criptografia, conta, backup ou sincronização. Revise antes de compartilhar e não use em dispositivo compartilhado.
- Os anexos de áudio/vídeo são guardados no IndexedDB deste navegador, sem criptografia e sem envio/sincronização. O limite por arquivo é 25 MB; evite aparelhos compartilhados e confira o espaço disponível.
- Relatórios são gerados sob ação do usuário e podem ser compartilhados pelo mecanismo do dispositivo ou baixados como texto. O app não envia e-mail nem agenda envios.
- Lembretes locais usam a API de notificações do navegador e só disparam enquanto a página está aberta; não são um serviço confiável de notificações em segundo plano.
- A área íntima cifra seus registros no dispositivo usando uma chave derivada da frase-senha e Web Crypto. O app não armazena essa frase nem oferece recuperação; se você a perder, os registros não podem ser abertos. Isso não protege um dispositivo desbloqueado, extensões maliciosas ou a página/app comprometidos; o nome do app, a navegação e o próprio botão não são ocultados. Não inclua nomes ou dados identificáveis de outras pessoas.
- A opção de marcar uma dose registra uma anotação pessoal; não confirma a ingestão e não substitui orientação médica. Lembretes são neutros e não mostram o nome do medicamento.
- As entradas de relatório podem incluir informações sensíveis. A seleção para incluir medicamentos/consultas é desativada por padrão; os registros da área íntima ficam sempre de fora.
- A PWA não é ferramenta de diagnóstico, tratamento, monitoramento clínico nem serviço de emergência. Em risco imediato, procure serviços locais de urgência e uma pessoa de confiança.

## Hospedagem

Para testar a interface, hospedagem estática em HTTPS no cPanel pode ser suficiente. A configuração de domínio personalizado para `bipolar.app.br` deve apontar o DNS para a hospedagem e emitir certificado TLS; mantenha `bipolar.app` e `bipolar.app.br` alinhados no DNS caso ambos sejam usados.

Para uso real com contas, dados de saúde, mídia, relatórios programados e notificações, implemente uma API autenticada antes de coletar dados de usuários. Um VPS gerenciado dá mais controle sobre versões, filas, backups, atualizações e limites de upload; cPanel pode servir uma API PHP se oferecer PHP 8.2+, TLS, Composer/SSH, cron, banco suportado e limites adequados. Confirme essas condições com o provedor. MySQL 5.x antigo não é uma base recomendada para uma aplicação nova; prefira MySQL 8.0 ou MariaDB atual suportado pelo host.

### Roteiro de backend PHP para cPanel

1. **Verifique o plano antes de escolher o host**: confirme PHP 8.2+, extensões `pdo_mysql`, `openssl`, `mbstring`, `curl`, `fileinfo` e `sodium`, Composer por SSH, cron jobs, TLS, versão e limites do banco, espaço para anexos e possibilidade de configurar o document root. Se o painel só oferecer PHP legado/MySQL 5 antigo, ou não permitir manter dependências e tarefas agendadas, use VPS gerenciado atualizado em vez de expor dados de saúde nessa hospedagem.
2. **Prepare DNS e HTTPS**: aponte `bipolar.app.br` para o cPanel, habilite AutoSSL e force HTTPS. Se preferir separar a API, crie `api.bipolar.app.br` com certificado próprio. Não configure OAuth antes de confirmar que as URLs HTTPS finais funcionam.
3. **Crie recursos isolados**: pelo MySQL Database Wizard, crie banco e usuário exclusivos do app e conceda apenas os privilégios necessários nesse banco. Guarde os dados em variáveis de ambiente ou arquivo de configuração fora de `public_html`; nunca os envie ao Git. Faça backup e teste a restauração.
4. **Publique a aplicação PHP**: desenvolva com Laravel (PHP 8.2+) e PDO; mantenha código e dependências fora do document root e exponha apenas `public/`. Se o plano cPanel não permitir isso, não copie o `.env`, `vendor/`, uploads ou dumps para uma pasta pública. Use SSH/Composer e siga o procedimento de deploy/rollback do host.
5. **Configure provedores de identidade**: registre apps OAuth separados para Google, Microsoft e X, com URLs de callback exatas para produção e desenvolvimento. Armazene client IDs/secrets no servidor. Use OpenID Connect para Google/Microsoft quando disponível e OAuth 2.0 para X; valide retorno, `state`, PKCE quando suportado e tokens. Vincule contas pelo identificador estável do provedor + issuer, não apenas pelo e-mail, e não solicite permissões para publicar/tuitar.
6. **Implemente API e schema**: use migrações Laravel para contas, entradas de humor, anexos privados, preferências de lembretes/relatórios e consentimentos. Cada entrada precisa de dono, data, período, nível de 1 a 7, nota opcional, sono/energia e timestamps. Cada query/mutation deve limitar pelo usuário autenticado. Inclua exclusão de conta/dados e exportação desde o início. Não armazene tokens OAuth se a sessão do servidor puder atender ao caso.
7. **Migre o frontend com cuidado**: troque o armazenamento local por endpoints versionados, por exemplo `GET/POST /api/v1/entries`, `GET /api/v1/entries/{id}`, `DELETE /api/v1/entries/{id}`, `GET /api/v1/reports/preview` e endpoints de preferências. Não envie anotações à API antes de a sessão e o consentimento estarem ativos. Restrinja CORS à origem publicada.
8. **Implemente tarefas agendadas**: use cron do cPanel para um comando Laravel agendador/fila; envie relatórios diários, semanais, mensais ou personalizados apenas com consentimento e confirmação do destinatário. Inclua link seguro para cancelar. Para push, use Web Push com opt-in revogável e texto neutro; notificações locais não são garantidas com o navegador fechado.
9. **Faça revisão antes de lançar**: teste isolamento entre duas contas, CSRF, OAuth/callbacks, limites de upload, exportação/exclusão, backups e links de mídia expirados. Configure e-mail transacional sem notas ou humor no assunto, logs sem payload sensível, alertas sem conteúdo privado, política LGPD e processo de incidente. Só então aponte o domínio para a versão com backend.

### Arquitetura de backend recomendada

Além das etapas acima, mantenha PHP 8.2+ com framework mantido e dependências atualizadas; use sessão web em cookie `HttpOnly`, `Secure`, `SameSite=Lax` com CSRF, limites de tentativas e logout. O fluxo OAuth deve usar bibliotecas mantidas (por exemplo, Socialite e adaptadores compatíveis, após verificar suporte atual), `state`, PKCE quando disponível, `nonce` em OIDC, callback exato e validação de issuer/audience/assinatura/expiração. Não guarde tokens OAuth em `localStorage`.

Trate as anotações como dados pessoais sensíveis sob a LGPD: HTTPS, criptografia de volumes e backups, retenção mínima, exportação/exclusão, prepared statements, validação server-side e autorização por dono. Mídia deve ficar fora do webroot, com limite de tamanho/tipo, validação de conteúdo, nomes aleatórios, links temporários privados e exclusão verificável. Logs não devem conter humor, notas ou mídia.

Relatórios e push devem exigir consentimento explícito e revogável. Não inclua informação de saúde no assunto do e-mail ou na tela bloqueada; permita pausar/cancelar, confirme destinatários e teste isolamento entre contas, OAuth, uploads, restauração de backups e incidentes antes de armazenar dados reais.

## Próximas etapas

- Substituir o armazenamento local por API e contas antes de sincronizar entre dispositivos.
- Implementar OAuth no backend com credenciais de desenvolvimento e produção separadas.
- Persistir anexos em armazenamento privado e oferecer exclusão/exportação.
- Programar relatórios e notificações em serviços de fila/cron, respeitando as preferências de frequência e consentimento.
- Avaliar integração com Apple Saúde e outros sistemas somente mediante suporte oficial, consentimento específico e arquitetura segura; esta PWA não acessa dados de saúde do aparelho.
