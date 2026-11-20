<?php

declare(strict_types=1);

namespace Tests\Feature\Links;

use App\DTOs\LinkDTO;
use App\Exceptions\LinkExpired;
use App\Exceptions\LinkNotFound;
use App\Models\Link;
use App\Models\User;
use App\Services\LinkService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class LinkServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::flushdb();
    }

    public function testListaSoOsLinksDoUtilizadorDosMaisRecentesParaOsMaisAntigos(): void
    {
        $outro = 99;
        $meu = 7;

        $antigo = $this->link('aaa1', 'https://antigo.pt', $meu);
        $novo = $this->link('aaa2', 'https://novo.pt', $meu);
        $this->link('aaa3', 'https://alheio.pt', $outro);

        $links = app(LinkService::class)->listForUser($meu);

        $this->assertSame(2, $links->total());
        $this->assertSame(
            [$novo->slug, $antigo->slug],
            $this->slugs($links),
        );
    }

    public function testListaNaoIncluiLinksAnonimos(): void
    {
        $this->link('anon1', 'https://anonimo.pt', null);
        $meu = $this->link('meu01', 'https://meu.pt', 7);

        $slugs = $this->slugs(app(LinkService::class)->listForUser(7));

        $this->assertSame([$meu->slug], $slugs);
        $this->assertNotContains('anon1', $slugs);
    }

    public function testListaFiltraPorSlug(): void
    {
        $this->link('promo1', 'https://a.pt', 7);
        $this->link('promo2', 'https://b.pt', 7);
        $this->link('outro1', 'https://c.pt', 7);

        $this->assertSame(
            ['promo2', 'promo1'],
            $this->slugs(app(LinkService::class)->listForUser(7, 'promo')),
        );
    }

    public function testBuscaComEspacoEnvolventeIgnoraAsEspacos(): void
    {
        $this->link('promo1', 'https://a.pt', 7);
        $this->link('outro1', 'https://b.pt', 7);

        $this->assertSame(
            ['promo1'],
            $this->slugs(app(LinkService::class)->listForUser(7, '  promo  ')),
        );
    }

    public function testBuscaComEspacoVazioNaoFiltra(): void
    {
        $this->link('promo1', 'https://a.pt', 7);
        $this->link('outro1', 'https://b.pt', 7);

        $this->assertSame(2, app(LinkService::class)->listForUser(7, '   ')->total());
    }

    public function testBuscaTrataPercentENoUnderlineComoLiterais(): void
    {
        $this->link('abc1', 'https://a.pt', 7);
        $this->link('xyz9', 'https://b.pt', 7);

        $service = app(LinkService::class);

        $this->assertSame([], $this->slugs($service->listForUser(7, '%')));
        $this->assertSame([], $this->slugs($service->listForUser(7, '_')));
    }

    public function testListaPagina(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->link('pag' . $i, 'https://exemplo.pt', 7);
        }

        $service = app(LinkService::class);

        $this->assertSame(5, $service->listForUser(7, null, 2)->total());
        $this->assertCount(2, $service->listForUser(7, null, 2)->items());
    }

    public function testApagarRemoveOLinhaEOCache(): void
    {
        $link = $this->link('sai11', 'https://sai.pt', 7);

        app(LinkService::class)->findBySlug('sai11');

        $this->assertNotNull(Redis::get('link:sai11'), 'O cache tem de estar povoado antes de apagar.');

        app(LinkService::class)->delete($link->id);

        $this->assertNull(Link::query()->find($link->id));
        $this->assertNull(Redis::get('link:sai11'));
    }

    public function testApagarDesconhecidoLancaLinkNotFound(): void
    {
        $this->expectException(LinkNotFound::class);

        app(LinkService::class)->delete(4321);
    }

    public function testApagarNaoMexeEmOutrosLinks(): void
    {
        $alvo = $this->link('borr1', 'https://a.pt', 7);
        $outro = $this->link('mant1', 'https://b.pt', 7);

        app(LinkService::class)->findBySlug('mant1');
        app(LinkService::class)->delete($alvo->id);

        $this->assertNull(Link::query()->find($alvo->id));
        $this->assertNotNull(Link::query()->find($outro->id));
        $this->assertNotNull(Redis::get('link:mant1'));
    }

    public function testCacheMissConsultaOMySQL(): void
    {
        $link = $this->link('vind1', 'https://do-banco.pt', 7);

        $resolved = app(LinkService::class)->findBySlug('vind1');

        $this->assertInstanceOf(Link::class, $resolved);
        $this->assertSame('https://do-banco.pt', $resolved->original_url);
        $this->assertSame($link->id, $resolved->id);
    }

    public function testCacheHitNaoVoltaAoMySQL(): void
    {
        $link = $this->link('cache', 'https://do-cache.pt', 7);

        $service = app(LinkService::class);
        $service->findBySlug('cache');

        DB::table('links')->where('id', $link->id)->delete();

        $resolved = $service->findBySlug('cache');

        $this->assertInstanceOf(Link::class, $resolved);
        $this->assertSame('https://do-cache.pt', $resolved->original_url);
    }

    public function testCacheHitDevolveAEntidadeComId(): void
    {
        $link = $this->link('comid', 'https://com-id.pt', 7);

        $service = app(LinkService::class);
        $service->findBySlug('comid');

        $resolved = $service->findBySlug('comid');

        $this->assertInstanceOf(Link::class, $resolved);
        $this->assertSame($link->id, $resolved->id);
        $this->assertTrue($resolved->exists(), 'Um id guardado implica linha criada.');
    }

    public function testCacheHitSemIdDevolveLinkDTO(): void
    {
        Redis::setex('link:voa1', 3600, (string) json_encode([
            'url' => 'https://antes-de-existir.pt',
            'exp' => null,
        ]));

        $resolved = app(LinkService::class)->findBySlug('voa1');

        $this->assertInstanceOf(LinkDTO::class, $resolved);
        $this->assertSame('https://antes-de-existir.pt', $resolved->url);
    }

    public function testCacheHitSemIdNaoVoltaAoMySQL(): void
    {
        Redis::setex('link:voa2', 3600, (string) json_encode([
            'url' => 'https://antes-de-existir.pt',
            'exp' => null,
        ]));

        app(LinkService::class)->findBySlug('voa2');

        $bruto = Redis::get('link:voa2');

        $this->assertIsString($bruto);
        $this->assertSame(
            ['url' => 'https://antes-de-existir.pt', 'exp' => null],
            json_decode($bruto, true),
            'O DTO não repovoa a cache: sem id, a linha pode ainda não existir.',
        );
    }

    public function testCacheHitComIdInvalidoCaiNoMySQL(): void
    {
        $this->link('idruim', 'https://sana.pt', 7);

        Redis::setex('link:idruim', 60, (string) json_encode([
            'url' => 'https://errado.pt',
            'exp' => null,
            'id' => 0,
        ]));

        $resolved = app(LinkService::class)->findBySlug('idruim');

        $this->assertInstanceOf(Link::class, $resolved);
        $this->assertSame('https://sana.pt', $resolved->original_url);
    }

    public function testCacheHitDevolveOMesmoDestinoQueOMySQL(): void
    {
        $this->link('igual', 'https://igual.pt', 7);

        $service = app(LinkService::class);

        $doBanco = $service->findBySlug('igual');
        $doCache = $service->findBySlug('igual');

        $this->assertInstanceOf(Link::class, $doBanco);
        $this->assertInstanceOf(Link::class, $doCache);
        $this->assertSame($doBanco->original_url, $doCache->original_url);
        $this->assertSame(
            $doBanco->expires_at?->getTimestamp(),
            $doCache->expires_at?->getTimestamp(),
        );
    }

    public function testSlugDesconhecidoLancaLinkNotFound(): void
    {
        $this->expectException(LinkNotFound::class);

        app(LinkService::class)->findBySlug('nada1');
    }

    public function testLookupDistingueMaiusculasDeMinusculas(): void
    {
        $this->link('Ab3x', 'https://maiuscula.pt', 7);
        $this->link('ab3x', 'https://minuscula.pt', 7);

        $service = app(LinkService::class);

        $maiuscula = $service->findBySlug('Ab3x');
        $minuscula = $service->findBySlug('ab3x');

        $this->assertInstanceOf(Link::class, $maiuscula);
        $this->assertInstanceOf(Link::class, $minuscula);
        $this->assertSame('https://maiuscula.pt', $maiuscula->original_url);
        $this->assertSame('https://minuscula.pt', $minuscula->original_url);
    }

    public function testLinkExpiradoLancaLinkExpired(): void
    {
        $this->link('cadu1', 'https://expirado.pt', 7, now()->subHour());

        $this->expectException(LinkExpired::class);

        app(LinkService::class)->findBySlug('cadu1');
    }

    public function testLinkExpiradoNoCacheTambemLancaLinkExpired(): void
    {
        $this->link('cadu2', 'https://expirado.pt', 7);

        Redis::setex('link:cadu2', 3600, (string) json_encode([
            'url' => 'https://expirado.pt',
            'exp' => now()->subHour()->getTimestamp(),
        ]));

        $this->expectException(LinkExpired::class);

        app(LinkService::class)->findBySlug('cadu2');
    }

    public function testLinkExpiradoNoCacheComIdTambemLancaLinkExpired(): void
    {
        $link = $this->link('cadu4', 'https://expirado.pt', 7);

        Redis::setex('link:cadu4', 3600, (string) json_encode([
            'url' => 'https://expirado.pt',
            'exp' => now()->subHour()->getTimestamp(),
            'id' => $link->id,
        ]));

        $this->expectException(LinkExpired::class);

        app(LinkService::class)->findBySlug('cadu4');
    }

    public function testAlterarALinhaAtrasDoCacheSoSeVedeDepoisDeInvalidar(): void
    {
        $link = $this->link('edit1', 'https://antes.pt', 7);

        $service = app(LinkService::class);

        $antes = $service->findBySlug('edit1');
        $this->assertInstanceOf(Link::class, $antes);
        $this->assertSame('https://antes.pt', $antes->original_url);

        DB::table('links')->where('id', $link->id)->update(['original_url' => 'https://depois.pt']);

        $cacheado = $service->findBySlug('edit1');
        $this->assertInstanceOf(Link::class, $cacheado);
        $this->assertSame(
            'https://antes.pt',
            $cacheado->original_url,
            'O cache é autoritário: sem o forget, a edição não aparece.'
        );

        $service->forget('edit1');

        $depois = $service->findBySlug('edit1');
        $this->assertInstanceOf(Link::class, $depois);
        $this->assertSame('https://depois.pt', $depois->original_url);
    }

    public function testLinkExpiradoNaoFicaCacheado(): void
    {
        $this->link('cadu3', 'https://expirado.pt', 7, now()->subHour());

        try {
            app(LinkService::class)->findBySlug('cadu3');
        } catch (LinkExpired) {
        }

        $this->assertNull(Redis::get('link:cadu3'));
    }

    public function testLinkSemExpiracaoFicaCacheadoUmDia(): void
    {
        $this->link('etern', 'https://eterno.pt', 7);

        app(LinkService::class)->findBySlug('etern');

        $this->assertSame(86400, Redis::ttl('link:etern'));
    }

    public function testTtlEncolheComAExpiracao(): void
    {
        $this->link('breve', 'https://breve.pt', 7, now()->addMinutes(5));

        app(LinkService::class)->findBySlug('breve');

        $ttl = Redis::ttl('link:breve');

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(300, $ttl);
    }

    public function testValorCacheadoCorrompidoCaiNoMySQL(): void
    {
        $this->link('lixo1', 'https://sana.pt', 7);

        Redis::setex('link:lixo1', 60, 'isto não é json');

        $sana = app(LinkService::class)->findBySlug('lixo1');

        $this->assertInstanceOf(Link::class, $sana);
        $this->assertSame('https://sana.pt', $sana->original_url);
        $this->assertNotNull(Redis::get('link:lixo1'), 'A leitura tem de repovoar o cache.');
    }

    public function testValorCacheadoComFormatoInvalidoCaiNoMySQL(): void
    {
        $this->link('lixo2', 'https://sana.pt', 7);

        Redis::setex('link:lixo2', 60, json_encode(['destino' => 'https://errado.pt']));

        $sana = app(LinkService::class)->findBySlug('lixo2');

        $this->assertInstanceOf(Link::class, $sana);
        $this->assertSame('https://sana.pt', $sana->original_url);
    }

    /**
     * @param  LengthAwarePaginator<int, Link>  $links
     * @return array<int, string>
     */
    private function slugs(LengthAwarePaginator $links): array
    {
        return $links->getCollection()
            ->map(fn (Link $link): string => $link->slug)
            ->values()
            ->all();
    }

    private function link(string $slug, string $url, ?int $userId, mixed $expiresAt = null): Link
    {
        if ($userId !== null && ! User::query()->whereKey($userId)->exists()) {
            User::factory()->create(['id' => $userId]);
        }

        return Link::query()->create([
            'slug' => $slug,
            'original_url' => $url,
            'user_id' => $userId,
            'expires_at' => $expiresAt,
        ]);
    }
}
