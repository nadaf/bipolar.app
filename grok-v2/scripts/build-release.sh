#!/usr/bin/env bash
# Mantido por compatibilidade: o pacote agora e gerado por scripts/build-cpanel-zip.sh.
exec "$(dirname "$0")/build-cpanel-zip.sh" "${1:-dist/bipolar-release.zip}"
