<?php
declare(strict_types=1);

namespace Jh2Pdf;

/**
 * Marcas à mão (operação "M"): círculo, sublinhado, destaque, caixa, riscado e X desenhados por cima do texto.
 * Os desenhos são SVG guardados num CSS (`.mk-circle { --mk-shape: url("data:image/svg+xml,..."); }`), o mesmo CSS que a tela usa;
 * a 2ª variação (`:nth-of-type(even)`) é opcional. O padrão está em resources/marks.css.
 */
final class Marks
{
    /**
     * Onde cada desenho fica em relação ao texto marcado, em múltiplos do tamanho da fonte (como o ::after do CSS):
     * [esquerda, direita, topo, base]; negativo = para fora; 'bottom'/'mid' = ancorado na base/meio do texto.
     */
    public const BOX = [
        'underline' => [-.1, -.1, 'bottom', .5], 'circle' => [-.6, -.6, -.34, -.34], 'highlight' => [-.18, -.18, .1, -.06],
        'box' => [-.34, -.34, -.2, -.2], 'strike' => [-.14, -.14, 'mid', .36], 'cross' => [-.2, -.2, -.1, -.1],
    ];

    /** @var array<string,array<int,string>> */
    private array $shapes = [];

    public function __construct(?string $cssFile = null)
    {
        $css = (string)@file_get_contents($cssFile ?? dirname(__DIR__) . '/resources/marks.css');
        $re = '~\.mk-(underline|circle|highlight|box|strike|cross)(:nth-of-type\(even\))? \{ --mk-shape: url\("data:image/svg\+xml,([^"]+)"\)~';
        if (preg_match_all($re, $css, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $this->shapes[$x[1]][$x[2] !== '' ? 1 : 0] = rawurldecode($x[3]);
        }
        foreach ($this->shapes as $k => $v) if (!isset($v[1])) $this->shapes[$k][1] = $v[0];
    }

    /** @return array<string,array<int,string>>  forma => [svg da variação 0, svg da variação 1] */
    public function shapes(): array
    {
        return $this->shapes;
    }

    public function svg(string $shape, int $variant): ?string
    {
        return $this->shapes[$shape][$variant ? 1 : 0] ?? null;
    }
}
