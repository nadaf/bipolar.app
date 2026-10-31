## [2.0.0-beta.1] - 2026-10-31
- Início da reescrita 2.0 (Laravel API-first, multi-tenant). Fatia vertical: registro de humor por período.
- Energia passa a ser escala bipolar -3..+3 (Muito baixa..Muito alta).
- Alertas personalizados: cada paciente escolhe os limiares baixo/alto de humor e energia (padrão −2/+2), na API e em Ajustes > Alertas.
- Registro de dias passados sem limite (período futuro continua bloqueado no fuso da pessoa).
- Clínicas gerenciam os próprios profissionais; contas de profissional solo; profissional só vê quem concedeu acesso.
- Subdomínios opcionais `<clinica>.bipolar.app.br` e `<usuario>.bipolar.app.br`, com nomes reservados; token e sessão amarrados ao tenant; cookie por host.
