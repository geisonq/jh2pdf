<?php
declare(strict_types=1);

namespace Jh2Pdf;

/**
 * Decide de onde vêm os bytes de uma imagem do layout. O `src` vem do navegador e NÃO é confiável: quem implementa
 * é quem decide o que é permitido (só arquivos do usuário da requisição, só uma pasta, só data:...). Devolva null para recusar.
 */
interface ImageResolver
{
    /** @return string|null  bytes da imagem (PNG, JPEG, GIF ou WebP) ou null se o src não for aceito */
    public function load(string $src): ?string;
}
