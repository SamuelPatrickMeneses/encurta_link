#!lua name=encurtador
--
-- Razões por trás deste script: docs/decisoes.md
--
-- FCALL gerar_slug 2 encurtador:contador encurtador:fila <url> <user_id> <exp> <ttl> <prefixo_cache>
--
-- <exp> e um timestamp Unix, ou texto vazio se o link nao expira.
-- <ttl> vem calculado pelo PHP: esta funcao nao decide tempo de vida, so aplica.
-- <prefixo_cache> vem em ARGV e nao em KEYS porque `link:<slug>` so existe depois
-- de o slug ser calculado. Numa instancia de um no so isso nao tem custo; sob
-- Redis Cluster a chave tocada nao estaria declarada, e a funcao deixaria de estar
-- conforme a regra de que todo o acesso a chaves passa por KEYS.

local ALPHABET = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz"
local BASE = 62

local MIN_ID = 238328
local MAX_LEN = 10

local function divmod62(decimal)
    local quociente = {}
    local resto = 0

    for i = 1, #decimal do
        local actual = resto * 10 + (decimal:byte(i) - 48)
        local digito = math.floor(actual / BASE)
        resto = actual - digito * BASE

        if #quociente > 0 or digito > 0 then
            quociente[#quociente + 1] = string.char(digito + 48)
        end
    end

    if #quociente == 0 then
        return '0', resto
    end

    return table.concat(quociente), resto
end

local function para_base62(decimal)
    local digitos = {}
    local actual = decimal

    while actual ~= '0' do
        local quociente, resto = divmod62(actual)
        digitos[#digitos + 1] = string.sub(ALPHABET, resto + 1, resto + 1)
        actual = quociente
    end

    if #digitos == 0 then
        return string.sub(ALPHABET, 1, 1)
    end

    local invertido = {}
    for i = #digitos, 1, -1 do
        invertido[#invertido + 1] = digitos[i]
    end

    return table.concat(invertido)
end

local function gerar_slug(keys, args)
    local chave_contador = keys[1]
    local chave_fila = keys[2]

    local link_antigo = args[1]
    local usuario = args[2]
    local exp = args[3]
    local ttl = args[4]
    local prefixo_cache = args[5]

    -- Antes do INCR: o Redis não desfaz escritas de um script que devolve erro.
    if link_antigo == nil or link_antigo == '' then
        return redis.error_reply('gerar_slug: o link de destino e obrigatorio')
    end

    if usuario == nil then
        usuario = ''
    end

    if exp == nil or exp == '' then
        exp = nil
    end

    -- Um ttl menor que 1 significa link ja expirado ou expiracao no limite: o
    -- PHP decidiu nao cachear, e esta funcao respeita a decisao sem a refazer.
    if ttl == nil or ttl == '' then
        ttl = 0
    end

    if type(link_antigo) ~= 'string' or type(usuario) ~= 'string' then
        return redis.error_reply('gerar_slug: o destino e o utilizador tem de ser texto')
    end

    if type(prefixo_cache) ~= 'string' or prefixo_cache == '' then
        return redis.error_reply('gerar_slug: o prefixo da cache e obrigatorio')
    end

    if exp ~= nil and tonumber(exp) == nil then
        return redis.error_reply('gerar_slug: a expiracao tem de ser um timestamp')
    end

    if tonumber(ttl) == nil then
        return redis.error_reply('gerar_slug: o ttl tem de ser um numero')
    end

    ttl = tonumber(ttl)

    redis.call('INCR', chave_contador)

    -- GET e não o retorno do INCR: acima de 2^53 o double perde algarismos.
    local bruto = redis.call('GET', chave_contador)

    if tonumber(bruto) < MIN_ID then
        redis.call('SET', chave_contador, MIN_ID)
        bruto = string.format('%d', MIN_ID)
    end

    local slug = para_base62(bruto)

    if #slug > MAX_LEN then
        return redis.error_reply('gerar_slug: espaco de slugs esgotado')
    end

    -- `exp` ausente do registo quando o link nao expira, e nao um texto vazio:
    -- quem consome a fila distingue os dois casos sem adivinhar.
    if exp == nil then
        redis.call('XADD', chave_fila, '*',
            'url', link_antigo,
            'slug', slug,
            'usuario', usuario)
    else
        redis.call('XADD', chave_fila, '*',
            'url', link_antigo,
            'slug', slug,
            'usuario', usuario,
            'exp', exp)
    end

    -- A cache escreve-se aqui, antes de a linha existir: e o que faz `/r/<slug>`
    -- responder assim que o slug e reservado. Por isso este payload nao tem `id` --
    -- a linha ainda nao esta criada. Quem acrescenta o `id` e a leitura ao MySQL,
    -- que reescreve a entrada; o ttl de 24h da regra e o que lhe da hipotese de o
    -- fazer. Ver "A funcao escreve a cache no momento da criacao" em
    -- docs/decisoes.md.
    if ttl > 0 then
        local destino = {url = link_antigo}

        if exp == nil then
            destino.exp = cjson.null
        else
            destino.exp = tonumber(exp)
        end

        redis.call('SET', prefixo_cache .. slug, cjson.encode(destino), 'EX', ttl)
    end

    return slug
end

redis.register_function('gerar_slug', gerar_slug)
