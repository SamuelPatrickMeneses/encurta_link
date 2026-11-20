<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * O que a resolução de um slug devolve enquanto a criação não está confirmada.
 *
 * O consumidor do STREAM escreve a cache no momento da criação, antes de a linha
 * existir. Nesse intervalo o slug já responde e ainda não há `id` para dar. Em vez
 * de afrouxar o modelo — cujo `id` é `int` porque no MySQL é sempre — devolve-se
 * isto, e a ausência de `id` é precisamente o que diz que a criação está em voo.
 *
 * Não substitui o `Link` fora desse intervalo: com o `id` conhecido, o que se
 * devolve é a entidade, e `exists()` é verdadeiro porque um `id` guardado implica
 * linha criada.
 */
final readonly class LinkDTO
{
    public function __construct(
        public string $slug,
        public string $url,
        public ?CarbonImmutable $expiresAt,
    ) {
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->isPast();
    }
}
