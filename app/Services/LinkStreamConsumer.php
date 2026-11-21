<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Projeta o stream `encurtador:fila` na tabela `links`.
 *
 * @phpstan-type LinkRow array{
 *     slug: string,
 *     original_url: string,
 *     user_id: int|null,
 *     expires_at: CarbonImmutable|null,
 *     created_at: CarbonImmutable,
 *     updated_at: CarbonImmutable
 * }
 */
final class LinkStreamConsumer
{
    private const STREAM = 'encurtador:fila';

    private const GROUP = 'links';

    private const BLOCK_MS = 2000;

    /**
     * Cria o consumer group, sem falhar quando ele já existe.
     *
     * O grupo começa em `0` para que as entradas que já estavam no stream
     * quando o consumidor arrancou não sejam ignoradas. `MKSTREAM` permite
     * criar o grupo mesmo antes de existir a primeira entrada.
     */
    public function ensureGroup(): void
    {
        try {
            $this->run(fn () => Redis::xgroup('CREATE', self::STREAM, self::GROUP, '0', true));
        } catch (RuntimeException $e) {
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    /**
     * Lê no máximo `$maxRecords` entradas e projeta-as numa única instrução.
     *
     * @return int O número de entradas confirmadas com `XACK`.
     *
     * @throws InvalidArgumentException
     */
    public function consumeBatch(int $maxRecords, int $blockMs = self::BLOCK_MS): int
    {
        if ($maxRecords < 1) {
            throw new InvalidArgumentException('O número máximo de registos tem de ser positivo.');
        }

        $entries = $this->read($maxRecords, $blockMs);

        if ($entries === []) {
            return 0;
        }

        $acknowledged = $this->project($entries);

        if ($acknowledged !== []) {
            $this->run(fn () => Redis::xack(self::STREAM, self::GROUP, $acknowledged));
        }

        return count($acknowledged);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function read(int $maxRecords, int $blockMs): array
    {
        $response = Redis::xreadgroup(
            self::GROUP,
            $this->consumerName(),
            [self::STREAM => '>'],
            $maxRecords,
            $blockMs,
        );

        if ($response === false) {
            $message = Redis::getLastError();

            // Um `BLOCK` sem mensagens devolve `false` sem erro de servidor;
            // uma falha real traz sempre a mensagem do Redis.
            if (! is_string($message) || $message === '') {
                return [];
            }

            throw new RuntimeException($message);
        }

        if (! is_array($response)) {
            return [];
        }

        // O phpredis devolve a chave como ela viajou no protocolo, já com o
        // prefixo da conexão. Só há um stream no pedido, por isso o valor
        // interessa mais do que o nome que o servidor devolveu.
        $stream = reset($response);

        if (! is_array($stream)) {
            return [];
        }

        $entries = [];

        foreach ($stream as $id => $fields) {
            if (! is_string($id) || ! is_array($fields)) {
                continue;
            }

            $entry = [];

            foreach ($fields as $field => $value) {
                if (is_string($field) && is_string($value)) {
                    $entry[$field] = $value;
                }
            }

            $entries[$id] = $entry;
        }

        return $entries;
    }

    /**
     * @param  array<string, array<string, string>>  $entries
     * @return list<string>
     */
    private function project(array $entries): array
    {
        /** @var array<string, LinkRow> $rows */
        $rows = [];

        /** @var array<string, list<string>> $idsBySlug */
        $idsBySlug = [];

        foreach ($entries as $id => $fields) {
            $row = $this->rowFrom($fields);

            if ($row === null) {
                Log::warning('Entrada inválida no stream encurtador:fila.', ['id' => $id]);

                continue;
            }

            $slug = $row['slug'];

            $rows[$slug] ??= $row;
            $idsBySlug[$slug][] = $id;
        }

        if ($rows === []) {
            return [];
        }

        $users = $this->existingUserIds($rows);

        foreach ($rows as $slug => $row) {
            if ($row['user_id'] !== null && ! isset($users[$row['user_id']])) {
                $rows[$slug]['user_id'] = null;
            }
        }

        try {
            DB::table('links')->insertOrIgnore(array_values($rows));
        } catch (Throwable $e) {
            Log::error('Falha ao projetar o lote; a repetir entrada a entrada.', [
                'exception' => $e,
            ]);

            return $this->insertOneByOne($rows, $idsBySlug);
        }

        return $this->flattenIds($idsBySlug);
    }

    /**
     * @param  array<string, string>  $fields
     * @return LinkRow|null
     */
    private function rowFrom(array $fields): ?array
    {
        $slug = $fields['slug'] ?? null;
        $url = $fields['url'] ?? null;

        if (! is_string($slug) || preg_match('/^[0-9A-Za-z]{4,10}$/', $slug) !== 1) {
            return null;
        }

        if (! is_string($url) || $url === '' || strlen($url) > 65535) {
            return null;
        }

        $usuario = $fields['usuario'] ?? '';

        if ($usuario !== '' && (! ctype_digit($usuario) || (int) $usuario < 1)) {
            return null;
        }

        $exp = $fields['exp'] ?? null;

        if ($exp !== null && (! ctype_digit($exp) || (int) $exp < 1)) {
            return null;
        }

        $now = CarbonImmutable::now();

        return [
            'slug' => $slug,
            'original_url' => $url,
            'user_id' => $usuario === '' ? null : (int) $usuario,
            'expires_at' => $exp === null ? null : CarbonImmutable::createFromTimestamp((int) $exp),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string, LinkRow>  $rows
     * @return array<int, true>
     */
    private function existingUserIds(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if ($row['user_id'] !== null) {
                $ids[$row['user_id']] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $found = DB::table('users')->whereIn('id', array_keys($ids))->pluck('id')->all();

        $existing = [];

        foreach ($found as $id) {
            if (is_int($id)) {
                $existing[$id] = true;
            } elseif (is_string($id) && ctype_digit($id)) {
                $existing[(int) $id] = true;
            }
        }

        return $existing;
    }

    /**
     * @param  array<string, LinkRow>  $rows
     * @param  array<string, list<string>>  $idsBySlug
     * @return list<string>
     */
    private function insertOneByOne(array $rows, array $idsBySlug): array
    {
        $acknowledged = [];

        foreach ($rows as $slug => $row) {
            try {
                DB::table('links')->insertOrIgnore([$row]);

                foreach ($idsBySlug[$slug] as $id) {
                    $acknowledged[] = $id;
                }
            } catch (Throwable $e) {
                Log::error('Entrada do stream não foi projetada.', [
                    'slug' => $slug,
                    'exception' => $e,
                ]);
            }
        }

        return $acknowledged;
    }

    /**
     * @param  array<string, list<string>>  $idsBySlug
     * @return list<string>
     */
    private function flattenIds(array $idsBySlug): array
    {
        $ids = [];

        foreach ($idsBySlug as $slugIds) {
            foreach ($slugIds as $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function consumerName(): string
    {
        $host = gethostname();

        return sprintf('%s-%d', is_string($host) ? $host : 'php', (int) getmypid());
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
