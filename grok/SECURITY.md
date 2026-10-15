# Política de segurança

Este projeto guarda dados de saúde mental. Levamos falhas de segurança muito a sério.

## Como relatar uma vulnerabilidade

- **Não abra uma issue pública.** Use o recurso “Report a vulnerability” (GitHub Security Advisories) do repositório ou escreva para **seguranca@bipolar.app.br**.
- Descreva o problema, como reproduzir, o impacto possível e, se puder, uma sugestão de correção.
- **Não acesse, altere ou baixe dados de outras pessoas** para provar a falha. Use apenas contas suas ou uma instalação local. Não faça testes de carga contra bipolar.app.br.
- Respondemos em até 7 dias e combinamos com você a data de divulgação (em geral, até 90 dias ou logo após a correção).

## Escopo

- Código deste repositório e a instalação oficial em https://bipolar.app.br.
- Fora do escopo: engenharia social, ataques físicos, negação de serviço, falhas apenas em navegadores desatualizados e problemas da hospedagem compartilhada que não dependam do código.

## Boas práticas para quem instala

- Nunca publique `app/config.php` (está no `.gitignore`). Ele contém a senha do banco, do SMTP, a chave privada VAPID e a `ai.secret_key`.
- Mantenha `app/`, `cron/`, `storage/` e `tools/` **fora** da pasta pública do site.
- Deixe `dev_login` em `false` e `loadtest_bypass_ratelimit_token` vazio em produção.
- Se uma chave vazar: troque a senha do banco/SMTP, gere novas chaves VAPID e, se for a `ai.secret_key`, gere outra (as pessoas precisarão colar suas chaves de IA de novo).

## Não existe área de administração

Por desenho, não há papel de administrador nem rota para ver dados de outras pessoas. Qualquer código que crie isso será recusado. Se você encontrar algo assim, trate como vulnerabilidade.

---

**English:** please report vulnerabilities privately via GitHub Security Advisories or seguranca@bipolar.app.br. Do not access other people's data and do not load-test the production site.
