<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LinkStreamConsumer;
use Illuminate\Console\Command;

final class ConsumeLinkStream extends Command
{
    protected $signature = 'links:consume
                            {--once : Processa um único lote e termina}';

    protected $description = 'Projeta o stream encurtador:fila na tabela links';

    public function handle(LinkStreamConsumer $consumer): int
    {
        $maxRecords = config('links.stream.max_records');

        if (! is_int($maxRecords) || $maxRecords < 1) {
            $this->components->error(
                'LINK_STREAM_MAX_RECORDS tem de ser um número inteiro positivo.'
            );

            return self::FAILURE;
        }

        $consumer->ensureGroup();

        $once = (bool) $this->option('once');

        do {
            $processed = $consumer->consumeBatch($maxRecords);

            if ($processed > 0) {
                $this->components->info(sprintf('%d registo(s) projetado(s).', $processed));
            }
        } while (! $once);

        return self::SUCCESS;
    }
}
