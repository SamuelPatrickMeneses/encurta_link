#!/bin/sh
#
# Arranque do Redis com carregamento da Redis Function `gerar_slug`.
# Razões por trás deste script: docs/decisoes.md

set -eu

LUA=/etc/redis/encurtador.lua
FUNCAO=gerar_slug
TENTATIVAS=60

if [ ! -r "$LUA" ]; then
    echo "encurta: $LUA nao existe ou nao e legivel." >&2
    echo "encurta: o volume com o script Lua tem de estar montado no redis." >&2
    exit 1
fi

# Pelo entrypoint da imagem, e nao pelo binario: e ele que trata do chown de
# /data e da descida para o utilizador redis.
echo "encurta: a arrancar o redis-server"

/usr/local/bin/docker-entrypoint.sh redis-server "$@" &
servidor=$!

# A espera no trap e o que fecha o AOF; sem ela o contentor morre no SIGTERM e o
# Docker mata o Redis a meio da gravacao.
encerrar() {
    kill -TERM "$servidor" 2>/dev/null || true
    wait "$servidor" 2>/dev/null || true
    exit 0
}
trap encerrar TERM INT

pronto=0
i=0
while [ "$i" -lt "$TENTATIVAS" ]; do
    if ! kill -0 "$servidor" 2>/dev/null; then
        echo "encurta: o redis-server terminou antes de ficar pronto" >&2
        wait "$servidor" || exit $?
        exit 1
    fi

    if [ "$(redis-cli ping 2>/dev/null)" = "PONG" ]; then
        pronto=1
        break
    fi

    i=$((i + 1))
    sleep 0.5
done

if [ "$pronto" -eq 0 ]; then
    echo "encurta: o redis nao respondeu a PING em 30s; a desistir" >&2
    kill -TERM "$servidor" 2>/dev/null || true
    exit 1
fi

# O estado de saida do redis-cli e 0 mesmo num erro, por isso decide-se pelo texto.
if ! biblioteca=$(redis-cli -x FUNCTION LOAD REPLACE < "$LUA" 2>&1); then
    biblioteca="ERR $biblioteca"
fi

case "$biblioteca" in
    ERR*)
        echo "encurta: FUNCTION LOAD falhou: $biblioteca" >&2
        kill -TERM "$servidor" 2>/dev/null || true
        exit 1
        ;;
esac

echo "encurta: biblioteca '$biblioteca' carregada"

if redis-cli FUNCTION LIST 2>/dev/null | grep -q "$FUNCAO"; then
    echo "encurta: '$FUNCAO' registada e pronta"
else
    echo "encurta: FUNCTION LIST nao devolve '$FUNCAO' apos o carregamento" >&2
    kill -TERM "$servidor" 2>/dev/null || true
    exit 1
fi

wait "$servidor" || exit $?
