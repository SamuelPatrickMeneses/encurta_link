#!/usr/bin/env bash
#
# Wrapper do GitHub MCP Server (stdio) para o OpenCode.
#
# Existe porque o OpenCode nao le ficheiros .env: {env:VAR} e resolvido contra
# o ambiente do processo do servico, e esse ambiente nao contem o token. Este
# script le-o de ~/.config/opencode/.env, que fica FORA do repositorio.
#
# Transporte, por ordem de preferencia:
#   1. binario nativo em PATH (github-mcp-server) — sem contentor, arranque
#      imediato. Instalar com:
#        curl -sSL -o /tmp/g.tgz \
#          https://github.com/github/github-mcp-server/releases/download/v<V>/github-mcp-server_Linux_x86_64.tar.gz
#        tar xzf /tmp/g.tgz -C /tmp && install -Dm755 /tmp/github-mcp-server ~/.local/bin/
#   2. imagem Docker ghcr.io/github/github-mcp-server, se o binario nao existir
#
# O token nunca e escrito no argv: e exportado no ambiente e o binario le-o de
# GITHUB_PERSONAL_ACCESS_TOKEN. Com o Docker usa-se "-e NOME" (sem "=valor")
# para o token tambem nao aparecer em `ps`.
#
# Sobreposicao:   OPENCODE_ENV_FILE=/caminho/para/.env
# Sem token:      sai com codigo 1 e mensagem no stderr, em vez de deixar o
#                 servidor cair no fluxo OAuth do GitHub (que so falha depois
#                 de um timeout e sem dizer a causa real).

set -euo pipefail

ENV_FILE="${OPENCODE_ENV_FILE:-$HOME/.config/opencode/.env}"
TOKEN_VAR="ENCURTA_LINK_GITHUB_TOKEN"
IMAGE="ghcr.io/github/github-mcp-server"
TOOLSETS="${GITHUB_TOOLSETS:-repos,issues,pull_requests}"

if [ ! -r "$ENV_FILE" ]; then
    echo "github-mcp: nao consigo ler $ENV_FILE" >&2
    exit 1
fi

token="$(sed -n "s/^${TOKEN_VAR}=//p" "$ENV_FILE" | head -n 1)"
# o .env pode ter o valor entre aspas
token="${token%\"}"; token="${token#\"}"
token="${token%\'}"; token="${token#\'}"

if [ -z "$token" ]; then
    echo "github-mcp: $TOKEN_VAR ausente ou vazio em $ENV_FILE" >&2
    exit 1
fi

export GITHUB_PERSONAL_ACCESS_TOKEN="$token"
export GITHUB_TOOLSETS="$TOOLSETS"

if command -v github-mcp-server >/dev/null 2>&1; then
    exec github-mcp-server stdio
fi

echo "github-mcp: binario nativo ausente, a usar a imagem $IMAGE" >&2
exec docker run -i --rm \
    -e GITHUB_PERSONAL_ACCESS_TOKEN \
    -e GITHUB_HOST \
    -e GITHUB_TOOLSETS \
    "$IMAGE"
