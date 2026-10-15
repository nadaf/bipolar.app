# Como contribuir

Obrigado por querer ajudar! Este app é usado por pessoas em momentos delicados, então cuidado e clareza valem mais que pressa.

## Regras do projeto

1. **Sem área de administração.** Nada de papel de admin, painel, rota ou API que permita ver dados de outras pessoas. Isso inclui “só para debug”.
2. **Privacidade por padrão.** Dados novos e sensíveis entram desligados e com opt-in. Push e e-mails de aviso nunca levam conteúdo sensível.
3. **Português do Brasil cuidadoso** em todo texto para o usuário: claro, acolhedor, sem jargão, sem culpa. Datas dd/mm/aaaa, horário 24 h.
4. **PHP puro, sem etapa de build.** Tem que rodar em hospedagem compartilhada (cPanel) com PHP 8.1+ e MySQL 5.7.
5. **Migrações simples:** apenas `ALTER TABLE` e `CREATE TABLE IF NOT EXISTS`, sem `information_schema`, sem `;` dentro de comentários SQL. Erros #1060/#1061 (já existe) devem poder ser pulados.
6. **Acessibilidade:** WCAG 2.2 AA (o teste com axe precisa continuar com 0 violações).
7. **Nada de segredos no Git.** Use `app/config.example.php` para documentar opções novas, sempre com valores vazios.

## Fluxo

1. Abra uma issue descrevendo o problema ou a ideia (para falhas de segurança, veja [SECURITY.md](SECURITY.md)).
2. Crie um branch, faça a mudança e **adicione testes** (PHPUnit em `tests/Unit` ou `tests/Integration`).
3. Rode `tests/run-all.sh`: PHPUnit, PHPStan nível 6 e ESLint precisam passar.
4. Abra o pull request explicando o que mudou e por quê. Mudanças de texto para o usuário: cole o antes e o depois.

Ao contribuir, você concorda que sua contribuição seja licenciada sob a AGPL-3.0.

## Conteúdo clínico

Mudanças em escalas, alertas ou textos sobre saúde precisam citar a fonte (artigo, diretriz) em `docs/`. O app não diagnostica e nunca sugere mudar remédio ou dose.
