<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\LinkDTO;
use App\DTOs\LinkStreamEntry;
use Closure;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Resolve um slug no stream de links ainda não confirmados.
 *
 * O consumidor remove do stream cada entrada que confirma no MySQL, por isso o
 * que aqui resta é precisamente o que ainda não pode ser encontrado na base de
 * dados. A varredura existe para o intervalo entre a criação no Redis e a
 * projeção no MySQL, não para substituir a cache nem a tabela.
 */
final class LinkStreamReader
{
    private const STREAM = 'encurtador:fila';

    public function findBySlug(string $slug): ?LinkDTO
    {
        $entries = $this->run(fn () => Redis::xrange(self::STREAM, '-', '+'));

        if (! is_array($entries)) {
            return null;
        }

        foreach ($entries as $fields) {
            if (! is_array($fields)) {
                continue;
            }

            $entry = LinkStreamEntry::fromFields($fields);

            if ($entry === null || $entry->slug !== $slug) {
                continue;
            }

            return new LinkDTO($entry->slug, $entry->url, $entry->expiresAt);
        }

        return null;
    }

    /**
     * @param  Closure(): mixed  $command
     */
    private function run(Closure $command): mixed
    {
        $result = $command();

        if ($result !== false) {
            return $result;
        }

        $message = Redis::getLastError();

        throw new RuntimeException(
            is_string($message) && $message !== ''
                ? $message
                : 'o comando Redis devolveu false sem mensagem de erro'
        );
    }
}
