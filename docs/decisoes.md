# Decisões que não se deduzem do código

O código não leva comentários. Este ficheiro guarda o que não é dedutível dele: as
razões que, esquecidas, deixam alguém a "optimizar" uma linha ou a mudar um valor
sem perceber o que estava a ser protegido.

Os contratos de domínio — o que um slug é, o que um link tem — estão em
`docs/wiki/dicionario-de-dados.md`. Aqui fica o porquê das decisões de implementação.

## Slugs

**Base 62, `[0-9A-Za-z]`, 4 a 10 caracteres, sensível à caixa.** `Ab3` e `ab3` são
slugs distintos.

Isto obriga `links.slug` a ter collation `utf8mb4_0900_as_cs`. Com a collation
insensível à caixa que o MySQL traz por omissão, o índice único trataria os dois
como o mesmo e rejeitaria um slug legítimo por diferir só na caixa. A decisão está
em `database/migrations/2026_09_26_000000_create_links_table.php` e é vigiada por
`tests/Feature/Database/LinksSlugCollationTest.php`.

**MySQL 8.4 é o mínimo.** `utf8mb4_0900_as_cs` não existe em MySQL 5.7 nem em
MariaDB. Com uma versão anterior, o teste da collation é ignorado em silêncio e o
CI fica verde sem verificar a decisão que sustenta o base 62. É a razão de o CI usar
aquela imagem específica.

**`MIN_ID` é 62³.** É o menor id cuja representação em base 62 tem quatro
caracteres; sem o piso, o primeiro link receberia um slug de três.

**`MAX_LEN` é 10 e o excedente é recusado, nunca truncado.** A coluna é
`VARCHAR(10)`. Um id que não caiba é rejeitado em vez de emitir um slug truncado:
a truncagem colidiria com outro slug, e o registo na fila seria um link que o MySQL
nunca conseguiria persistir.

**Um contador reposto reemite slugs já usados.** Um volume novo, ou uma chave
expulsa por `maxmemory`, reinicia o contador em 1. O índice único de `links.slug`
rejeita a colisão e é o chamador que tem de repetir a operação — a função não sabe
o que já foi usado.

## A Redis Function

**O `GET` a seguir ao `INCR` não é redundante.** O `INCR` devolve um número, e um
número Lua é um double de 52 bits de mantissa: acima de 2^53 já vem com algarismos
trocados. O `GET` devolve a mesma quantidade como string, com os algarismos intactos.
62⁹ — o primeiro slug com dez caracteres — vale 1.35e16, acima de 2^53. Sem esta
leitura extra, todo o slug com dez caracteres seria silenciosamente errado.

**A validação acontece antes do `INCR`.** O Redis não desfaz o que um script já
escreveu quando este devolve um erro. Incrementar primeiro queimaria um slug sem
fila onde o link ficar registado.

**As chaves de `KEYS` chegam prefixadas e a função não as prefixa. A chave da cache
é a excepção, porque é construida lá dentro.** O phpredis aplica o prefixo da
conexão, e a função usa as chaves de `KEYS` exactamente como chegam. É isso que
mantém a fila dentro do mesmo namespace do phpredis e da fila de jobs
(`QUEUE_CONNECTION=redis`), sem duplicar a descoberta do prefixo dentro do Lua.

Mas `link:<slug>` só existe depois de o slug ser calculado, e não pode ser uma
`KEYS`. A função escreve essa chave a partir de um prefixo que recebe em `ARGV` — e
tem de ser o prefixo **completo**, prefixo do phpredis incluído, não só `link:`.
Dentro do Lua o servidor não sabe nada do cliente: com `link:` a função
escreveria `link:abc` e o PHP, ao ler, procuraria
`encurta-link-database-link:abc`. O acerto de cache nunca chegava a acontecer, e
nenhum teste o denunciava porque não havia nenhum que lesse a chave. É o que
`cachePrefixCompleto()` resolve, e o que o teste
`testAChaveQueOLuaEscreveEAQueOPhpLeria` fixa — verificado reintroduzindo o erro:
caiem oito testes, entre eles o do `LinkDTO`.

**A função escreve a cache no momento da criação, e por isso o payload não tem
`id`.** O `SET link:<slug>` passou para dentro do `gerar_slug`, na mesma execução
atómica do `INCR` e do `XADD`. É o que faz `/r/<slug>` responder assim que o slug é
reservado, sem esperar pelo consumidor da fila. O que a função escreve é
`{url, exp}` e nada mais: a linha ainda não existe, e um `id` ali seria uma
mentira. Quem o acrescenta é a leitura ao MySQL, que reescreve a entrada já com
`{url, exp, id}`.

**É o TTL de 24 h que dá a essa correcção a hipótese de acontecer.** A entrada
escrita na criação fica sem `id` até uma leitura ao MySQL a reescrever. Se essa
entrada nunca expirasse, ficava `{url, exp}` para sempre e o `LinkDTO` também: o
`link_id` do registo de clique nunca chegaria a existir. O tecto não é política de
memória, é o mecanismo que deixa a entrada corrigir-se sozinha.

**O TTL é calculado em PHP e aplicado em Lua, num único `ARGV`.** A regra
`min(86400, segundos até expires_at)` vive em `ttlFor()`, num sítio só. A função
recebe o resultado em `<ttl>` e limita-se a `SET ... EX <ttl>`, saltando a escrita
quando é zero. A alternativa era a função refazer a regra com `TIME`, o que a
duplicava em duas linguagens: se as duas divergissem a cache mentia em silêncio, e
quem nela confia é o leitor. Passar pelo PHP também desliga a conversa do desvio de
relógio — o TTL e o `exp` do payload saem ambos do relógio da aplicação. E o
desvio que sobrasse seria inofensivo, porque `findBySlug()` volta a validar
`isExpired()` no payload: o TTL é memória, não correcção.

**O tecto de 24 h não limita o link, limita a entrada de cache.** Um link com
`expires_at` nulo é permanente. O que acontece é que a cada 24 h a chave expira, o
pedido seguinte leva um acerto falhado, vai ao MySQL, e a linha lá está sem prazo e
responde normalmente: ninguém vê nada. O MySQL é consultado uma vez por dia, por
link permanente.

**`generate()` ganhou o `$expiresAt`, e o `XADD` ganhou o `exp`.** O registo da
fila tem de levar a expiração, ou o consumidor não consegue inserir a linha com o
`expires_at` certo. `exp` viaja como timestamp Unix, e é omitido — não vazio —
quando o link não expira, para o consumidor distinguir «não expira» de «expirou e
não sei quando».

**A fila é um STREAM, não uma lista.** É para que um consumidor que morra a meio
deixe a entrada pendente em vez de a perder.

**O id da entrada é a chave de deduplicação do consumidor.** O campo `*` deixa o
Redis gerar o id, de modo que um reenvio carrega sempre o mesmo slug.

**`usuario` viaja como string vazia, nunca ausente.** O STREAM não tem nulls, e um
registo por consumidor implica sempre os mesmos campos. O consumidor converte-a de
volta em `NULL`, que é o que `links.user_id` aceita para links anónimos.

**As funções vivem fora do keyspace.** `FLUSHDB` não as remove; só `FUNCTION FLUSH` o
faz. Por isso o `flushdb` do `setUp` da suite de integração não as limpa.

## O arranque do Redis

**O AOF grava a biblioteca, mas devolve a versão que foi carregada, não a do
ficheiro.** Reiniciar sem `docker/redis/start.sh` depois de editar
`docker/redis/encurtador.lua` continua a executar a versão anterior, sem qualquer
erro. Verificado: com o ficheiro numa segunda versão e o AOF com a primeira, o Redis
continuava a responder como a primeira. Carregar do ficheiro em cada arranque
resolve esse caso e o do volume novo, que não tem AOF de onde restaurar.

**O `redis-cli` devolve 0 mesmo numa resposta de erro.** Em modo não interactivo o
estado de saída não diz nada; o que decide é o texto da resposta. Um `ERR` no início
é a assinatura de uma biblioteca recusada.

**O script invoca o entrypoint da imagem em vez do `redis-server` directamente.** O
entrypoint trata do `chown` de `/data` e desce para o utilizador `redis`. Chamá-lo
preserva esse comportamento em vez de o reimplementar e divergir dele um dia.

**O `trap` espera pelo servidor antes de sair.** Sem essa espera o script saía no
SIGTERM, o contentor morria, e o Docker matava o Redis a meio da gravação do AOF.

## A aplicação

**A classe chama-se `LinkService`, e gerar slugs é só uma das cinco coisas que
ela faz.** Reserva, lista, apaga, invalida cache e resolve slugs. `SlugGenerator`
foi o nome certo enquanto só reservava; a partir de `listForUser()` já não era, e
continuou a ser usado só porque a renomeação não tinha sido pedida. Passou a
`LinkService` com o resto do código, dos testes e da documentação, e o ficheiro de
teste acompanhhou: `SlugGeneratorLinksTest` passou a `LinkServiceTest`.

**`LinkService` não carrega a biblioteca.** `docker/redis/start.sh` garante que a
função está registada em cada arranque. Se a função faltar, a criação de links falha
com a mensagem do Redis (`ERR Function not found`) em vez de se recuperar sozinha. É
o preço de a garantia passar a estar no arranque do contentor e não em cada pedido.

**O `false` do phpredis 6 é convertido em excepção.** O phpredis 6 deixou de lançar
em erros de resposta do servidor: devolve `false` e guarda a mensagem em
`getLastError()`. Sem essa normalização, `Function not found` chegaria ao chamador
como "devolveu um valor inesperado", que não aponta para a causa — e é a única pista
que resta, porque a aplicação já não carrega a biblioteca.

**`fcall` não é repetido.** Não consta da lista `RETRYABLE_COMMANDS` do Laravel, que
só inclui comandos idempotentes, e não deve ser acrescentado: repetir a reserva
gastaria um segundo slug e deixaria um segundo registo na fila sem que ninguém o
tenha pedido.

**A suite de integração carrega a biblioteca no `setUp`.** `docker/redis/start.sh`
garrega-a em cada arranque, mas a suite não deve depender desse estado ambiente — e
como um teste a descarrega de propósito, repô-la no `setUp` é o que impede esse
teste de envenenar os seguintes. O serviço Redis do CI também não a carrega:
GitHub Actions não permite montar volumes em contentores de serviço.

## Isolamento dos testes

**`REDIS_DB=1` é a protecção real, não o prefixo.** O `flushdb` do `setUp` ignora o
prefixo e apaga a base inteira. `REDIS_PREFIX=encurta-link-testing-` evita que a suite
colida com nada de outro ambiente, mas só dentro dessa base.

## Testes que protegem contra regressões

Alguns testes existem por causa de bugs que já existiram. O nome do teste não
diz o suficiente para ninguém inferir que o removê-lo é seguro.

**Uma reserva é exactamente um `FCALL`.** Uma versão anterior corria um segundo
`FCALL` só para inspeccionar o retorno e, se a função faltasse, repetia a reserva
depois de a carregar. O `FCALL` extra gastava um slug e deixava na fila um registo
que ninguém tinha pedido. É o que
`RedisFunctionContractTest::testReservaGastaUmaUnicaChamadaAoRedis` fixa, com
`shouldNotReceive('executeRaw')` a travar a segunda via.

**A função ausente tem de falhar, e tem de falhar sem trabalho a meio.**
`RedisFunctionIntegrationTest::testAplicacaoNaoCarregaABibliotecaFaltaEla` faz
`FUNCTION FLUSH` e depois verifica, para além da excepção, que o contador não
avançou e que a fila não ganhou entradas. A asserção do contador é a que prova
que a falha não foi recuperada às custas de uma segunda tentativa — que é
exactamente o defeito que a remoção do fallback eliminou.

**O formato do slug não distingue base 36 de base 62.** Ambos passam em
`/^[0-9A-Za-z]+$/`. O que os distingue é a presença dos dois conjuntos de caixa,
e é por isso que existe um teste separado que reserva 200 slugs e exige minúsculas
*e* maiúsculas. Apagá-lo parece limpo; o efeito é devolver a suite a aceitar
silenciosamente um alfabeto de 36.

**O `getLastError` tem de ser lido.** O phpredis 6 devolve `false` sem lançar, e
`false` isolado não distingue "função ausente" de "ligação perdida". Há testes para
as duas convenções — `false` mais `getLastError()`, e excepção — porque as duas têm
de chegar ao chamador com a mensagem do servidor.

**O `assertIsArray` de `primeiroRegisto` não é redundante.** A facade declara
`xrange` como `\Redis|array|bool`, e o PHPStan não estreita essa união sozinho. O
`assertIsArray` traz `@phpstan-assert` e o `@var` em seguida fixa a forma exacta
que o phpredis devolve para um STREAM — id do campo para o valor. `Redis::scalar()`
em vez de `selectOne()` no teste da collation é pelo mesmo motivo: `selectOne()`
devolve `mixed`.

**A `links.slug` tem de distinguir caixa.** É a decisão que sustenta o base 62, e
`LinksSlugCollationTest` é o que a vigia. Ver a secção de slugs acima.

**O cache é autoritário até ser invalidado.** Alterar a linha de `links` por trás
do `link:{slug}` não se vê: a leitura continua a devolver o valor antigo até o TTL
ou até um `forget()`. Não é um defeito, é o que o cache é, e é a razão de o fluxo
05 apagar a chave ao editar. O teste que fixa este comportamento é
`testAlterarALinhaAtrasDoCacheSoSeVedeDepoisDeInvalidar`, porque a tentação de o
tratar como bug é real.

**O `id` do valor cacheado é o que decide o tipo devolvido.** A cache guarda
`{url, exp, id}`. Com `id`, `findBySlug()` devolve a entidade `Link` e `exists()` é
verdadeiro, porque um `id` guardado implica linha já criada. Sem `id`, devolve um
`LinkDTO` — é o caso do consumidor do STREAM, que escreve a cache na criação antes
de a linha existir, e nesse intervalo o slug já responde mas ainda não há `id`.
Ver «`findBySlug()` devolve `Link` ou `LinkDTO`».

## A cache de slugs

**A cache vive na conexão `default` do Redis, pela facade `Redis`, não pela
facade `Cache`.** A facade `Cache` com `CACHE_STORE=redis` escreve na base 1 com
o `CACHE_PREFIX`, que em `.env.example` está vazio; a chave passaria a ser
`link:abc123` sem qualquer prefixo e deixaria de partilhar namespace com a fila.
Usar `Redis::` mantém a chave em `encurta-link-database-link:abc123`, como o
fluxo 03 assume e como o teste `testFuncaoNaoEscreveRelacaoDoSlug` já antecipava.

**O valor cacheado é `{url, exp, id}`, em JSON, com `exp` como timestamp Unix.** O
fluxo 03 assume `{url, expires_at}`; o fluxo 02 escrevia só `= url`, o que é
insuficiente — sem a expiração, um acerto de cache não distingue um link expirado
de um válido e o `link:{slug}` continuava a ser servido durante as 24 horas do
TTL. O fluxo 02 foi corrigido. O `id` veio depois, com a decisão do DTO. O JSON e
não PHP `serialize` porque `redis-cli` continua a mostrar o valor legível.

**O TTL é `min(86400, segundos até `expires_at`)`, e um link já expirado não é
cacheado.** O TTL de um link expirado seria negativo ou zero, e uma chave com TTL
zero é um `DEL` disfarçado. Deixá-lo por cachear obrigaria a um TTL de um segundo
e não compraria nada: o slug expirado volta ao MySQL a cada pedido, o que é o
custo de um link morto.

**Uma entrada de cache corrompida ou com formato inesperado é apagada e a
resolução segue para o MySQL.** Sem isso, uma escrita errada envenenava todos os
pedidos desse slug durante 24 horas, e nenhum deles chegaria ao MySQL para se
repor.

**A exclusão apaga a cache antes de apagar a linha.** Se o MySQL falhar a seguir,
o link fica sem cache e a leitura seguinte repovoa-o. Ao contrário, apagar a linha
primeiro deixaria um link apagado a ser servido a partir do cache até ao TTL.

**`delete()` recebe o id e não verifica a posse.** A verificação é do
`LinkPolicy`, no exterior, e é por isso que o serviço recebe o id: um slug é
enumerável a partir de quatro caracteres em base 62, pelo que um serviço que
apagasse o slug que lhe passassem sem checar a posse permitiria apagar links de
outras pessoas. O serviço depor-se a conhecer a regra de autorização seria o
mesmo erro noutro sítio.

**`findBySlug()` devolve `Link` quando o `id` é conhecido, e `LinkDTO` quando não
é.** O `id` do modelo ficou `int`, porque no MySQL é sempre. Só o intervalo em que
a criação ainda não está confirmada — o consumidor do STREAM escreve a cache antes
de a linha existir — é que devolve DTO. Podia ter-se afrouxado o modelo para
`int|null` e devolvido sempre `Link`, mas aí `null` passava a ser um valor normal
do modelo e nenhum consumidor distinguiria «não sei» de «não existe», que é
exatamente o que o `linkId` anulável dizia. O DTO isola o caso em vez de
espalhá-lo pelo modelo inteiro.

**Um `Link` reidratado da cache nunca é reidratado sem `id`.** Com `id`,
`exists()` é verdadeiro e não é mentira, porque o consumidor escreve a cache
depois de inserir a linha. Sem `id` não se reidrata modelo nenhum: devolve-se o
DTO. Rehidratar com `newFromBuilder()` e `exists() === true` sem `id` seria
precisamente a mentira que o DTO existe para evitar.

**`forget()` existe para o formato da chave não vazar para um controller.** O
fluxo 05 invalida a cache ao editar, e tem de o fazer sem apagar a linha. Sem o
método, a única forma seria o controller escrever `link:` à mão, e a chave
passaria a ter duas donas.

**Uma falha do Redis propaga em vez de cair no MySQL.** É a mesma decisão
aplicada à função ausente: uma falha de infraestrutura chega ao chamador com a
mensagem do servidor, em vez de ser disfarçada por um caminho alternativo. Um
Redis em baixo é visível; engolido, vira um MySQL lento sem ninguém a saber porquê.

## Nomenclatura

**Um DTO chama-se `<ClasseDeModelo>DTO`, e um Command chama-se
`<AçãoEmInglêsCamelCase>Command`.** `LinkDTO`, `CreateLinkCommand`. O DTO é o que
a camada de serviço devolve para o controller e a view; o Command é o que traz os
dados do utilizador. A acção vai em inglês e em CamelCase mesmo sendo o resto do
código em português, para que o nome do comando se leia como o que faz.

**O `Command` está definido mas ainda não existe nenhuma classe que o siga.** A
primeira decisão sobre isto era outra — os dados do utilizador iam num
`*Command` com as regras de validação dentro — e foi posta de parte para não
duplicar o que o framework já faz. A convenção fica escrita para quando aparecer
um caso em que o `FormRequest` não chega; o `LinkDTO` já segue a parte do DTO.

**Um `Request` estende `Illuminate\Http\Request` e valida; não há `FormRequest` no
projecto.** O fluxo 02 nomeia `StoreLinkRequest`, que é a convenção do Laravel para
isto, e a escolha foi estender `Illuminate\Http\Request` directamente em vez de
`Illuminate\Foundation\Http\FormRequest`. A vantagem é o `Request` ficar com o que
o formulário tem — `boolean()`, `string()`, `integer()`, `date()` — e devolver
objectos de modelo já construídos, em vez de passar ao controller um array. A
desvantagem é perder o 422 automático e o `validated()` do `FormRequest`: a
validação e a resposta de erro passam a ser explícitas. Ainda não há nenhum
`Request` escrito, portanto nada disto está provado.

**O que não se expõe à view fica em `$hidden`.** O framework traz o trait
`HidesAttributes` e os métodos `makeHidden()` e `makeVisible()`, e é o que se usa
para esconder atributos internos em vez de os filtrar à mão na view. `Link` ainda
não declara `$hidden`.

**O `Request` é onde a forma dos dados e a validação se juntam.** A validação diz
que dados são aceitáveis; a construção diz de que forma eles existem. Por isso a
classe valida *e* constrói, em vez de ser um DTO puro — um DTO não sabe dizer o
que é válido.

**A listagem ordena por `id` descendente.** Nenhuma spec diz. O `id` desempata de
forma estável ao contrário de `created_at`, que tem empates.

**A busca é sensível à caixa, porque a coluna é.** `utf8mb4_0900_as_cs` faz o
`LIKE` distinguir maiúsculas, e é a mesma collation que sustenta o base 62.
Consequência para a interface: quem escreve `PROMO` no formulário não encontra
`promo`. É o comportamento correcto para não confundir `Ab3` com `ab3`, mas é uma
decisão de interface que ninguém chegou a rever. `%`, `_` e `\` são
escapados e procurados como literais, para que um termo com `%` não devolva a lista
inteira.

## Lacunas conhecidas

**A tabela `clicks` não tem migração.** O dicionário de dados promete que a remoção
de um link remove os seus clicks em cascata, e o fluxo 05 desenha
`DELETE links (cascade em clicks)`. Não há nada para cascatear: a cascata é uma
constraint de base de dados e a migração não existe. `delete()` não faz nada quanto
a clicks, e não pode.

**Não existe `LinkPolicy`.** `delete()` e a edição dependem de uma verificação de
posse que está no fluxo 05 e em nenhum ficheiro. Enquanto não existir, nada impede
um controller de chamar `delete()` com o id que lhe derem.

**O consumidor do STREAM não existe.** Sem ele a criação é assíncrona só no papel,
e o dashboard — que lista do MySQL — não mostra o link novo. O mesmo intervalo
justifica o `LinkDTO`: entre a reserva e o consumidor a linha ainda não existe, e
é por isso que a cache escrita na criação não tem `id`.

**O job de clique precisa de um `link_id` que ainda não é escrito.** Se chegar
antes do consumidor, o `INSERT` em `clicks` falha por foreign key. Detalhe em
`docs/wiki/fluxos/03-redirecionamento.md`.
