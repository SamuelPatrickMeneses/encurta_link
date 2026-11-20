<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class LinkNotFound extends RuntimeException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('Não existe nenhum link com o slug "%s".', $slug));
    }

    public static function forId(int $linkId): self
    {
        return new self(sprintf('Não existe nenhum link com o id %d.', $linkId));
    }
}
