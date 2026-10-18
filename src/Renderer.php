<?php
declare(strict_types=1);

namespace Jh2Pdf;

use Dompdf\CanvasFactory;
use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;

/**
 * Desenha o layout (JSON do navegador) em PDF com o Cpdf do Dompdf. Não interpreta HTML nem CSS: cada operação já traz
 * posição, tamanho, cor e fonte finais, então não há layout para errar. Todo o JSON é tratado como não confiável.
 *
 *     $pdf = (new Renderer(new Options('/caminho/gravavel')))->render($layoutArray, 'Título');
 *
 * Formato das operações: docs/format.md.
 */
final class Renderer
{
    /** Aninhamento máximo de recortes (C/E) por página. */
    private const MAX_CLIP_DEPTH = 32;

    public function __construct(private Options $o)
    {
    }

    /**
     * @param array  $layout  {"w","h","pages":[{"bg","ops":[...]}]} (decodificado do JSON do extrator JS)
     * @return string  os bytes do PDF
     * @throws Exception
     */
    public function render(array $layout, string $title = ''): string
    {
        if (!class_exists(Dompdf::class)) throw new Exception('Dompdf is not installed (composer require dompdf/dompdf).');
        if ($this->o->tempDir === '' || !is_dir($this->o->tempDir) || !is_writable($this->o->tempDir)) throw new Exception('Options::$tempDir must be an existing writable directory.');
        @set_time_limit(180);
        $this->raiseMemoryLimit();
        $this->collectGarbage();
        $lay = Layout::clean($layout, $this->o);
        if (!$lay['pages']) throw new Exception('No pages received.');
        $k = $this->o->pxToPt;
        $W = $lay['w'] * $k;
        $H = $lay['h'] * $k;

        $dopt = new DompdfOptions();
        $dopt->set('isRemoteEnabled', false);
        $dopt->set('isPhpEnabled', false);
        $dopt->set('isJavascriptEnabled', false);
        $dopt->set('tempDir', $this->o->tempDir);
        $dopt->set('fontDir', $this->o->tempDir);
        $dopt->set('fontCache', $this->o->tempDir);
        $dopt->set('chroot', [$this->o->tempDir, $this->o->fontDir]);
        $dompdf = new Dompdf($dopt);
        $fm = $dompdf->getFontMetrics();
        $canvas = CanvasFactory::get_instance($dompdf, [0, 0, $W, $H], 'portrait');
        $cpdf = $canvas->get_cpdf();
        $title = mb_substr((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title), 0, 200);                  // sem caracteres de controle
        if ($title !== '') $canvas->add_info('Title', $title);
        $canvas->add_info('Creator', $this->o->creator);

        $fonts = new Fonts($fm, $this->o);
        $images = new Images($this->o);
        $svgs = (new Marks($this->o->marksCss))->shapes();
        $fillRect = function (float $x, float $y, float $w, float $h, array $rgb, float $a, array $r = []) use ($canvas, $k) {
            if ($w <= 0 || $h <= 0 || $a <= 0) return;
            $rgb['alpha'] = $a;
            if (array_sum($r) > 0) $canvas->polygon(Layout::roundRectPoints($x * $k, $y * $k, $w * $k, $h * $k, array_map(fn($v) => $v * $k, $r)), $rgb, null, [], true);
            else $canvas->filled_rectangle($x * $k, $y * $k, $w * $k, $h * $k, $rgb);
        };

        $imageOps = 0;
        try {
            foreach ($lay['pages'] as $pi => $page) {
                if ($pi > 0) $canvas->new_page();
                $canvas->filled_rectangle(0, 0, $W, $H, Layout::color($page['bg']) ?? [1, 1, 1]);
                $clips = [];                                                 // pilha: true = esta abertura de recorte foi feita
                foreach ($page['ops'] as $op) {
                    $x = Layout::num($op['x'] ?? 0); $y = Layout::num($op['y'] ?? 0);
                    $w = Layout::num($op['w'] ?? 0, 0); $h = Layout::num($op['h'] ?? 0, 0);
                    $alpha = isset($op['a']) ? Layout::num($op['a'], 0, 1) : 1.0;
                    $color = Layout::color($op['c'] ?? '#000000') ?? [0, 0, 0];
                    $radii = isset($op['r']) && is_array($op['r']) ? array_map(fn($v) => Layout::num($v, 0, 4000), array_slice(array_values($op['r']), 0, 4)) : [];
                    switch ($op['t']) {
                        case 'R':
                            $fillRect($x, $y, $w, $h, $color, $alpha, $radii);
                            break;
                        case 'B':
                            $this->borders($canvas, $fillRect, $op, $x, $y, $w, $h, $radii, $k);
                            break;
                        case 'T':
                            $this->text($canvas, $cpdf, $fm, $fonts, $op, $x, $y, $w, $h, $color, $alpha, $k, $H);
                            break;
                        case 'C':                       // início de um recorte arredondado (overflow: hidden): vale até o 'E'
                            $open = count($clips) < self::MAX_CLIP_DEPTH;      // aninhamento absurdo gera um PDF que os leitores não abrem: passa do limite, não recorta
                            if ($open) $canvas->clipping_roundrectangle($x * $k, $y * $k, $w * $k, $h * $k, ...array_map(fn($v) => $v * $k, $radii + [0, 0, 0, 0]));
                            $clips[] = $open;
                            break;
                        case 'E':
                            if ($clips && array_pop($clips)) $canvas->clipping_end();
                            break;
                        case 'L':
                            $rgb = $color; $rgb['alpha'] = $alpha;
                            $canvas->line($x * $k, $y * $k, Layout::num($op['x2'] ?? 0) * $k, Layout::num($op['y2'] ?? 0) * $k, $rgb, max(.25, Layout::num($op['w'] ?? 1, 0, 100) * $k));
                            break;
                        case 'I':
                            if (++$imageOps > $this->o->maxImages) break;
                            $this->image($canvas, $images, $op, $x, $y, $w, $h, $alpha, $radii, $k);
                            break;
                        case 'M':
                            $this->mark($canvas, $svgs, $op, $x, $y, $w, $h, $color, $k);
                            break;
                    }
                }
                while ($clips) { if (array_pop($clips)) $canvas->clipping_end(); }
            }
            $out = (string)$canvas->output();
        } catch (Exception $e) {
            throw $e;
        } catch (\Throwable $e) {                          // o detalhe (caminhos do servidor) fica na exceção anterior, para o log; a mensagem é genérica
            throw new Exception('Could not render the PDF.', 0, $e);
        } finally {
            $images->cleanup();
        }
        if (substr($out, 0, 4) !== '%PDF') throw new Exception('Dompdf did not produce a PDF.');
        return $out;
    }

    /** Sobe o memory_limit para o mínimo pedido (nunca baixa o que o servidor já deu). */
    private function raiseMemoryLimit(): void
    {
        if ($this->o->memoryLimit === null) return;
        $bytes = static function (string $v): int {
            $n = (int)$v;
            $u = strtolower(substr(trim($v), -1));
            return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
        };
        $cur = (string)ini_get('memory_limit');
        if ($cur !== '-1' && $bytes($cur) < $bytes($this->o->memoryLimit)) @ini_set('memory_limit', $this->o->memoryLimit);
    }

    /** Apaga imagens temporárias esquecidas por uma geração que morreu no meio (tempo esgotado, falta de memória): mais de 1 hora. */
    private function collectGarbage(): void
    {
        foreach (glob(rtrim($this->o->tempDir, '/\\') . '/lay_*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 3600) @unlink($f);
        }
    }

    /** Bordas: quatro lados (topo, direita, base, esquerda); com cantos arredondados e lados iguais, um traço contínuo. */
    private function borders($canvas, \Closure $fillRect, array $op, float $x, float $y, float $w, float $h, array $radii, float $k): void
    {
        $sides = array_slice((array)($op['s'] ?? []), 0, 4);
        $uniform = count($sides) === 4 && array_sum($radii) > 0 && count(array_unique(array_map('json_encode', $sides))) === 1;
        if ($uniform) {
            $bw = Layout::num($sides[0][0] ?? 0, 0, 100);
            $bc = Layout::color($sides[0][1] ?? '#000') ?? [0, 0, 0];
            $bc['alpha'] = Layout::num($sides[0][2] ?? 1, 0, 1);
            if ($bw > 0) {
                $pts = Layout::roundRectPoints(($x + $bw / 2) * $k, ($y + $bw / 2) * $k, ($w - $bw) * $k, ($h - $bw) * $k, array_map(fn($v) => max(0, $v - $bw / 2) * $k, $radii));
                $np = count($pts);
                for ($i = 0; $i < $np; $i += 2) $canvas->line($pts[$i], $pts[$i + 1], $pts[($i + 2) % $np], $pts[($i + 3) % $np], $bc, $bw * $k, [], 'butt');
            }
            return;
        }
        foreach ($sides as $si => $s) {
            $bw = Layout::num($s[0] ?? 0, 0, 200);
            if ($bw <= 0) continue;
            $bc = Layout::color($s[1] ?? '#000') ?? [0, 0, 0];
            $ba = Layout::num($s[2] ?? 1, 0, 1);
            [$rx, $ry, $rw, $rh] = [[$x, $y, $w, $bw], [$x + $w - $bw, $y, $bw, $h], [$x, $y + $h - $bw, $w, $bw], [$x, $y, $bw, $h]][$si];
            $fillRect($rx, $ry, $rw, $rh, $bc, $ba);
        }
    }

    /** Uma palavra de texto na linha de base exata que o navegador mediu, com a MESMA largura (ajusta o espaço entre letras). */
    private function text($canvas, $cpdf, $fm, Fonts $fonts, array $op, float $x, float $y, float $w, float $h, array $color, float $alpha, float $k, float $H): void
    {
        $text = is_string($op['s'] ?? null) ? mb_substr($op['s'], 0, 300) : '';
        if ($text === '') return;
        $size = Layout::num($op['z'] ?? 16, 1, 600) * $k;
        $fam = is_string($op['f'] ?? null) ? mb_substr((string)preg_replace('/[^A-Za-z0-9 \-]/', '', $op['f']), 0, 60) : $this->o->fallbackFont;
        $slant = false;
        $font = $fonts->resolve($fam, (int)($op['g'] ?? (!empty($op['b']) ? 700 : 400)), !empty($op['i']), $slant);
        $cpdf->selectFont($font, '', true, true);
        $fh = $cpdf->getFontHeight($size);
        $desc = -$cpdf->getFontDescender($size);
        if ($fh <= 0) return;
        $baseline = ($y + $h * (($fh - $desc) / $fh)) * $k;                  // topo da caixa + a parte do texto acima da linha de base
        $want = $w * $k;
        $have = $fm->getTextWidth($text, $font, $size);
        $n = mb_strlen($text);
        $space = ($n > 1 && $want > 0) ? max(-2.0, min(2.0, ($want - $have) / $n)) : 0.0;
        $rgb = $color; $rgb['alpha'] = $alpha;
        $cpdf->setColor($color);
        $canvas->set_opacity($alpha);
        if ($slant) { $canvas->save(); $canvas->skew(-12, 0, $x * $k, $baseline); }          // itálico simulado (12°), como o navegador faz
        $cpdf->addText($x * $k, $H - $baseline, $size, $text, 0, 0, $space);
        if ($slant) $canvas->restore();
        $canvas->set_opacity(1.0);
        $d = (int)($op['d'] ?? 0);
        if ($d & 1) $canvas->line($x * $k, $baseline + $size * .12, ($x + $w) * $k, $baseline + $size * .12, $rgb, max(.5, $size * .06));
        if ($d & 2) $canvas->line($x * $k, $baseline - $size * .3, ($x + $w) * $k, $baseline - $size * .3, $rgb, max(.5, $size * .06));
    }

    /** Imagem com filtros do CSS, recorte (k) com cantos arredondados e sombra (drop-shadow). */
    private function image($canvas, Images $images, array $op, float $x, float $y, float $w, float $h, float $alpha, array $radii, float $k): void
    {
        $src = is_string($op['u'] ?? null) ? $op['u'] : '';
        $filter = is_string($op['f'] ?? null) ? mb_substr($op['f'], 0, 300) : '';
        $shadow = null;
        if (preg_match('~drop-shadow\(\s*(?:(rgba?\([^)]*\))\s+)?(-?[\d.]+)px\s+(-?[\d.]+)px(?:\s+([\d.]+)px)?(?:\s+(?:-?[\d.]+px\s+)?(rgba?\([^)]*\)))?\s*\)~i', $filter, $sm)) {
            $col = ($sm[1] ?? '') !== '' ? $sm[1] : ($sm[5] ?? '');
            preg_match_all('~[\d.]+~', $col, $cn);
            $cn = $cn[0] ?? [];
            $shadow = [(float)$sm[2], (float)$sm[3], (float)($sm[4] ?? 0), [(int)($cn[0] ?? 0), (int)($cn[1] ?? 0), (int)($cn[2] ?? 0), isset($cn[3]) ? (float)$cn[3] : 1.0]];
            $filter = trim(str_replace($sm[0], '', $filter));
        }
        $imgAlpha = $alpha;
        if (preg_match('~opacity\(\s*([\d.]+)(%?)\s*\)~i', $filter, $om)) {                // opacity() do filtro: transparência de verdade (não mistura com branco)
            $imgAlpha *= max(0, min(1, (float)$om[1] / ($om[2] === '%' ? 100 : 1)));
            $filter = trim(str_replace($om[0], '', $filter));
        }
        $file = $images->file($src, $filter, $w);
        if (!$file || $w <= 0 || $h <= 0) return;
        if ($shadow) {                                                                      // por fora do recorte da imagem, mas dentro dos recortes dos pais
            $sh = $images->shadow($file, $w, $h, $radii, $shadow[0], $shadow[1], $shadow[2], $shadow[3]);
            if ($sh) {
                [$sf, $sp] = $sh;
                $canvas->save();
                if ($imgAlpha < 1) $canvas->set_opacity($imgAlpha);
                $canvas->image($sf, ($x - $sp) * $k, ($y - $sp) * $k, ($w + 2 * $sp) * $k, ($h + 2 * $sp) * $k);
                $canvas->set_opacity(1.0);
                $canvas->restore();
            }
        }
        $clip = isset($op['k']) && is_array($op['k']) && count($op['k']) === 4 ? array_map(fn($v) => Layout::num($v), array_values($op['k'])) : null;
        $canvas->save();
        if ($clip && array_sum($radii) > 0) $canvas->clipping_roundrectangle($clip[0] * $k, $clip[1] * $k, max(0, $clip[2]) * $k, max(0, $clip[3]) * $k, ...array_map(fn($v) => $v * $k, $radii + [0, 0, 0, 0]));
        elseif ($clip) $canvas->clipping_rectangle($clip[0] * $k, $clip[1] * $k, max(0, $clip[2]) * $k, max(0, $clip[3]) * $k);
        if ($imgAlpha < 1) $canvas->set_opacity($imgAlpha);
        $canvas->image($file, $x * $k, $y * $k, $w * $k, $h * $k);
        $canvas->set_opacity(1.0);
        if ($clip) $canvas->clipping_end();
        $canvas->restore();
    }

    /** Marca à mão: o SVG da forma, na cor da marca, esticado na caixa do texto (a espessura do traço estica junto). */
    private function mark($canvas, array $svgs, array $op, float $x, float $y, float $w, float $h, array $color, float $k): void
    {
        $shape = is_string($op['m'] ?? null) ? $op['m'] : '';
        if (!isset($svgs[$shape], Marks::BOX[$shape])) return;
        $fs = Layout::num($op['z'] ?? 20, 4, 300);
        [$l, $r, $t, $b] = Marks::BOX[$shape];
        $bx = $x + $l * $fs;
        $bw2 = $w - ($l + $r) * $fs;
        if ($t === 'bottom') { $by = $y + $h + $fs * .32 - $b * $fs; $bh = $b * $fs; }
        elseif ($t === 'mid') { $by = $y + $h / 2 - $fs * .17; $bh = $b * $fs; }
        else { $by = $y + $t * $fs; $bh = $h - ($t + $b) * $fs; }
        if ($bw2 <= 0 || $bh <= 0) return;
        $svg = $svgs[$shape][!empty($op['v']) ? 1 : 0];
        preg_match('~viewBox=.0 0 ([\d.]+) ([\d.]+).~', $svg, $vb);
        $sx = $bw2 / (float)($vb[1] ?? 100);
        $sy = $bh / (float)($vb[2] ?? 40);
        $rgb = $color;
        $rgb['alpha'] = $shape === 'highlight' ? .5 : 1.0;
        foreach (Svg::paths($svg) as [$pts, $sw, $isFill, $isStroke]) {
            $mapped = [];
            foreach ($pts as [$px, $py]) { $mapped[] = ($bx + $px * $sx) * $k; $mapped[] = ($by + $py * $sy) * $k; }
            if ($isFill && count($mapped) >= 6) $canvas->polygon($mapped, $rgb, null, [], true);
            if (!$isStroke) continue;
            // o SVG é esticado só na largura/altura da caixa (preserveAspectRatio=none): a espessura do traço estica junto e depende da direção de cada pedaço
            for ($i = 0, $j = 0; $i + 3 < count($mapped); $i += 2, $j++) {
                $ddx = $pts[$j + 1][0] - $pts[$j][0];
                $ddy = $pts[$j + 1][1] - $pts[$j][1];
                $len = sqrt($ddx * $ddx + $ddy * $ddy);
                $sc = sqrt(($ddx * $sx) ** 2 + ($ddy * $sy) ** 2);
                $wpt = max(.4, $sc > 0 ? $sw * $sx * $sy * $len / $sc * $k : $sw * ($sx + $sy) / 2 * $k);
                $canvas->line($mapped[$i], $mapped[$i + 1], $mapped[$i + 2], $mapped[$i + 3], $rgb, $wpt, [], 'round');
            }
        }
    }
}
