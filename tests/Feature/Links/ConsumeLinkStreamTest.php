<?php

declare(strict_types=1);

namespace Tests\Feature\Links;

use App\Models\Link;
use App\Models\User;
use App\Services\LinkStreamConsumer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class ConsumeLinkStreamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::flushdb();
    }

    public function testProjetaNoMaximoOsRegistosConfigurados(): void
    {
        config()->set('links.stream.max_records', 2);

        $this->addStreamEntry('aaa1', 'https://a.pt');
        $this->addStreamEntry('aaa2', 'https://b.pt');
        $this->addStreamEntry('aaa3', 'https://c.pt');

        $this->consumeOnce();

        $this->assertSame(2, Link::query()->count());

        $this->consumeOnce();

        $this->assertSame(3, Link::query()->count());
    }

    public function testProjetaOLoteNumUnicoInsert(): void
    {
        $inserts = [];

        DB::listen(function (QueryExecuted $query) use (&$inserts): void {
            $sql = (string) $query->sql;

            if (str_contains(strtolower($sql), 'insert') && str_contains($sql, 'links')) {
                $inserts[] = $sql;
            }
        });

        $this->addStreamEntry('lote1', 'https://a.pt');
        $this->addStreamEntry('lote2', 'https://b.pt');
        $this->addStreamEntry('lote3', 'https://c.pt');

        $this->consumeOnce();

        $this->assertCount(1, $inserts, 'O lote tem de chegar ao MySQL numa única instrução.');
        $this->assertSame(3, Link::query()->count());
    }

    public function testMapeiaDestinoUtilizadorEExpiracao(): void
    {
        User::factory()->create(['id' => 7]);

        $expira = now()->addHour()->startOfSecond();

        $this->addStreamEntry('comus', 'https://com-user.pt', '7', $expira->getTimestamp());
        $this->addStreamEntry('anon1', 'https://anonimo.pt');
        $this->addStreamEntry('semex', 'https://sem-exp.pt', '7');

        $this->consumeOnce();

        $comUsuario = Link::query()->where('slug', 'comus')->firstOrFail();
        $anonimo = Link::query()->where('slug', 'anon1')->firstOrFail();
        $semExpiracao = Link::query()->where('slug', 'semex')->firstOrFail();

        $this->assertSame('https://com-user.pt', $comUsuario->original_url);
        $this->assertSame(7, $comUsuario->user_id);
        $this->assertSame($expira->getTimestamp(), $comUsuario->expires_at?->getTimestamp());

        $this->assertNull($anonimo->user_id);
        $this->assertNull($anonimo->expires_at);

        $this->assertSame(7, $semExpiracao->user_id);
        $this->assertNull($semExpiracao->expires_at);
    }

    public function testUtilizadorInexistenteFicaNuloEmVezDeBloquearOLote(): void
    {
        $this->addStreamEntry('orfao', 'https://orfao.pt', '999999');

        $this->consumeOnce();

        $link = Link::query()->where('slug', 'orfao')->firstOrFail();

        $this->assertNull($link->user_id);
        $this->assertSame('https://orfao.pt', $link->original_url);
    }

    public function testSlugRepetidoProjetaUmaLinhaEConfirmaAsDuasEntradas(): void
    {
        $this->addStreamEntry('dup1', 'https://a.pt');
        $this->addStreamEntry('dup1', 'https://b.pt');

        $this->consumeOnce();

        $this->assertSame(1, Link::query()->count());
        $this->assertSame('https://a.pt', Link::query()->firstOrFail()->original_url);

        $pending = Redis::xpending('encurtador:fila', 'links');

        $this->assertIsArray($pending);
        $this->assertSame(0, $pending[0] ?? null);
    }

    public function testStreamVazioNaoLancaErro(): void
    {
        $consumer = app(LinkStreamConsumer::class);
        $consumer->ensureGroup();

        $this->assertSame(0, $consumer->consumeBatch(10, 1));
    }

    public function testMaximoInvalidoFalhaSemConsumir(): void
    {
        config()->set('links.stream.max_records', 0);

        $command = $this->artisan('links:consume', ['--once' => true]);

        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertFailed();
    }

    private function consumeOnce(): void
    {
        $command = $this->artisan('links:consume', ['--once' => true]);

        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful();
    }

    private function addStreamEntry(
        string $slug,
        string $url,
        string $usuario = '',
        ?int $exp = null,
    ): string {
        $fields = [
            'url' => $url,
            'slug' => $slug,
            'usuario' => $usuario,
        ];

        if ($exp !== null) {
            $fields['exp'] = (string) $exp;
        }

        $id = Redis::xadd('encurtador:fila', '*', $fields);

        if (! is_string($id)) {
            $this->fail('O Redis não devolveu o id da entrada.');
        }

        return $id;
    }
}
