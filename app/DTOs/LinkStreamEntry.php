<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * Uma entrada válida do stream `encurtador:fila`.
 *
 * O consumidor e a leitura de emergência partilham esta validação para que os
 * dois não aceitem formatos diferentes do mesmo registo.
 */
final readonly class LinkStreamEntry
{
    public function __construct(
        public string $slug,
        public string $url,
        public ?int $userId,
        public ?CarbonImmutable $expiresAt,
    ) {
    }

    /**
     * @param  array<array-key, mixed>  $fields
     */
    public static function fromFields(array $fields): ?self
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

        if (! is_string($usuario)) {
            return null;
        }

        if ($usuario !== '' && (! ctype_digit($usuario) || (int) $usuario < 1)) {
            return null;
        }

        $exp = $fields['exp'] ?? null;

        if ($exp !== null && (! is_string($exp) || ! ctype_digit($exp) || (int) $exp < 1)) {
            return null;
        }

        return new self(
            $slug,
            $url,
            $usuario === '' ? null : (int) $usuario,
            $exp === null ? null : CarbonImmutable::createFromTimestamp((int) $exp),
        );
    }
}
