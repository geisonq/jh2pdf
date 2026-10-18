<?php
declare(strict_types=1);

namespace Jh2Pdf;

/** Um subconjunto de SVG suficiente para os desenhos das marcas: caminhos com M L H V C S Z (absolutos e relativos), achatados em segmentos. */
final class Svg
{
    /**
     * Caminho SVG → lista de sub-caminhos de pontos [[x,y],...], com as curvas de Bézier achatadas em 14 segmentos.
     * @return array<int,array<int,array{0:float,1:float}>>
     */
    public static function flattenPath(string $d): array
    {
        preg_match_all('/([MmLlHhVvCcSsZz])|(-?\d*\.?\d+(?:e-?\d+)?)/', $d, $tok, PREG_SET_ORDER);
        $subs = [];
        $cur = [];
        $cx = $cy = 0.0;
        $sx = $sy = 0.0;
        $cmd = null;
        $nums = [];
        $lastC2 = null;
        $flush = function () use (&$subs, &$cur) {
            if (count($cur) > 1) $subs[] = $cur;
            $cur = [];
        };
        $bez = function ($x0, $y0, $x1, $y1, $x2, $y2, $x3, $y3) use (&$cur) {
            for ($i = 1; $i <= 14; $i++) {
                $t = $i / 14;
                $u = 1 - $t;
                $cur[] = [$u*$u*$u*$x0 + 3*$u*$u*$t*$x1 + 3*$u*$t*$t*$x2 + $t*$t*$t*$x3, $u*$u*$u*$y0 + 3*$u*$u*$t*$y1 + 3*$u*$t*$t*$y2 + $t*$t*$t*$y3];
            }
        };
        $arity = ['M' => 2, 'L' => 2, 'H' => 1, 'V' => 1, 'C' => 6, 'S' => 4, 'Z' => 0];
        $run = function (string $c, array $a) use (&$cur, &$cx, &$cy, &$sx, &$sy, &$lastC2, $bez, $flush) {
            $rel = ctype_lower($c);
            $C = strtoupper($c);
            $ox = $rel ? $cx : 0;
            $oy = $rel ? $cy : 0;
            switch ($C) {
                case 'M': $flush(); $cx = $ox + $a[0]; $cy = $oy + $a[1]; $sx = $cx; $sy = $cy; $cur[] = [$cx, $cy]; $lastC2 = null; break;
                case 'L': $cx = $ox + $a[0]; $cy = $oy + $a[1]; $cur[] = [$cx, $cy]; $lastC2 = null; break;
                case 'H': $cx = $ox + $a[0]; $cur[] = [$cx, $cy]; $lastC2 = null; break;
                case 'V': $cy = $oy + $a[0]; $cur[] = [$cx, $cy]; $lastC2 = null; break;
                case 'C':
                    $bez($cx, $cy, $ox + $a[0], $oy + $a[1], $ox + $a[2], $oy + $a[3], $ox + $a[4], $oy + $a[5]);
                    $lastC2 = [$ox + $a[2], $oy + $a[3]];
                    $cx = $ox + $a[4]; $cy = $oy + $a[5];
                    break;
                case 'S':
                    $x1 = $lastC2 ? 2 * $cx - $lastC2[0] : $cx;
                    $y1 = $lastC2 ? 2 * $cy - $lastC2[1] : $cy;
                    $bez($cx, $cy, $x1, $y1, $ox + $a[0], $oy + $a[1], $ox + $a[2], $oy + $a[3]);
                    $lastC2 = [$ox + $a[0], $oy + $a[1]];
                    $cx = $ox + $a[2]; $cy = $oy + $a[3];
                    break;
                case 'Z': if ($cur) $cur[] = [$sx, $sy]; $cx = $sx; $cy = $sy; $lastC2 = null; break;
            }
        };
        foreach ($tok as $t) {
            if (($t[1] ?? '') !== '') {
                if (strtoupper($t[1]) === 'Z') { $run($t[1], []); $cmd = null; $nums = []; continue; }
                $cmd = $t[1];
                $nums = [];
            } elseif ($cmd !== null) {
                $nums[] = (float)$t[2];
                if (count($nums) === $arity[strtoupper($cmd)]) {
                    $run($cmd, $nums);
                    $nums = [];
                    if (strtoupper($cmd) === 'M') $cmd = ctype_lower($cmd) ? 'l' : 'L';
                }
            }
        }
        $flush();
        return $subs;
    }

    /**
     * Os traços e preenchimentos dos `<path>` de um SVG: [[pontos, largura do traço, preenche?, traça?], ...].
     * Só lê `d`, `fill`, `stroke` e `stroke-width` (aspas simples, como nos SVG embutidos em CSS).
     * @return array<int,array{0:array,1:float,2:bool,3:bool}>
     */
    public static function paths(string $svg): array
    {
        $out = [];
        if (!preg_match_all('~<path\b([^>]*)/?>~', $svg, $paths)) return $out;
        foreach ($paths[1] as $attrs) {
            if (!preg_match("~\bd='([^']+)'~", $attrs, $d)) continue;
            $fill = preg_match("~\bfill='(?!none)[^']*'~", $attrs) === 1;
            $sw = preg_match("~stroke-width='([\d.]+)'~", $attrs, $w) ? (float)$w[1] : 2.0;
            $stroke = strpos($attrs, "stroke='") !== false && strpos($attrs, "stroke='none'") === false;
            foreach (self::flattenPath($d[1]) as $pts) $out[] = [$pts, $sw, $fill, $stroke];
        }
        return $out;
    }
}
