<?php
declare(strict_types=1);

namespace Jh2Pdf;

/**
 * Validação do JSON de layout que vem do navegador (NÃO confiável) e pequenos conversores usados por todo o desenho.
 * Formato completo em docs/format.md.
 */
final class Layout
{
    /** Operações aceitas: R retângulo, B bordas, T texto, I imagem, M marca à mão, L linha, C/E início/fim de recorte arredondado. */
    public const OPS = 'RBTILMCE';

    /** Número limitado ao intervalo; qualquer coisa que não seja número vira 0. */
    public static function num(mixed $v, float $min = -100000, float $max = 100000): float
    {
        return is_numeric($v) ? max($min, min($max, (float)$v)) : 0.0;
    }

    /** "#rrggbb" ou "#rgb" → [r, g, b] de 0 a 1; qualquer outra coisa → null (nada de nomes, rgb(), url(), var()...). */
    public static function color(mixed $v): ?array
    {
        if (!is_string($v) || !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v, $m)) return null;
        $h = strlen($m[1]) === 3 ? $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] : $m[1];
        return [hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255];
    }

    /**
     * Devolve só o que é seguro desenhar: tamanho da página limitado, páginas/operações dentro dos limites, tipos de operação conhecidos.
     * Os campos de cada operação são validados na hora de desenhar (Renderer).
     * @return array{w:float,h:float,pages:array<int,array{bg:mixed,ops:array}>}
     */
    public static function clean(array $in, ?Options $o = null): array
    {
        $o ??= new Options('');
        $w = self::num($in['w'] ?? 1280, 200, 4000);
        $h = self::num($in['h'] ?? 720, 200, 4000);
        $pages = [];
        $total = 0;
        foreach (array_slice((array)($in['pages'] ?? []), 0, $o->maxPages) as $p) {
            if (!is_array($p)) continue;
            $ops = [];
            $full = false;
            foreach (array_slice((array)($p['ops'] ?? []), 0, $o->maxOpsPerPage) as $op) {
                if (!is_array($op) || !is_string($op['t'] ?? null) || strlen($op['t']) !== 1 || strpos(self::OPS, $op['t']) === false) continue;
                if (++$total > $o->maxOpsTotal) { $full = true; break; }
                $ops[] = $op;
            }
            $pages[] = ['bg' => $p['bg'] ?? '#ffffff', 'ops' => $ops];
            if ($full) break;                                    // o resto do documento passou do limite: fica só o que coube
        }
        return ['w' => $w, 'h' => $h, 'pages' => $pages];
    }

    /** Polígono (pontos achatados x,y,x,y..., em pt) de um retângulo com cantos arredondados [tl, tr, br, bl]. */
    public static function roundRectPoints(float $x, float $y, float $w, float $h, array $r): array
    {
        [$tl, $tr, $br, $bl] = array_map(fn($v) => max(0, min($v, $w / 2, $h / 2)), $r + [0, 0, 0, 0]);
        $pts = [];
        $arc = function (float $cx, float $cy, float $rad, float $a0, float $a1) use (&$pts) {
            $n = max(3, (int)ceil($rad * 1.2));
            for ($i = 0; $i <= $n; $i++) {
                $a = deg2rad($a0 + ($a1 - $a0) * $i / $n);
                $pts[] = $cx + $rad * cos($a);
                $pts[] = $cy + $rad * sin($a);
            }
        };
        $arc($x + $tl, $y + $tl, $tl, 180, 270);
        $arc($x + $w - $tr, $y + $tr, $tr, 270, 360);
        $arc($x + $w - $br, $y + $h - $br, $br, 0, 90);
        $arc($x + $bl, $y + $h - $bl, $bl, 90, 180);
        return $pts;
    }
}
