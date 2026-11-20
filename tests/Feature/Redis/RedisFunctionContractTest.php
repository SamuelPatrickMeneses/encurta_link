<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Services\LinkService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class RedisFunctionContractTest extends TestCase
{
    public function testReservaSlugAtravesDeFcall(): void
    {
        Redis::shouldReceive('fcall')
            ->once()
            ->with(
                'gerar_slug',
                ['encurtador:contador', 'encurtador:fila'],
                ['https://exemplo.pt', '7', '', '86400', 'encurta-link-testing-link:']
            )
            ->andReturn('Ab3x');

        $this->assertSame('Ab3x', app(LinkService::class)->generate('https://exemplo.pt', 7));
    }

    public function testLinkAnonimoTravelaComUtilizadorVazio(): void
    {
        Redis::shouldReceive('fcall')
            ->once()
            ->with(
                'gerar_slug',
                ['encurtador:contador', 'encurtador:fila'],
                ['https://anonimo.pt', '', '', '86400', 'encurta-link-testing-link:']
            )
            ->andReturn('a1b2');

        $this->assertSame('a1b2', app(LinkService::class)->generate('https://anonimo.pt'));
    }

    public function testExpiracaoViajaComOTtlQueElaImplica(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00'));

        try {
            Redis::shouldReceive('fcall')
                ->once()
                ->with(
                    'gerar_slug',
                    ['encurtador:contador', 'encurtador:fila'],
                    [
                        'https://exemplo.pt',
                        '7',
                        (string) Carbon::parse('2026-09-27 14:00:00')->getTimestamp(),
                        '7200',
                        'encurta-link-testing-link:',
                    ]
                )
                ->andReturn('Ab3x');

            $this->assertSame('Ab3x', app(LinkService::class)->generate(
                'https://exemplo.pt',
                7,
                Carbon::parse('2026-09-27 14:00:00'),
            ));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testLinkJaExpiradoReservaComTtlZeroParaNaoSerCacheado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00'));

        try {
            Redis::shouldReceive('fcall')
                ->once()
                ->with(
                    'gerar_slug',
                    ['encurtador:contador', 'encurtador:fila'],
                    [
                        'https://exemplo.pt',
                        '7',
                        (string) Carbon::parse('2026-09-27 11:00:00')->getTimestamp(),
                        '0',
                        'encurta-link-testing-link:',
                    ]
                )
                ->andReturn('Ab3x');

            $this->assertSame('Ab3x', app(LinkService::class)->generate(
                'https://exemplo.pt',
                7,
                Carbon::parse('2026-09-27 11:00:00'),
            ));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testReservaGastaUmaUnicaChamadaAoRedis(): void
    {
        Redis::shouldReceive('fcall')->once()->andReturn('c3d4');
        Redis::shouldNotReceive('executeRaw');

        $this->assertSame('c3d4', app(LinkService::class)->generate('https://exemplo.pt'));
    }

    public function testFuncaoAusenteNaoERecarregadaPelaAplicacao(): void
    {
        Redis::shouldReceive('fcall')->once()->andReturn(false);
        Redis::shouldReceive('getLastError')->once()->andReturn('ERR Function not found');
        Redis::shouldNotReceive('executeRaw');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ERR Function not found');

        app(LinkService::class)->generate('https://exemplo.pt');
    }

    public function testErroDoServidorPropaQuandoPhpredisDevolveFalse(): void
    {
        Redis::shouldReceive('fcall')->once()->andReturn(false);
        Redis::shouldReceive('getLastError')->once()->andReturn('ERR Connection refused');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ERR Connection refused');

        app(LinkService::class)->generate('https://exemplo.pt');
    }

    public function testErroDoServidorPropaQuandoPhpredisLancaExcepcao(): void
    {
        Redis::shouldReceive('fcall')
            ->once()
            ->andThrow(new RuntimeException('ERR Function not found'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ERR Function not found');

        app(LinkService::class)->generate('https://exemplo.pt');
    }

    public function testRejeitaSlugVazio(): void
    {
        Redis::shouldReceive('fcall')->once()->andReturn('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('devolveu um valor inesperado');

        app(LinkService::class)->generate('https://exemplo.pt');
    }
}
