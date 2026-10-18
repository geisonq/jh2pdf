<?php
declare(strict_types=1);

namespace Jh2Pdf;

/** Configuração do {@see Renderer}. Só `tempDir` é obrigatório. */
final class Options
{
    /** Pasta gravável: cache de fontes do Dompdf e imagens temporárias (apagadas ao fim de cada PDF). */
    public string $tempDir;

    /** Pasta com os TTF estáticos (`<familia>-<peso>[-italic].ttf`). Padrão: a pasta `fonts/` da biblioteca. */
    public string $fontDir;

    /** De onde vêm as imagens. Padrão: só `data:image/...` ({@see DataUriImageResolver}). */
    public ?ImageResolver $imageResolver = null;

    /** Arquivo CSS com os desenhos das marcas à mão (`--mk-shape`). Padrão: `resources/marks.css` da biblioteca. */
    public ?string $marksCss = null;

    /** Metadado "Creator" do PDF. */
    public string $creator = 'Jh2Pdf';

    /** Fonte usada quando a família pedida não existe em `fontDir` (acompanha o Dompdf). */
    public string $fallbackFont = 'DejaVu Sans';

    /** Limites: o JSON vem do navegador e não pode pedir trabalho sem fim. */
    public int $maxPages = 1000;
    public int $maxOpsPerPage = 60000;
    public int $maxOpsTotal = 400000;

    /** No máximo quantas imagens (operações "I") são processadas por PDF; as demais são ignoradas (cada uma custa CPU e memória da GD). */
    public int $maxImages = 200;

    /** `memory_limit` mínimo durante a geração (a GD e o Dompdf precisam). Só SOBE o limite atual, nunca baixa; null = não mexer. */
    public ?string $memoryLimit = '512M';

    /** Tamanho do texto/imagens em pt por px CSS (96 dpi → 0,75). */
    public float $pxToPt = 0.75;

    public function __construct(string $tempDir, ?string $fontDir = null)
    {
        $this->tempDir = $tempDir;
        $this->fontDir = $fontDir ?? dirname(__DIR__) . '/fonts';
    }
}
