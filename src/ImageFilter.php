<?php
declare(strict_types=1);

namespace Jh2Pdf;

/**
 * Filtros de imagem do CSS (`filter: grayscale() sepia() brightness() contrast() invert() blur() saturate() hue-rotate() opacity()`)
 * aplicados com a GD do PHP, já que o PDF não tem filtros. As fórmulas são as do CSS (matrizes do Filter Effects), pixel a pixel.
 */
final class ImageFilter
{
    /**
     * Aplica os filtros a uma imagem (data URI) e devolve o novo data URI, ou null se não deu para processar.
     * @param string     $filter     o valor de `filter` (computado pelo navegador também serve)
     * @param array|null $cover      [largura, altura] em px: recorta no centro para encher essa caixa (object-fit: cover)
     * @param int|null   $maxWidth   reduz a imagem a essa largura antes (o desfoque e o peso do arquivo dependem disso)
     * @param float      $zoom       fator do desfoque (px CSS → px da imagem reduzida)
     * @param bool       $keepAlpha  PNG com transparência continua PNG; senão o resultado é JPEG sobre fundo branco
     */
    public static function apply(string $dataUri, string $filter = '', ?array $cover = null, ?int $maxWidth = null, float $zoom = 1.0, bool $keepAlpha = false): ?string
    {
        if (!function_exists('imagecreatefromstring') || !preg_match('~^data:image/[a-z+]+;base64,(.+)$~s', $dataUri, $m)) return null;
        $raw = base64_decode($m[1], true);
        $img = $raw !== false && Images::dimensionsOk($raw) ? @imagecreatefromstring($raw) : false;
        if (!$img) return null;
        $hasAlpha = $keepAlpha && strncmp($raw, "\x89PNG", 4) === 0 && (in_array(ord($raw[25] ?? "\0"), [4, 6], true) || strpos(substr($raw, 0, 8192), 'tRNS') !== false);
        if ($hasAlpha) { imagepalettetotruecolor($img); imagealphablending($img, false); imagesavealpha($img, true); }
        if ($maxWidth && imagesx($img) > $maxWidth) {
            $small = imagescale($img, $maxWidth);
            if ($small) { imagedestroy($img); $img = $small; if ($hasAlpha) { imagealphablending($img, false); imagesavealpha($img, true); } }
        }
        if ($cover) {
            [$bw, $bh] = $cover;
            $sw = imagesx($img); $sh = imagesy($img);
            $scale = max($bw / $sw, $bh / $sh);
            $cw = (int)round($bw / $scale); $ch = (int)round($bh / $scale);
            $crop = imagecrop($img, ['x' => (int)(($sw - $cw) / 2), 'y' => (int)(($sh - $ch) / 2), 'width' => max(1, $cw), 'height' => max(1, $ch)]);
            if ($crop) { imagedestroy($img); $img = $crop; }
        }
        $opacity = 1.0;
        if (preg_match_all('/([a-z-]+)\(\s*(-?[\d.]+)(px|deg)?\s*\)/i', $filter, $fm, PREG_SET_ORDER)) {
            foreach ($fm as [, $fn, $v]) {
                $v = (float)$v;
                switch (strtolower($fn)) {
                    case 'grayscale': if ($v > 0) imagefilter($img, IMG_FILTER_GRAYSCALE); break;
                    case 'sepia':     if ($v > 0) self::sepia($img, min(1, $v)); break;
                    case 'brightness':                                            // o CSS MULTIPLICA (0,5 = metade); a GD soma: para escurecer, mistura com preto
                        if ($v < 1) { $bk = imagecreatetruecolor(imagesx($img), imagesy($img)); imagecopymerge($img, $bk, 0, 0, 0, 0, imagesx($img), imagesy($img), (int)round((1 - max(0, $v)) * 100)); imagedestroy($bk); }
                        else imagefilter($img, IMG_FILTER_BRIGHTNESS, (int)min(255, ($v - 1) * 128));
                        break;
                    case 'contrast':  imagefilter($img, IMG_FILTER_CONTRAST, (int)max(-100, min(100, -($v - 1) * 100))); break;   // na GD, positivo = MENOS contraste
                    case 'invert':    if ($v > 0) imagefilter($img, IMG_FILTER_NEGATE); break;
                    case 'blur':      $v *= $zoom; for ($i = 0, $n = (int)min(80, max(1, round($v * $v * 1.5))); $i < $n; $i++) imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR); break;
                    case 'saturate':  $s = max(0, $v); self::matrix($img, [.213 + .787 * $s, .715 - .715 * $s, .072 - .072 * $s, .213 - .213 * $s, .715 + .285 * $s, .072 - .072 * $s, .213 - .213 * $s, .715 - .715 * $s, .072 + .928 * $s]); break;
                    case 'hue-rotate':
                        $c = cos(deg2rad($v)); $n = sin(deg2rad($v));
                        self::matrix($img, [.213 + $c * .787 - $n * .213, .715 - $c * .715 - $n * .715, .072 - $c * .072 + $n * .928, .213 - $c * .213 + $n * .143, .715 + $c * .285 + $n * .140, .072 - $c * .072 - $n * .283, .213 - $c * .213 - $n * .787, .715 - $c * .715 + $n * .715, .072 + $c * .928 + $n * .072]);
                        break;
                    case 'opacity':   $opacity = max(0, min(1, $v)); break;
                }
            }
        }
        if ($hasAlpha) {
            ob_start(); imagepng($img, null, 6); $png = (string)ob_get_clean(); imagedestroy($img);
            return $png === '' ? null : 'data:image/png;base64,' . base64_encode($png);
        }
        if ($opacity < 1) {                                                     // mistura com o branco da página
            $w = imagesx($img); $h = imagesy($img);
            $bg = imagecreatetruecolor($w, $h);
            imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
            imagecopymerge($bg, $img, 0, 0, 0, 0, $w, $h, (int)round($opacity * 100));
            imagedestroy($img); $img = $bg;
        }
        ob_start(); imagejpeg($img, null, 88); $jpg = (string)ob_get_clean(); imagedestroy($img);
        return $jpg === '' ? null : 'data:image/jpeg;base64,' . base64_encode($jpg);
    }

    /** Sépia do CSS (a mesma matriz do `filter: sepia()`), pixel a pixel; $amt de 0 a 1. Mantém a transparência. */
    public static function sepia(\GdImage $img, float $amt): void
    {
        $w = imagesx($img); $h = imagesy($img);
        if ($w * $h > 4_000_000) return;                                       // imagem enorme: não vale o tempo de CPU
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($img, $x, $y);
                $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
                $nr = min(255, .393 * $r + .769 * $g + .189 * $b); $ng = min(255, .349 * $r + .686 * $g + .168 * $b); $nb = min(255, .272 * $r + .534 * $g + .131 * $b);
                imagesetpixel($img, $x, $y, ($c & 0x7F000000) | (int)round($r + ($nr - $r) * $amt) << 16 | (int)round($g + ($ng - $g) * $amt) << 8 | (int)round($b + ($nb - $b) * $amt));
            }
        }
    }

    /** Matriz de cores 3x3 do CSS (saturate, hue-rotate), pixel a pixel, mantendo a transparência. */
    public static function matrix(\GdImage $img, array $m): void
    {
        $w = imagesx($img); $h = imagesy($img);
        if ($w * $h > 4_000_000) return;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($img, $x, $y);
                $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
                $nr = max(0, min(255, (int)round($m[0] * $r + $m[1] * $g + $m[2] * $b)));
                $ng = max(0, min(255, (int)round($m[3] * $r + $m[4] * $g + $m[5] * $b)));
                $nb = max(0, min(255, (int)round($m[6] * $r + $m[7] * $g + $m[8] * $b)));
                imagesetpixel($img, $x, $y, ($c & 0x7F000000) | $nr << 16 | $ng << 8 | $nb);
            }
        }
    }
}
