<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\LinkDTO;
use App\Exceptions\LinkExpired;
use App\Exceptions\LinkNotFound;
use App\Models\Link;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

final class LinkService
{
    private const FUNCTION = 'gerar_slug';

    private const COUNTER = 'encurtador:contador';

    private const STREAM = 'encurtador:fila';

    private const CACHE_PREFIX = 'link:';

    private const CACHE_TTL = 86400;

    /**
     * Reserva um slug e põe a link na fila, numa única chamada ao Redis.
     *
     * A expiração viaja em `<exp>` e o TTL correspondente em `<ttl>`, e a função
     * Redis escreve a cache com esse TTL. A regra do TTL fica aqui, num sítio só:
     * a função aplicá-la, sem a refazer. Por isso também não decide o `id` — a
     * linha ainda não existe, e é a leitura ao MySQL que o acrescenta depois.
     *
     * @param  string  $originalUrl
     * @param  int|null  $userId
     * @param  CarbonInterface|null  $expiresAt  Nulo para um link sem prazo.
     *
     * @throws RuntimeException
     */
    public function generate(
        string $originalUrl,
        ?int $userId = null,
        ?CarbonInterface $expiresAt = null,
    ): string {
        $ttl = $this->ttlFor($expiresAt);

        $slug = $this->run(fn () => Redis::fcall(
            self::FUNCTION,
            [self::COUNTER, self::STREAM],
            [
                $originalUrl,
                $userId === null ? '' : (string) $userId,
                $expiresAt === null ? '' : (string) $expiresAt->getTimestamp(),
                (string) max($ttl, 0),
                $this->cachePrefixCompleto(),
            ],
        ));

        if (! is_string($slug) || $slug === '') {
            throw new RuntimeException(
                sprintf('A função Redis %s devolveu um valor inesperado.', self::FUNCTION)
            );
        }

        return $slug;
    }

    /**
     * Os links de um utilizador, dos mais recentes para os mais antigos.
     *
     * @param  string|null  $search  Filtra por `slug LIKE %termo%`. A collation
     *                               da coluna é sensível à caixa, portanto a
     *                               busca também é.
     * @return LengthAwarePaginator<int, Link>
     */
    public function listForUser(int $userId, ?string $search = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = Link::query()
            ->where('user_id', $userId)
            ->orderByDesc('id');

        $termo = $search === null ? '' : trim($search);

        if ($termo !== '') {
            $query->where('slug', 'like', '%' . self::escaparLike($termo) . '%');
        }

        return $query->paginate($perPage);
    }

    /**
     * Apaga um link e a sua entrada de cache.
     *
     * Recebe o id, não o slug, e não verifica a posse: isso é do `LinkPolicy`, no
     * exterior. O id é o que torna a autorização possível à partida, porque o
     * slug é enumerável a partir de quatro caracteres.
     *
     * @throws LinkNotFound
     */
    public function delete(int $linkId): void
    {
        $link = Link::query()->find($linkId);

        if (! $link instanceof Link) {
            throw LinkNotFound::forId($linkId);
        }

        $this->forget($link->slug);

        Link::query()->whereKey($linkId)->delete();
    }

    /**
     * Invalida a cache de um slug sem tocar na linha.
     *
     * É o que a edição precisa: o fluxo altera a linha e a chave tem de cair
     * antes ou a alteração só aparece depois do TTL. Existe como método para o
     * formato da chave não deixar de viver aqui e passar a estar escrito num
     * controller.
     */
    public function forget(string $slug): void
    {
        $this->run(fn () => Redis::del($this->cacheKey($slug)));
    }

    /**
     * Resolve um slug, pelo cache primeiro e pelo MySQL em caso de falha.
     *
     * Devolve a entidade quando o `id` é conhecido, e um DTO enquanto a criação
     * ainda não estiver confirmada.
     *
     * @throws LinkNotFound
     * @throws LinkExpired
     */
    public function findBySlug(string $slug): Link|LinkDTO
    {
        $cached = $this->readCache($slug);

        if ($cached instanceof Link || $cached instanceof LinkDTO) {
            if ($cached->isExpired()) {
                throw LinkExpired::forSlug($slug, $this->expiresAtOf($cached));
            }

            return $cached;
        }

        $link = Link::query()->where('slug', $slug)->first();

        if (! $link instanceof Link) {
            throw LinkNotFound::forSlug($slug);
        }

        if ($link->isExpired()) {
            throw LinkExpired::forSlug($slug, $link->expires_at?->toImmutable());
        }

        $this->writeCache($link);

        return $link;
    }

    private function cacheKey(string $slug): string
    {
        return self::CACHE_PREFIX . $slug;
    }

    /**
     * O prefixo da chave de cache já com o do phpredis, para a função Redis.
     *
     * O phpredis prefixa o que o cliente lhe pede, e `cacheKey()` dá-lhe
     * `link:abc` para ele virar `encurta-link-database-link:abc`. Dentro do Lua não
     * há prefixo nenhum — a função escreve a chave a partir do prefixo que recebe
     * em ARGV, e o servidor não sabe nada do cliente. Se lhe passarmos só
     * `link:`, ela escreve `link:abc` e o PHP, ao ler, procura
     * `encurta-link-database-link:abc`: o acerto de cache nunca acontecia. Daí dois
     * prefixos com finalidades diferentes — `cacheKey()` para o PHP, este para o Lua.
     */
    private function cachePrefixCompleto(): string
    {
        $prefixo = config('database.redis.options.prefix');

        return (is_string($prefixo) ? $prefixo : '') . self::CACHE_PREFIX;
    }

    private function readCache(string $slug): Link|LinkDTO|null
    {
        $chave = $this->cacheKey($slug);

        $bruto = $this->run(fn () => Redis::get($chave));

        if (! is_string($bruto) || $bruto === '') {
            return null;
        }

        $payload = json_decode($bruto, true);

        $resolved = is_array($payload) ? $this->fromCachePayload($slug, $payload) : null;

        if ($resolved === null) {
            $this->run(fn () => Redis::del($chave));
        }

        return $resolved;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function fromCachePayload(string $slug, array $payload): Link|LinkDTO|null
    {
        $url = $payload['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        $exp = $payload['exp'] ?? null;

        if ($exp !== null && ! is_int($exp)) {
            return null;
        }

        $expiresAt = $exp === null ? null : CarbonImmutable::createFromTimestamp($exp);

        $id = $payload['id'] ?? null;

        if ($id === null) {
            return new LinkDTO($slug, $url, $expiresAt);
        }

        if (! is_int($id) || $id < 1) {
            return null;
        }

        // `exists()` verdadeiro porque um `id` guardado implica linha criada: o
        // consumidor escreve a cache com o `id` depois de a inserir no MySQL.
        return (new Link())->newFromBuilder([
            'id' => $id,
            'slug' => $slug,
            'original_url' => $url,
            'expires_at' => $expiresAt?->toIso8601String(),
        ]);
    }

    private function writeCache(Link $link): void
    {
        $expiresAt = $link->expires_at?->toImmutable();

        $ttl = $this->ttlFor($expiresAt);

        if ($ttl < 1) {
            return;
        }

        $payload = [
            'url' => $link->original_url,
            'exp' => $expiresAt?->getTimestamp(),
            'id' => $link->id,
        ];

        $this->run(fn () => Redis::setex(
            $this->cacheKey($link->slug),
            $ttl,
            json_encode($payload, JSON_THROW_ON_ERROR),
        ));
    }

    private function expiresAtOf(Link|LinkDTO $resolved): ?CarbonInterface
    {
        return $resolved instanceof Link
            ? $resolved->expires_at?->toImmutable()
            : $resolved->expiresAt;
    }

    private function ttlFor(?CarbonInterface $expiresAt): int
    {
        if ($expiresAt === null) {
            return self::CACHE_TTL;
        }

        return min(self::CACHE_TTL, $expiresAt->getTimestamp() - now()->getTimestamp());
    }

    private static function escaparLike(string $termo): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $termo);
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
