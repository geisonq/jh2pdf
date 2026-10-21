<?php
/**
 * Testes da biblioteca (sem framework; só PHP):   php tests/run.php
 * Precisa do Dompdf: `composer install` na pasta da biblioteca, ou o vendor/ do projeto que a hospeda (procurado nos pais).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 3) . '/vendor/autoload.php'] as $auto) {
    if (is_file($auto)) { require $auto; break; }
}

use Jh2Pdf\DataUriImageResolver;
use Jh2Pdf\Exception;
use Jh2Pdf\FontFaces;
use Jh2Pdf\FontIndex;
use Jh2Pdf\Fonts;
use Jh2Pdf\ImageFilter;
use Jh2Pdf\Images;
use Jh2Pdf\Layout;
use Jh2Pdf\Marks;
use Jh2Pdf\Options;
use Jh2Pdf\Renderer;
use Jh2Pdf\Svg;

$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
    if (!$ok) $fail++;
}

if (!class_exists(\Dompdf\Dompdf::class)) { fwrite(STDERR, "Dompdf não encontrado (composer install).\n"); exit(2); }
$tmp = sys_get_temp_dir() . '/jh2pdf-tests-' . bin2hex(random_bytes(4));
mkdir($tmp);
register_shutdown_function(function () use ($tmp) {
    foreach (glob($tmp . '/*') ?: [] as $f) is_file($f) ? @unlink($f) : null;
    @rmdir($tmp);
});
$opts = new Options($tmp);
$pageMatch = fn(string $pdf, int $n) => substr($pdf, 0, 4) === '%PDF' && preg_match('~/Count ' . $n . '~', $pdf) === 1 && preg_match('~/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+960(?:\.0+)?\s+540(?:\.0+)?\s*\]~', $pdf) === 1;

echo "Validação\n";
$evil = ['w' => 99999, 'h' => -5, 'pages' => [['bg' => 'url(x)', 'ops' => [
    ['t' => 'X', 'x' => 1], 'texto solto', ['t' => 'RR'],
    ['t' => 'T', 'x' => 'abc', 'y' => 1e99, 'w' => 50, 'h' => 20, 's' => str_repeat('A', 5000) . ')] TJ (pwn) Tj', 'f' => "Evil\x00/../../etc/passwd<script>", 'z' => 1e9, 'c' => 'javascript:alert(1)'],
    ['t' => 'I', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'u' => 'file:///etc/passwd'],
    ['t' => 'I', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'u' => 'http://evil.invalid/x.png'],
    ['t' => 'I', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'u' => '../../../config.php'],
    ['t' => 'M', 'm' => '../../x', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10],
]]]];
$clean = Layout::clean($evil);
check('limita o tamanho da página e descarta operações inválidas', $clean['w'] <= 4000 && $clean['h'] >= 200 && count($clean['pages'][0]['ops']) === 5, json_encode([$clean['w'], $clean['h'], count($clean['pages'][0]['ops'])]));
check('limite de páginas', count(Layout::clean(['pages' => array_fill(0, 1200, ['ops' => []])])['pages']) === $opts->maxPages);
check('limite de operações no documento todo', (function () {
    $o = new Options('x'); $o->maxOpsTotal = 10;
    return count(Layout::clean(['pages' => [['ops' => array_fill(0, 50, ['t' => 'R'])], ['ops' => array_fill(0, 50, ['t' => 'R'])]]], $o)['pages'][0]['ops']) === 10;
})());
check('cores só em #rgb/#rrggbb', Layout::color('#fff') == [1, 1, 1] && Layout::color('red') === null && Layout::color('#12345') === null && Layout::color(['#fff']) === null);
check('números limitados', Layout::num('abc') === 0.0 && Layout::num(1e99) === 100000.0 && Layout::num(5, 0, 3) === 3.0);

echo "SVG e marcas\n";
check('caminho SVG vira pontos', count(Svg::flattenPath('M0 0 C10 0 10 10 0 10 S-10 20 0 20 L5 25 Z')) >= 1);
check('traços e preenchimentos de um <path>', count(Svg::paths("<svg><path d='M0 0 L10 10' fill='none' stroke='black' stroke-width='3'/></svg>")) === 1);
$marks = new Marks();
check('as 6 formas de marca, com 2 variações', count($marks->shapes()) === 6 && count(array_filter($marks->shapes(), fn($v) => count($v) === 2)) === 6);

echo "Fontes\n";
$names = [];
foreach (glob($opts->fontDir . '/*.ttf') ?: [] as $f) {
    if (preg_match('~/(.+)-(400|500|600|700)(-italic)?\.ttf$~', str_replace('\\', '/', $f), $m)) $names[$m[1] . ($m[3] ?? '')][] = (int)$m[2];
}
$incompleto = array_keys(array_filter($names, fn($w) => count($w) !== 4));
check('cada família tem os 4 pesos (400, 500, 600, 700)', $names && !$incompleto, implode(', ', $incompleto));
$dompdf = new \Dompdf\Dompdf((new \Dompdf\Options())->set('fontDir', $tmp)->set('fontCache', $tmp)->set('tempDir', $tmp)->set('chroot', [$tmp, $opts->fontDir]));
$fonts = new Fonts($dompdf->getFontMetrics(), $opts);
$sl = null;
$f400 = $fonts->resolve('DM Sans', 400, false, $sl);
$f600 = $fonts->resolve('DM Sans', 600, false, $sl);
$fi = $fonts->resolve('DM Sans', 500, true, $sl); $slItalic = $sl;
$fj = $fonts->resolve('JetBrains Mono', 400, true, $sl);
check('cada peso usa o seu arquivo', $f400 !== $f600 && $fi !== $f400 && stripos($f600, 'dejavu') === false);
check('com itálico na família: não inclina; sem itálico: inclina a normal', $slItalic === false && $sl === true);
check('família desconhecida cai na fonte de reserva', stripos($fonts->resolve('Nao Existe', 400, false), 'dejavu') !== false);

echo "Imagens\n";
$png = imagecreatetruecolor(40, 30); imagealphablending($png, false); imagesavealpha($png, true);
imagefill($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127)); imagefilledrectangle($png, 5, 5, 34, 24, imagecolorallocate($png, 200, 40, 40));
ob_start(); imagepng($png); $pngBytes = (string)ob_get_clean(); imagedestroy($png);
$uri = 'data:image/png;base64,' . base64_encode($pngBytes);
$resolver = new DataUriImageResolver();
check('resolvedor padrão: só data:image', $resolver->load($uri) === $pngBytes && $resolver->load('file:///etc/passwd') === null && $resolver->load('http://x/y.png') === null && $resolver->load('../a.png') === null && $resolver->load('data:text/html;base64,PGI+') === null);
$out = ImageFilter::apply($uri, 'saturate(2) hue-rotate(90deg)', null, null, 1.0, true);
$img = $out && preg_match('~^data:image/png;base64,(.+)$~s', $out, $mm) ? @imagecreatefromstring(base64_decode($mm[1])) : false;
check('filtros saturate/hue-rotate mantêm a transparência do PNG', $img && ((imagecolorat($img, 0, 0) >> 24) & 127) === 127 && ((imagecolorat($img, 20, 15) >> 24) & 127) === 0);
$gray = ImageFilter::apply($uri, 'grayscale(1)', null, null, 1.0, false);
check('sem keepAlpha o resultado é JPEG', $gray !== null && str_starts_with($gray, 'data:image/jpeg;base64,'));
$images = new Images($opts);
$sh = $images->shadow(null, 40, 30, [4, 4, 4, 4], 0, 6, 14, [0, 0, 0, 0.35]);
$info = $sh ? getimagesize($sh[0]) : false;
check('sombra: PNG maior que a imagem, com transparência', $info && $info[2] === IMAGETYPE_PNG && $sh[1] > 10 && $info[0] > 20);
$file = $images->file($uri, 'sepia(1)', 40);
check('imagem com filtro vira arquivo temporário em tempDir', $file !== null && is_file($file) && str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $tmp)));
check('src recusado pelo resolvedor não vira arquivo', $images->file('file:///etc/passwd', '', 10) === null);
$images->cleanup();
check('cleanup apaga os temporários', !is_file($file));

echo "PDF\n";
$ops = [
    ['t' => 'R', 'x' => 40, 'y' => 40, 'w' => 300, 'h' => 80, 'c' => '#4f46e5', 'a' => 1, 'r' => [8, 8, 8, 8]],
    ['t' => 'B', 'x' => 40, 'y' => 40, 'w' => 300, 'h' => 80, 's' => [[2, '#000000', 1], [2, '#000000', 1], [2, '#000000', 1], [2, '#000000', 1]], 'r' => [8, 8, 8, 8]],
    ['t' => 'C', 'x' => 40, 'y' => 40, 'w' => 300, 'h' => 80, 'r' => [8, 8, 8, 8]],
    ['t' => 'T', 'x' => 60, 'y' => 60, 'w' => 120, 'h' => 30, 's' => 'Olá', 'f' => 'DM Sans', 'z' => 24, 'c' => '#ffffff', 'g' => 600],
    ['t' => 'E'],
    ['t' => 'T', 'x' => 60, 'y' => 160, 'w' => 200, 'h' => 30, 's' => 'palavra', 'f' => 'Jh Serif', 'z' => 24, 'c' => '#000000', 'd' => 1],
    ['t' => 'T', 'x' => 60, 'y' => 200, 'w' => 200, 'h' => 30, 's' => 'inclinado', 'f' => 'JetBrains Mono', 'z' => 24, 'c' => '#000000', 'i' => 1],
    ['t' => 'I', 'x' => 400, 'y' => 40, 'w' => 80, 'h' => 60, 'u' => $uri, 'k' => [400, 40, 80, 60], 'r' => [4, 4, 4, 4], 'f' => 'drop-shadow(rgba(0, 0, 0, 0.35) 0px 6px 14px) sepia(1)'],
    ['t' => 'M', 'm' => 'circle', 'v' => 1, 'x' => 60, 'y' => 160, 'w' => 100, 'h' => 30, 'c' => '#f08c1f', 'z' => 24],
    ['t' => 'L', 'x' => 10, 'y' => 10, 'x2' => 200, 'y2' => 10, 'c' => '#ff0000', 'w' => 2],
];
$pdf = (new Renderer($opts))->render(['w' => 1280, 'h' => 720, 'pages' => [['bg' => '#ffffff', 'ops' => $ops], ['bg' => '#000000', 'ops' => []]]], 'Teste');
check('desenha todas as operações num PDF de 2 páginas 16:9', $pageMatch($pdf, 2));
check('título e criador nos metadados', strpos($pdf, '/Title') !== false && strpos($pdf, '/Creator') !== false);
$t0 = microtime(true);
$out = (new Renderer($opts))->render($evil);
check('entradas hostis não quebram nem acessam arquivos/rede', substr($out, 0, 4) === '%PDF' && microtime(true) - $t0 < 15 && strpos($out, 'pwn') === false && strpos($out, 'root:') === false);
check('não deixa arquivos temporários para trás', !glob($tmp . '/lay_*'));
$thrown = false;
try { (new Renderer($opts))->render(['pages' => []]); } catch (Exception $e) { $thrown = true; }
check('sem páginas: lança Jh2Pdf\Exception', $thrown);
$thrown = false;
try { (new Renderer(new Options($tmp . '/nao-existe')))->render(['pages' => [['ops' => []]]]); } catch (Exception $e) { $thrown = true; }
check('tempDir inexistente: lança Jh2Pdf\Exception', $thrown);

echo "Segurança (entrada hostil)\n";
$bad = ')>> /OpenAction << /S /JavaScript /JS (app.alert(1)) >> /URI (http://evil.invalid) /X (';
$pdf = (new Renderer($opts))->render(['pages' => [['ops' => [
    ['t' => 'T', 'x' => 10, 'y' => 10, 'w' => 300, 'h' => 30, 's' => $bad, 'f' => 'DM Sans', 'z' => 20, 'c' => '#000000'],
    ['t' => 'T', 'x' => 10, 'y' => 60, 'w' => 300, 'h' => 30, 's' => 'ok', 'f' => 'DM Sans) /JS (x', 'z' => 20, 'c' => '#000'],
    ['t' => 'T', 'x' => 10, 'y' => 90, 'w' => 300, 'h' => 30, 's' => 'ok', 'f' => '../../../etc/passwd', 'z' => 20, 'c' => '#000'],
]]]], $bad . "\x00\r\n");
$all = $pdf;
if (preg_match_all('~stream\r?\n(.*?)endstream~s', $pdf, $mm)) foreach ($mm[1] as $st) { $d = @gzuncompress($st); if ($d !== false) $all .= "\n" . $d; }
$found = array_filter(['/OpenAction', '/JavaScript', '/JS ', '/Launch', '/URI', '/SubmitForm', '/EmbeddedFile', '/AA '], fn($k) => strpos($all, $k) !== false);
check('texto, título e nome de fonte hostis não viram objetos do PDF (JS, OpenAction, URI, Launch…)', !$found, implode(',', $found));
// imagem que declara centenas de milhões de pixels: recusada pelo cabeçalho, sem decodificar (memória e tempo)
$im = imagecreate(12000, 12000); imagecolorallocate($im, 255, 255, 255); ob_start(); imagepng($im, null, 9); $bomb = (string)ob_get_clean(); imagedestroy($im);
$t0 = microtime(true); $m0 = memory_get_peak_usage();
$pdf = (new Renderer($opts))->render(['pages' => [['ops' => [['t' => 'I', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'u' => 'data:image/png;base64,' . base64_encode($bomb), 'f' => 'blur(5px)']]]]]);
check('imagem de 144 milhões de pixels (17 KB) é recusada sem estourar memória/tempo', substr($pdf, 0, 4) === '%PDF' && microtime(true) - $t0 < 3 && memory_get_peak_usage() - $m0 < 64 * 1048576);
check('Images::dimensionsOk: só PNG/JPEG/GIF/WebP dentro dos limites', Images::dimensionsOk($pngBytes) && !Images::dimensionsOk($bomb) && !Images::dimensionsOk('<svg xmlns="http://www.w3.org/2000/svg"/>') && !Images::dimensionsOk('GIF89a'));
// só as primeiras maxImages imagens são processadas
$o2 = new Options($tmp); $o2->maxImages = 2;
$img = ['t' => 'I', 'x' => 0, 'y' => 0, 'w' => 40, 'h' => 30, 'u' => $uri];
$p2 = (new Renderer($o2))->render(['pages' => [['ops' => [$img, $img, $img, $img, $img]]]]);
$p5 = (new Renderer($opts))->render(['pages' => [['ops' => [$img, $img, $img, $img, $img]]]]);
check('maxImages limita as imagens desenhadas', substr($p2, 0, 4) === '%PDF' && strlen($p2) < strlen($p5));
// PNG transparente reduzido: o fundo continua 100% transparente (imagescale bicúbico deixava alfa 125–126 → véu cinza no PDF)
check('Images::shrink mantém transparente o fundo de um PNG reduzido', (function () {
    $im = imagecreatetruecolor(240, 240);
    imagealphablending($im, false); imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledellipse($im, 120, 120, 160, 160, imagecolorallocatealpha($im, 40, 80, 220, 0));
    ob_start(); imagepng($im); $png = (string)ob_get_clean(); imagedestroy($im);
    [$out, $ext] = Images::shrink($png, 150);
    $small = imagecreatefromstring($out);
    $ok = $ext === 'png' && imagesx($small) === 150;
    foreach ([[0, 0], [149, 0], [0, 149], [149, 149], [75, 2]] as [$x, $y]) $ok = $ok && ((imagecolorat($small, $x, $y) >> 24) & 127) === 127;
    return $ok && ((imagecolorat($small, 75, 75) >> 24) & 127) === 0;
})());
// recortes: aninhamento absurdo e E sem C não quebram o PDF
$deep = array_merge(array_fill(0, 500, ['t' => 'C', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'r' => [4, 4, 4, 4]]), [['t' => 'E'], ['t' => 'E'], ['t' => 'E']], array_fill(0, 600, ['t' => 'E']));
$pdf = (new Renderer($opts))->render(['pages' => [['ops' => $deep]]]);
check('recortes aninhados demais ou fechados a mais não quebram o PDF', substr($pdf, 0, 4) === '%PDF');
// números extremos e tipos errados
$weird = ['w' => '1e999', 'h' => [], 'pages' => [['bg' => ['x'], 'ops' => [
    ['t' => 'R', 'x' => '1e999', 'y' => -1e300, 'w' => 'NaN', 'h' => INF, 'c' => ['#fff'], 'r' => 'x'],
    ['t' => 'B', 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 50, 's' => 'x', 'r' => [1e9, -5]],
    ['t' => 'T', 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 20, 's' => ['a'], 'f' => ['b'], 'g' => 'x', 'z' => 'y'],
    ['t' => 'I', 'x' => 0, 'y' => 0, 'w' => 1e9, 'h' => 1e9, 'u' => ['x'], 'k' => 'x', 'f' => str_repeat('blur(80px) ', 500)],
    ['t' => 'M', 'm' => 'circle', 'x' => 0, 'y' => 0, 'w' => 1e9, 'h' => 1e9, 'z' => 1e9],
    ['t' => 'L', 'x' => 0, 'y' => 0, 'x2' => 'a', 'y2' => [], 'w' => 1e99],
]]]];
$t0 = microtime(true);
$pdf = (new Renderer($opts))->render($weird);
check('números extremos, NaN/INF e tipos errados não quebram nem demoram', substr($pdf, 0, 4) === '%PDF' && microtime(true) - $t0 < 10);
check('nenhum arquivo temporário sobra depois dos ataques', !glob($tmp . '/lay_*'));

echo "Fontes: registro, peso, pilha e CSS\n";
$fontDir = dirname(__DIR__) . '/fonts';
$nw = fn(int $want, array $have) => Fonts::nearestWeight($want, $have);
check('Fonts::nearestWeight segue a regra do CSS', $nw(400, [400, 700]) === 400 && $nw(300, [400, 700]) === 400 && $nw(500, [400, 700]) === 400
    && $nw(600, [400, 700]) === 700 && $nw(800, [400, 700]) === 700 && $nw(900, [400, 700]) === 700 && $nw(100, [400, 700]) === 400
    && $nw(450, [400, 500]) === 500 && $nw(400, [300, 500]) === 500 && $nw(300, [200, 400]) === 200 && $nw(900, [100]) === 100 && $nw(400, []) === null,
    json_encode([$nw(500, [400, 700]), $nw(450, [400, 500]), $nw(300, [200, 400])]));
$idx = new FontIndex($opts);
check('FontIndex acha as famílias da pasta, com pesos e itálicos', count($idx->families()) === 12 && $idx->has('inter') && $idx->has('dm-sans')
    && array_keys($idx->faces('inter')) === [400, 500, 600, 700] && isset($idx->faces('dm-sans')[600][1]) && !isset($idx->faces('inter')[400][1]) && !$idx->has('roboto') && !$idx->has(''),
    implode(',', $idx->families()));
check('FontIndex::slug', FontIndex::slug('DM Sans') === 'dm-sans' && FontIndex::slug('../../x') === 'x' && FontIndex::slug("  A 'b'-1 ") === 'a-b-1');

// registro explícito: arquivo com qualquer nome, valores inválidos, prioridade e pastas extras
$brand = $tmp . '/Brand_Face.ttf';
copy($fontDir . '/montserrat-700.ttf', $brand);
$extra = $tmp . '/extra';
mkdir($extra);
copy($fontDir . '/nunito-400.ttf', $extra . '/acme-sans-400.ttf');
copy($fontDir . '/nunito-700.ttf', $extra . '/acme-sans-700.ttf');
copy($fontDir . '/nunito-400.ttf', $extra . '/not a font name.ttf');
$o = new Options($tmp);
$o->addFont('Acme Sans', 700, $brand)->addFontDir($extra)->addFont('Inter', 400, $fontDir . '/jetbrains-mono-400.ttf');
$ix = new FontIndex($o);
$fw = fn(string $p) => str_replace('\\', '/', $p);          // no Windows os caminhos misturam \ e /
check('addFont e addFontDir: família nova, pasta extra e prioridade de addFont', $ix->faces('acme-sans')[700][0] === $fw($brand) && $ix->faces('acme-sans')[400][0] === $fw($extra) . '/acme-sans-400.ttf'
    && $ix->faces('inter')[400][0] === $fw($fontDir) . '/jetbrains-mono-400.ttf' && $ix->faces('inter')[700][0] === $fw($fontDir) . '/inter-700.ttf'
    && $ix->name('acme-sans') === 'Acme Sans' && !$ix->has('not-a-font-name'), json_encode([$ix->faces('acme-sans'), $ix->faces('inter')[400] ?? null]));
$thrown = function (callable $f): bool {
    try { $f(); return false; } catch (Exception $e) { return !str_contains($e->getMessage(), '/') && !str_contains($e->getMessage(), '\\'); }
};
check('addFont/addFontDir recusam entradas inválidas (sem vazar caminhos)', $thrown(fn() => (new Options($tmp))->addFont('X', 400, $tmp . '/nao-existe.ttf'))
    && $thrown(fn() => (new Options($tmp))->addFont('X', 450, $brand)) && $thrown(fn() => (new Options($tmp))->addFont('X', 0, $brand))
    && $thrown(fn() => (new Options($tmp))->addFont('!!!', 400, $brand)) && $thrown(fn() => (new Options($tmp))->addFont('X', 400, $extra . '/not a font name.png'))
    && $thrown(fn() => (new Options($tmp))->addFontDir($tmp . '/nao-existe')));

$T = fn(array $x) => $x + ['t' => 'T', 'x' => 10, 'y' => 10, 'w' => 100, 'h' => 20, 's' => 'Hello', 'z' => 16, 'c' => '#000000'];
$render = function (array $ops, ?Options $opt = null) use ($opts, &$renderer) {
    $renderer = new Renderer($opt ?? $opts);
    return $renderer->render(['w' => 400, 'h' => 300, 'pages' => [['bg' => '#ffffff', 'ops' => $ops]]]);
};
$names = function (string $pdf): array {
    preg_match_all('~/BaseFont\s*/(?:[A-Z]{6}\+)?([A-Za-z0-9\-]+)~', $pdf, $m);
    return array_values(array_unique($m[1]));
};

$pdf = $render([$T(['f' => 'Acme Sans', 'g' => 700])], $o);
check('addFont: o PDF usa o arquivo registrado (nome de arquivo livre) e nada falta', $names($pdf) === ['Montserrat-Bold'] && $renderer->missingFonts() === [], json_encode($names($pdf)));
$pdf = $render([$T(['f' => 'Acme Sans', 'g' => 400])], $o);
check('addFontDir: o PDF usa a fonte da pasta extra', $names($pdf) === ['Nunito-Regular'], json_encode($names($pdf)));

// peso mais próximo e itálico
$w = fn(array $x) => $names($render([$T($x + ['f' => 'Inter'])]));
check('peso mais próximo: 300→Regular, 500→Medium, 600→SemiBold, 800/900→Bold', $w(['g' => 300]) === ['Inter-Regular'] && $w(['g' => 500]) === ['Inter-Medium']
    && $w(['g' => 600]) === ['Inter-SemiBold'] && $w(['g' => 800]) === ['Inter-Bold'] && $w(['g' => 900]) === ['Inter-Bold'], json_encode([$w(['g' => 300]), $w(['g' => 500]), $w(['g' => 800])]));
check('peso inválido no JSON vira 400; enorme vira o mais pesado', $w(['g' => 'x']) === ['Inter-Regular'] && $w(['g' => -5]) === ['Inter-Regular'] && $w(['g' => 1e9]) === ['Inter-Bold'] && $w(['g' => [700]]) === ['Inter-Regular']);
check('itálico real quando a família tem; simulado (sem erro) quando não tem', $names($render([$T(['f' => 'Jh Serif', 'g' => 700, 'i' => 1])])) === ['JhSerif-BoldItalic']
    && $names($render([$T(['f' => 'Inter', 'g' => 700, 'i' => 1])])) === ['Inter-Bold']);

// pilha de famílias (font-family com reservas) e limites
$pdf = $render([$T(['f' => 'Nope Sans', 'fb' => 'Also Nope,Inter'])]);
check('pilha: usa a primeira família da lista que o servidor tem, sem reportar falta', $names($pdf) === ['Inter-Regular'] && $renderer->missingFonts() === [], json_encode($names($pdf)));
$render([$T(['f' => 'Nope Sans', 'fb' => 'a1,a2,a3,a4,a5,a6,Inter'])]);
check('pilha: só as 5 primeiras reservas contam (resto é ignorado)', $renderer->missingFonts() === [['family' => 'Nope Sans', 'weight' => 400, 'italic' => false]]);
$pdf = $render([$T(['f' => 'Inter)] TJ (pwn', 'fb' => "../../etc/passwd, \x00Inter, Inter"])]);
check('pilha hostil (caminhos, bytes nulos, injeção) é limpa e não quebra', substr($pdf, 0, 4) === '%PDF' && $names($pdf) === ['Inter-Regular'] && !str_contains($pdf, 'passwd'), json_encode($names($pdf)));
$pdf = $render([$T(['f' => ['x'], 'fb' => ['y']]), $T(['f' => 7, 'fb' => 7]), $T([])]);
check('f/fb de tipos errados ou ausentes: usa a fonte de reserva, sem aviso de falta', substr($pdf, 0, 4) === '%PDF' && $renderer->missingFonts() === []);

// fonte faltando: relatório, callback, modo estrito
$seen = [];
$lenient = new Options($tmp);
$lenient->onMissingFont = function (string $family, int $weight, bool $italic) use (&$seen) { $seen[] = "$family/$weight/" . (int)$italic; };
$pdf = $render([$T(['f' => 'Roboto']), $T(['f' => 'Roboto', 's' => 'again']), $T(['f' => 'Lato', 'g' => 700, 'i' => 1]), $T(['f' => 'Inter']), $T(['s' => 'sem família'])], $lenient);
check('fonte faltando: PDF sai com a fonte de reserva, relatório sem repetição e callback uma vez cada',
    substr($pdf, 0, 4) === '%PDF' && in_array('DejaVuSans', $names($pdf), true)
    && $renderer->missingFonts() === [['family' => 'Roboto', 'weight' => 400, 'italic' => false], ['family' => 'Lato', 'weight' => 700, 'italic' => true]]
    && $seen === ['Roboto/400/0', 'Lato/700/1'], json_encode([$renderer->missingFonts(), $seen]));
$strict = new Options($tmp);
$strict->strictFonts = true;
$msg = '';
try { $render([$T(['f' => 'Inter']), $T(['f' => 'Roboto']), $T(['f' => 'Lato', 'g' => 700, 'i' => 1])], $strict); } catch (Exception $e) { $msg = $e->getMessage(); }
check('strictFonts: lança antes de desenhar, listando o que falta, sem caminhos e sem sobras', $msg === 'Missing fonts: Roboto 400, Lato 700 italic.' && !glob($tmp . '/lay_*'), $msg);
$ok = $render([$T(['f' => 'Inter']), $T(['s' => 'sem família'])], $strict);
check('strictFonts: não recusa quando tudo existe (nem palavras sem família)', substr($ok, 0, 4) === '%PDF' && $renderer->missingFonts() === []);

// CSS do navegador gerado das mesmas fontes
$css = FontFaces::css($opts, '/f/');
$rules = array_values(array_filter(explode("\n", $css), fn($l) => str_starts_with($l, '@font-face')));
preg_match_all("~font-family: '([^']+)'~", $css, $fm);
check('FontFaces::css: uma regra por arquivo, com os nomes certos das 12 famílias', count($rules) === 56 && count(array_unique($fm[1])) === 12
    && in_array('DM Sans', $fm[1], true) && in_array('JetBrains Mono', $fm[1], true) && in_array('Jh Serif Book', $fm[1], true) && !in_array('DM Sans 9pt', $fm[1], true)
    && str_contains($css, "font-family: 'Inter'; font-weight: 600; font-style: normal; font-display: block; src: url('/f/inter-600.ttf') format('truetype');")
    && str_contains($css, "font-family: 'Jh Serif'; font-weight: 700; font-style: italic;"), count($rules) . ' regras');
$cssO = FontFaces::css($o, fn(string $file) => 'https://cdn.example/' . basename($file), 'evil');
check('FontFaces::css: família registrada com addFont, URL por função e font-display validado',
    str_contains($cssO, "font-family: 'Acme Sans'; font-weight: 700; font-style: normal; font-display: block; src: url('https://cdn.example/Brand_Face.ttf')") && !str_contains($cssO, 'evil'));
$none = $tmp . '/nenhuma';
mkdir($none);
$q = new Options($tmp, $none);                                   // pasta de fontes vazia: só o que veio de addFont
$q->addFont("O'Neil \\ Sans\n", 400, $brand);
$cq = FontFaces::css($q, "/a'b/");
check('FontFaces::css escapa aspas, barras e quebras de linha', str_contains($cq, "font-family: 'O\\'Neil \\\\ Sans'") && str_contains($cq, "url('/a\\'b/Brand_Face.ttf')") && substr_count($cq, "\n") === 2, $cq);
copy($fontDir . '/inter-400.ttf', $tmp . '/my-font-400.ttf');
check('FontFaces::familyName: lê o nome do TTF; se não casar com o arquivo, usa o nome do arquivo',
    FontFaces::familyName($fontDir . '/inter-400.ttf', 'inter') === 'Inter' && FontFaces::familyName($fontDir . '/dm-sans-500.ttf', 'dm-sans') === 'DM Sans'
    && FontFaces::familyName($tmp . '/my-font-400.ttf', 'my-font') === 'My Font');
check('nenhum arquivo temporário sobra depois dos testes de fontes', !glob($tmp . '/lay_*'));

echo $fail ? "\n$fail FALHA(S)\n" : "\nTudo certo.\n";
exit($fail ? 1 : 0);
