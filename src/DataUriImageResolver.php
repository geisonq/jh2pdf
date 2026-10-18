<?php
declare(strict_types=1);

namespace Jh2Pdf;

/** Resolvedor padrão: aceita só imagens embutidas (`data:image/...;base64,...`). Nada de disco nem de rede. */
final class DataUriImageResolver implements ImageResolver
{
    public function __construct(private int $maxBytes = 12_000_000)
    {
    }

    public function load(string $src): ?string
    {
        if (strlen($src) > $this->maxBytes || !preg_match('~^data:image/(?:png|jpe?g|gif|webp);base64,([A-Za-z0-9+/=]+)$~', $src, $m)) return null;
        $bytes = base64_decode($m[1], true);
        return $bytes === false || $bytes === '' ? null : $bytes;
    }
}
