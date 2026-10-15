#!/usr/bin/env bash
# Atalhos para o teste de carga. Uso:
#   ./run.sh smoke   → 1 usuário por 30 s (confere login/CSRF/fluxo antes do teste completo)
#   ./run.sh full    → 5, 10, 25, 50 e 100 usuários, ~2 min cada (≈ 13 min), para sozinho se erro > 5% ou p95 > 5 s
# Variáveis obrigatórias: BASE_URL, PASSWORD. Para o site real: CONFIRM_LIVE=sim e BYPASS_TOKEN.
set -euo pipefail
cd "$(dirname "$0")"
command -v k6 >/dev/null || { echo "k6 não encontrado. Instale: https://grafana.com/docs/k6/latest/set-up/install-k6/"; exit 1; }
mkdir -p results
case "${1:-smoke}" in
  smoke) STAGES=1 HOLD=30s RAMP=5s exec k6 run bipolar.js ;;
  full)  exec k6 run bipolar.js ;;
  *) echo "uso: $0 smoke|full"; exit 2 ;;
esac
