<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LinksSlugCollationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('A collation só existe no MySQL; requer MySQL 8+.');
        }
    }

    public function testColunaSlugTemCollationSensivelACaixa(): void
    {
        $collation = DB::scalar(
            "SELECT COLLATION_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'links' AND COLUMN_NAME = 'slug'",
            [DB::getDatabaseName()]
        );

        $this->assertSame('utf8mb4_0900_as_cs', $collation);
    }

    public function testDoisSlugsQueSoMudemACaixaCoexistem(): void
    {
        DB::table('links')->insert(['slug' => 'Ab3x', 'original_url' => 'https://a.pt']);
        DB::table('links')->insert(['slug' => 'ab3x', 'original_url' => 'https://b.pt']);

        $this->assertSame(2, DB::table('links')->count());
    }

    public function testIndiceUnicoRejeitaSlugIdentico(): void
    {
        DB::table('links')->insert(['slug' => 'Ab3x', 'original_url' => 'https://a.pt']);

        $this->expectException(QueryException::class);

        DB::table('links')->insert(['slug' => 'Ab3x', 'original_url' => 'https://c.pt']);
    }
}
