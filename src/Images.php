<?php
declare(strict_types=1);

namespace Jh2Pdf;

/**
 * Prepara as imagens do layout para entrarem no PDF: busca os bytes pelo {@see ImageResolver}, aplica os filtros do CSS,
 * reduz ao tamanho em que a imagem aparece e desenha as sombras (`drop-shadow`). Tudo vira arquivo temporário em `tempDir`,
 * apagado por {@see cleanup()} ao fim do PDF.
 */
final class Images
{
    /** Limites de uma imagem aceita: protege a memória (uma PNG de 17 KB pode declarar 144 milhões de pixels e pedir gigabytes à GD). */
    public const MAX_PIXELS = 25_000_000;
    public const MAX_SIDE = 20000;

    /** @var array<string,string|null> */
    private array $cache = [];
    /** @var string[] */
    private array $tmp = [];
    private ImageResolver $resolver;

    public function __construct(private Options $o)
    {
        $this->resolver = $o->imageResolver ?? new DataUriImageResolver();
    }

    /** Lê só o cabeçalho (sem decodificar) e confere tipo e dimensões: PNG/JPEG/GIF/WebP de até MAX_PIXELS. */
    public static function dimensionsOk(string $bytes): bool
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) return false;
        return $info[0] > 0 && $info[1] > 0 && $info[0] <= self::MAX_SIDE && $info[1] <= self::MAX_SIDE && $info[0] * $info[1] <= self::MAX_PIXELS;
    }

    public function cleanup(): void
    {
        foreach ($this->tmp as $f) @unlink($f);
        $this->tmp = [];
        $this->cache = [];
    }

    /** Arquivo temporário (JPEG/PNG/GIF/WebP) da imagem já com os filtros, ou null. $showW = largura em px com que ela aparece. */
    public function file(string $src, string $filter, float $showW = 0): ?string
    {
        $showW = min($showW, 4000.0);                                           // a página tem no máximo 4000 px: uma imagem mais larga não ganha nada
        $key = md5($src . '|' . $filter . '|' . round($showW));
        if (array_key_exists($key, $this->cache)) return $this->cache[$key];
        $bytes = $this->resolver->load($src);
        if ($bytes === null || $bytes === '' || !self::dimensionsOk($bytes)) return $this->cache[$key] = null;
        if ($filter !== '') {
            $uri = ImageFilter::apply('data:image/png;base64,' . base64_encode($bytes), $filter, null, $showW > 0 ? (int)ceil($showW * 1.5) : null, 1.5, true);
            if ($uri && preg_match('~^data:image/(?:jpeg|png);base64,(.+)$~s', $uri, $mm)) $bytes = base64_decode($mm[1]);
        }
        if ($showW > 0) [$bytes] = self::shrink($bytes, (int)ceil($showW * 1.6));        // no máximo ~150 dpi: o arquivo não cresce a cada uso da imagem
        $info = @getimagesizefromstring($bytes);
        $ext = $info ? ([IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null) : null;
        if ($ext === null) return $this->cache[$key] = null;
        return $this->cache[$key] = $this->write($bytes, $ext);
    }

    private function write(string $bytes, string $ext): string
    {
        $f = rtrim($this->o->tempDir, '/\\') . '/lay_' . bin2hex(random_bytes(6)) . '.' . $ext;
        file_put_contents($f, $bytes);
        $this->tmp[] = $f;
        return $f;
    }

    /**
     * Reduz a imagem ao tamanho em que ela aparece. Com transparência continua PNG; sem, vira JPEG (muito menor).
     * @return array{0:string,1:?string}  [bytes, extensão] (os originais se não valer a pena)
     */
    public static function shrink(string $bytes, int $maxW): array
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info || !function_exists('imagecreatefromstring') || $maxW < 16) return [$bytes, null];
        $alpha = $info[2] === IMAGETYPE_PNG && (in_array(ord($bytes[25] ?? "\0"), [4, 6], true) || strpos(substr($bytes, 0, 8192), 'tRNS') !== false);
        if ($info[2] === IMAGETYPE_JPEG && $info[0] <= $maxW * 1.15) return [$bytes, 'jpg'];
        $img = @imagecreatefromstring($bytes);
        if (!$img) return [$bytes, null];
        if (imagesx($img) > $maxW) {
            if ($alpha) {
                // imagescale(IMG_BICUBIC) deixa o fundo transparente com alfa 125–126 de 127: no PDF vira um véu cinza em volta do desenho
                $nh = max(1, (int)round(imagesy($img) * $maxW / imagesx($img)));
                $small = imagecreatetruecolor($maxW, $nh);
                imagealphablending($small, false); imagesavealpha($small, true);
                imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
                imagecopyresampled($small, $img, 0, 0, 0, 0, $maxW, $nh, imagesx($img), imagesy($img));
            } else {
                $small = imagescale($img, $maxW, -1, IMG_BICUBIC);
            }
            if ($small) { imagedestroy($img); $img = $small; }
        }
        ob_start();
        if ($alpha) { imagealphablending($img, false); imagesavealpha($img, true); imagepng($img, null, 6); $ext = 'png'; }
        else { imagejpeg($img, null, 88); $ext = 'jpg'; }
        $out = (string)ob_get_clean();
        imagedestroy($img);
        return $out === '' ? [$bytes, null] : [$out, $ext];
    }

    /**
     * Sombra de `filter: drop-shadow(dx dy blur cor)`: um PNG só com a sombra, maior que a imagem em `pad` px de cada lado.
     * Segue o contorno da imagem (a transparência dela, ou o retângulo com os cantos arredondados) e é desfocada como no navegador
     * (desvio padrão = blur/2). Trabalha em baixa resolução (a sombra é suave); a ampliação no PDF dá o resto.
     * @param array<int,float> $radii  raios dos cantos, em px
     * @param array{0:int,1:int,2:int,3:float} $rgba
     * @return array{0:string,1:float}|null  [arquivo, pad em px]
     */
    public function shadow(?string $imgFile, float $w, float $h, array $radii, float $dx, float $dy, float $blur, array $rgba): ?array
    {
        if (!function_exists('imagecreatetruecolor') || $w < 1 || $h < 1) return null;
        $blur = max(0.0, min(80.0, $blur));
        $dx = max(-200.0, min(200.0, $dx));
        $dy = max(-200.0, min(200.0, $dy));
        $w = min($w, 4000.0);
        $h = min($h, 4000.0);
        $pad = ceil($blur * 1.2 + max(abs($dx), abs($dy)) + 2);
        $tw = $w + 2 * $pad; $th = $h + 2 * $pad;
        $s = min(0.5, sqrt(500000 / max(1, $tw * $th)));
        $cw = max(4, (int)ceil($tw * $s)); $ch = max(4, (int)ceil($th * $s));
        $mask = imagecreatetruecolor($cw, $ch);
        imagefill($mask, 0, 0, imagecolorallocate($mask, 255, 255, 255));
        $ox = (int)round(($pad + $dx) * $s); $oy = (int)round(($pad + $dy) * $s);
        $bw = max(1, (int)round($w * $s)); $bh = max(1, (int)round($h * $s));
        $black = imagecolorallocate($mask, 0, 0, 0);
        $drawn = false;
        if ($imgFile && is_file($imgFile)) {                                    // imagem com transparência: a sombra segue o desenho
            $src = @imagecreatefromstring((string)file_get_contents($imgFile));
            if ($src) {
                $small = imagecreatetruecolor($bw, $bh);
                imagealphablending($small, false); imagesavealpha($small, true);
                imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
                imagecopyresampled($small, $src, 0, 0, 0, 0, $bw, $bh, imagesx($src), imagesy($src));
                $has = false;
                for ($y = 0; $y < $bh && !$has; $y += 3) for ($x = 0; $x < $bw; $x += 3) if (((imagecolorat($small, $x, $y) >> 24) & 127) > 8) { $has = true; break; }
                if ($has) {
                    for ($y = 0; $y < $bh; $y++) for ($x = 0; $x < $bw; $x++) {
                        $a = 1 - ((imagecolorat($small, $x, $y) >> 24) & 127) / 127;     // opacidade do pixel
                        if ($a <= 0 || $ox + $x < 0 || $oy + $y < 0 || $ox + $x >= $cw || $oy + $y >= $ch) continue;
                        $g = (int)round(255 * (1 - $a));
                        imagesetpixel($mask, $ox + $x, $oy + $y, $g << 16 | $g << 8 | $g);
                    }
                    $drawn = true;
                }
                imagedestroy($small); imagedestroy($src);
            }
        }
        if (!$drawn) {                                                          // retângulo com os cantos arredondados
            [$tl, $tr, $br, $bl] = array_map(fn($v) => max(0, min((int)round($v * $s), (int)($bw / 2), (int)($bh / 2))), $radii + [0, 0, 0, 0]);
            $x0 = $ox; $y0 = $oy; $x1 = $ox + $bw - 1; $y1 = $oy + $bh - 1;
            imagefilledrectangle($mask, $x0 + max($tl, $bl), $y0, $x1 - max($tr, $br), $y1, $black);
            imagefilledrectangle($mask, $x0, $y0 + $tl, $x0 + max($tl, $bl), $y1 - $bl, $black);
            imagefilledrectangle($mask, $x1 - max($tr, $br), $y0 + $tr, $x1, $y1 - $br, $black);
            foreach ([[$tl, $x0 + $tl, $y0 + $tl], [$tr, $x1 - $tr, $y0 + $tr], [$br, $x1 - $br, $y1 - $br], [$bl, $x0 + $bl, $y1 - $bl]] as [$rr, $cx, $cy]) {
                if ($rr > 0) imagefilledellipse($mask, $cx, $cy, $rr * 2, $rr * 2, $black);
            }
        }
        $sigma = $blur / 2 * $s;                                                // a GD só tem o desfoque 3x3 (desvio ~0,7): repete até chegar no desvio pedido
        for ($i = 0, $n = (int)min(150, ceil($sigma * $sigma * 2)); $i < $n; $i++) imagefilter($mask, IMG_FILTER_GAUSSIAN_BLUR);
        $out = imagecreatetruecolor($cw, $ch);
        imagealphablending($out, false); imagesavealpha($out, true);
        $rgb = ($rgba[0] & 255) << 16 | ($rgba[1] & 255) << 8 | ($rgba[2] & 255);
        for ($y = 0; $y < $ch; $y++) for ($x = 0; $x < $cw; $x++) {
            $a = (1 - (imagecolorat($mask, $x, $y) & 255) / 255) * max(0, min(1, $rgba[3]));
            imagesetpixel($out, $x, $y, (127 - (int)round($a * 127)) << 24 | $rgb);
        }
        imagedestroy($mask);
        ob_start(); imagepng($out, null, 6); $png = (string)ob_get_clean(); imagedestroy($out);
        return $png === '' ? null : [$this->write($png, 'png'), (float)$pad];
    }
}
