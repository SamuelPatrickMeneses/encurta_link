<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\DTOs\LinkDTO;
use App\Services\LinkService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class RedisFunctionIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->redisFunctionsAvailable()) {
            $this->markTestSkipped('Requer Redis 7+ com Redis Functions.');
        }

        Redis::flushdb();

        $this->carregarBiblioteca();
    }

    private function carregarBiblioteca(): void
    {
        Redis::executeRaw([
            'FUNCTION', 'LOAD', 'REPLACE',
            File::get(base_path('docker/redis/encurtador.lua')),
        ]);
    }

    public function testPhpunitUsaPrefixoIsolado(): void
    {
        $prefix = config('database.redis.options.prefix');
        $base = config('database.redis.default.database');

        $this->assertIsString($prefix);
        $this->assertIsString($base);

        $this->assertStringStartsWith('encurta-link-testing-', $prefix);
        $this->assertSame('1', $base);
    }

    public function testReservaSlugDentroDoFormatoDoContrato(): void
    {
        $slug = app(LinkService::class)->generate('https://exemplo.pt');

        $this->assertGreaterThanOrEqual(4, strlen($slug));
        $this->assertLessThanOrEqual(10, strlen($slug));
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]+$/', $slug);
    }

    public function testAlfabetoEmitidoTemMaiusculasEMinusculas(): void
    {
        $service = app(LinkService::class);

        $vistos = '';
        for ($i = 0; $i < 200; $i++) {
            $vistos .= $service->generate('https://exemplo.pt');
        }

        $this->assertMatchesRegularExpression(
            '/[a-z]/',
            $vistos,
            'Nenhuma minúscula em 200 slugs: o alfabeto não é base 62.'
        );
        $this->assertMatchesRegularExpression(
            '/[A-Z]/',
            $vistos,
            'Nenhuma maiúscula em 200 slugs: o alfabeto não é base 62.'
        );
    }

    public function testPrimeiroSlugJaTemQuatroCaracteres(): void
    {
        $slug = app(LinkService::class)->generate('https://exemplo.pt');

        $this->assertSame(4, strlen($slug));
        $this->assertSame('1000', $slug);
    }

    public function testSlugsSaoUnicosAoLongoDe500Reservas(): void
    {
        $service = app(LinkService::class);

        $slugs = [];
        for ($i = 0; $i < 500; $i++) {
            $slugs[] = $service->generate('https://exemplo.pt');
        }

        $this->assertCount(500, array_unique($slugs));
    }

    public function testChaveDoContadorELegivelPeloLaravel(): void
    {
        app(LinkService::class)->generate('https://exemplo.pt');

        $this->assertSame(
            '238328',
            Redis::get('encurtador:contador'),
            'A função deve incrementar a chave que o phpredis lhe passou em keys[1].'
        );
    }

    public function testFilaPartilhaPrefixoDasChavesDoPhpredis(): void
    {
        app(LinkService::class)->generate('https://exemplo.pt');

        $prefix = config('database.redis.options.prefix');
        $this->assertIsString($prefix);

        $this->assertSame(
            [$prefix . 'encurtador:fila'],
            Redis::executeRaw(['KEYS', '*encurtador:fila'])
        );
    }

    public function testAplicacaoNaoCarregaABibliotecaFaltaEla(): void
    {
        Redis::executeRaw(['FUNCTION', 'FLUSH']);

        $this->assertSame(
            [],
            Redis::executeRaw(['FUNCTION', 'LIST']),
            'A biblioteca tem de estar descarregada antes de gerar.'
        );

        $contador = Redis::get('encurtador:contador');
        $fila = Redis::xlen('encurtador:fila');

        try {
            app(LinkService::class)->generate('https://exemplo.pt', 7);
            $this->fail('Sem a biblioteca registada, a reserva tem de falhar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Function not found', $e->getMessage());
        }

        $this->assertSame($contador, Redis::get('encurtador:contador'));
        $this->assertSame($fila, Redis::xlen('encurtador:fila'));
    }

    public function testRegistoVaiParaFilaComDestinoSlugEUtilizador(): void
    {
        $slug = app(LinkService::class)->generate('https://destino.pt/caminho', 42);

        $registo = $this->primeiroRegisto();

        $this->assertSame('https://destino.pt/caminho', $registo['url'] ?? null);
        $this->assertSame($slug, $registo['slug'] ?? null);
        $this->assertSame('42', $registo['usuario'] ?? null);
    }

    public function testLinkAnonimoRegistaUtilizadorVazio(): void
    {
        app(LinkService::class)->generate('https://anonimo.pt');

        $registo = $this->primeiroRegisto();

        $this->assertArrayHasKey('usuario', $registo);
        $this->assertSame('', $registo['usuario'] ?? null);
    }

    public function testDestinoVazioERecusadoSemGastarSlug(): void
    {
        $service = app(LinkService::class);
        $service->generate('https://exemplo.pt');

        $contador = Redis::get('encurtador:contador');
        $fila = Redis::xlen('encurtador:fila');

        try {
            $service->generate('');
            $this->fail('Um destino vazio tem de ser recusado.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('obrigatorio', $e->getMessage());
        }

        $this->assertSame($contador, Redis::get('encurtador:contador'));
        $this->assertSame($fila, Redis::xlen('encurtador:fila'));
    }

    public function testCriacaoEscreveACacheJaComOSlug(): void
    {
        $slug = app(LinkService::class)->generate('https://exemplo.pt');

        $bruto = Redis::get('link:' . $slug);

        $this->assertIsString($bruto, 'A criação escreve a cache antes de a linha existir.');

        $payload = json_decode($bruto, true);

        $this->assertIsArray($payload);
        $this->assertSame('https://exemplo.pt', $payload['url'] ?? null);

        // A chave existe com valor nulo, e não é omitida: é assim que o PHP escreve
        // o mesmo payload, e um formato só é mais fácil de manter do que dois. O
        // `cjson` não garante a ordem das chaves, por isso isto não se compara com
        // um literal por `assertSame`. E a presença prova-se com `array_key_exists`
        // e não com `??`, que tanto devolve um valor nulo como uma chave ausente.
        $this->assertTrue(
            array_key_exists('exp', $payload),
            'A chave `exp` existe mesmo sem prazo.',
        );
        $this->assertNull($payload['exp']);
    }

    public function testOCacheDaCriacaoNaoTemId(): void
    {
        $slug = app(LinkService::class)->generate('https://exemplo.pt');

        $bruto = Redis::get('link:' . $slug);

        $this->assertIsString($bruto);

        $payload = json_decode($bruto, true);

        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('id', $payload, 'A linha ainda não existe: não há id para guardar.');
    }

    public function testAChaveQueOLuaEscreveEAQueOPhpLeria(): void
    {
        $prefixo = config('database.redis.options.prefix');

        $this->assertIsString($prefixo);

        $slug = app(LinkService::class)->generate('https://exemplo.pt');

        $this->assertIsString(Redis::get('link:' . $slug));
        $this->assertSame(
            [],
            Redis::executeRaw(['KEYS', 'link:*']),
            'A função não pode escrever a chave sem o prefixo do phpredis: escrevia '
            . '`link:' . $slug . '` e o PHP leria `' . $prefixo . 'link:' . $slug . '`.',
        );
    }

    public function testLinkSemPrazoFicaCacheadoUmDia(): void
    {
        $slug = app(LinkService::class)->generate('https://exemplo.pt');

        $this->assertSame(86400, Redis::ttl('link:' . $slug));
    }

    public function testLinkComPrazoFicaCacheadoAteAoPrazo(): void
    {
        $slug = app(LinkService::class)->generate('https://exemplo.pt', null, now()->addHours(2));

        $ttl = Redis::ttl('link:' . $slug);

        $this->assertGreaterThan(7100, $ttl);
        $this->assertLessThanOrEqual(7200, $ttl);
    }

    public function testLinkComPrazoLongeFicaCacheadoNoMaximoDeUmDia(): void
    {
        $slug = app(LinkService::class)->generate('https://exemplo.pt', null, now()->addDays(30));

        $this->assertSame(86400, Redis::ttl('link:' . $slug));
    }

    public function testLinkJaExpiradoReservaOSlugMasNaoOcupaCache(): void
    {
        $slug = app(LinkService::class)->generate('https://exemplo.pt', null, now()->subHour());

        $this->assertSame(1, Redis::xlen('encurtador:fila'), 'O slug é reservado na mesma.');
        $this->assertNull(Redis::get('link:' . $slug), 'Mas o que já está morto não ocupa cache.');
    }

    public function testExpiracaoViajaNoRegistoDaFila(): void
    {
        $expira = now()->addHours(2);

        app(LinkService::class)->generate('https://exemplo.pt', null, $expira);

        $registo = $this->primeiroRegisto();

        $this->assertSame((string) $expira->getTimestamp(), $registo['exp'] ?? null);
    }

    public function testLinkSemPrazoOmiteAExpiracaoDoRegisto(): void
    {
        app(LinkService::class)->generate('https://exemplo.pt');

        $registo = $this->primeiroRegisto();

        $this->assertArrayNotHasKey('exp', $registo, 'Ausente é como se diz que não expira.');
    }

    public function testResolucaoLogoAposACriacaoDevolveLinkDTO(): void
    {
        $slug = app(LinkService::class)->generate('https://antes-de-existir.pt');

        // Não há linha no MySQL: a criação é assíncrona e o consumidor da fila não
        // existe. Se a cache não respondesse, o MySQL seria consultado, não
        // encontraria nada, e a resolução lançaria LinkNotFound. O facto de
        // devolver um DTO em vez de uma entidade é, sozinho, a prova de que a cache
        // respondeu — e de que o `id` ainda não existe.
        $resolved = app(LinkService::class)->findBySlug($slug);

        $this->assertInstanceOf(LinkDTO::class, $resolved);
        $this->assertSame('https://antes-de-existir.pt', $resolved->url);
    }

    public function testFuncaoNaoEscreveAlgumaRelacaoForaDaCache(): void
    {
        $slug = app(LinkService::class)->generate('https://exemplo.pt');

        $prefixo = config('database.redis.options.prefix');

        $this->assertIsString($prefixo);

        // A função passou a escrever a relação slug -> destino, dentro da cache, e
        // só dentro dela. Estas são as chaves que não pode tocar.
        $this->assertSame([], Redis::executeRaw(['KEYS', 'url:*']));
        $this->assertSame([], Redis::executeRaw(['KEYS', 'slug:*']));

        $escritas = Redis::executeRaw(['KEYS', $prefixo . 'link:*']);

        $this->assertIsArray($escritas);
        $this->assertCount(1, $escritas, $slug);
    }

    public function testContadorRepostoNaoReemiteMesmoSlugSemAvisar(): void
    {
        $first = app(LinkService::class)->generate('https://exemplo.pt');

        Redis::del('encurtador:contador');

        $this->assertSame(
            $first,
            app(LinkService::class)->generate('https://exemplo.pt'),
            'Com o contador reposto, o slug colide. É o índice único de links.slug '
            . 'que resolve, e o chamador tem de repetir a operação.'
        );
    }

    /**
     * @return array<string, string>
     */
    private function primeiroRegisto(): array
    {
        $entradas = Redis::xrange('encurtador:fila', '-', '+');

        $this->assertIsArray($entradas);
        $this->assertCount(1, $entradas);

        $registo = reset($entradas);

        $this->assertIsArray($registo);

        /** @var array<string, string> $registo */
        return $registo;
    }

    private function redisFunctionsAvailable(): bool
    {
        try {
            if (Redis::ping() !== true) {
                return false;
            }

            return Redis::command('function', ['LIST']) !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
