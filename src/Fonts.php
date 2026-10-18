<?php
declare(strict_types=1);

namespace Jh2Pdf;

use Dompdf\FontMetrics;

/**
 * Registro das fontes no Dompdf. Cada arquivo de `fontDir` é um TTF ESTÁTICO (o Dompdf não lê woff2 nem fontes variáveis), nomeado
 * `<familia-em-minusculas-com-hifens>-<peso>[-italic].ttf` — ex.: `dm-sans-600.ttf`, `jh-serif-400-italic.ttf`. Os pesos usados são 400, 500, 600 e 700
 * (o que a fonte variável do navegador entrega); tools/generate-fonts.py gera os arquivos a partir das fontes variáveis.
 *
 * O Dompdf só conhece normal/negrito por família, então cada peso é registrado como uma "família" própria ("DM Sans W600").
 */
final class Fonts
{
    /** @var array<string,bool> famílias/pesos já tentados */
    private array $tried = [];

    public function __construct(private FontMetrics $fm, private Options $o)
    {
    }

    /**
     * Caminho (sem extensão) da fonte registrada para a família/peso/estilo pedidos.
     * @param bool $slant  saída: true se a família não tem itálico e o texto deve ser desenhado inclinado (o navegador faz o mesmo)
     */
    public function resolve(string $family, int $weight, bool $italic, ?bool &$slant = null): string
    {
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($family)), '-');
        $dir = str_replace('\\', '/', $this->o->fontDir);
        $weight = max(400, min(700, (int)(round($weight / 100) * 100)));
        $fam = "$family W$weight";
        $key = "$slug-$weight";
        if ($slug !== '' && !isset($this->tried[$key])) {
            $this->tried[$key] = false;
            $have = isset($this->fm->getFontFamilies()[mb_strtolower($fam)]);        // já registrada numa exportação anterior (fica no cache do Dompdf)
            foreach (['normal' => '', 'italic' => '-italic'] as $style => $suffix) {
                $file = "$dir/$slug-$weight$suffix.ttf";
                if (!is_file($file)) continue;
                if (!$have) $this->fm->registerFont(['family' => $fam, 'style' => $style, 'weight' => 'normal'], $file);
                $this->tried[$key] = true;
            }
        }
        $faces = $this->fm->getFontFamilies()[mb_strtolower($fam)] ?? [];
        $slant = false;
        $style = $italic ? 'italic' : 'normal';
        if ($italic && !isset($faces['italic'])) { $slant = true; $style = 'normal'; }
        $f = ($this->tried[$key] ?? false) ? $this->fm->getFont($fam, $style) : null;
        return $f ?: ($this->fm->getFont($this->o->fallbackFont, $weight >= 600 ? 'bold' : 'normal') ?: $this->fm->getFont($this->o->fallbackFont, 'normal'));
    }
}
