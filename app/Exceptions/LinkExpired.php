<?php

declare(strict_types=1);

namespace App\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

final class LinkExpired extends RuntimeException
{
    public static function forSlug(string $slug, ?CarbonInterface $expiresAt): self
    {
        return new self(sprintf(
            'O link "%s" expirou%s.',
            $slug,
            $expiresAt === null
                ? ''
                : sprintf(' em %s', $expiresAt->toIso8601String()),
        ));
    }
}
