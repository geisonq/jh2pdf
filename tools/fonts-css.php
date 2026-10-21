<?php
/**
 * Generates the @font-face CSS for the browser from the same fonts the PDF uses.
 *
 *   php tools/fonts-css.php [--dir <folder>]... [--url <prefix>] [--out <file>] [--display block|swap|...]
 *
 *   --dir      folder with static TTFs named <family>-<weight>[-italic].ttf (repeatable; default: the library's fonts/)
 *   --url      URL prefix of the files in your site, e.g. /fonts/ (default: relative to the CSS file: none)
 *   --out      write to this file instead of the standard output
 *   --display  font-display value (default: block)
 *
 * Git Bash on Windows rewrites arguments that start with / (like --url /fonts/) as disk paths: run `MSYS_NO_PATHCONV=1 php tools/fonts-css.php ...`
 * or use PowerShell.
 *
 * Fonts registered in PHP with Options::addFont() are not on disk folders: call Jh2Pdf\FontFaces::css($options, …) from your own code for those.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 3) . '/autoload.php'] as $auto) {   // Dompdf's php-font-lib reads the family names
    if (is_file($auto)) { require $auto; break; }
}

$a = getopt('', ['dir:', 'url:', 'out:', 'display:', 'help']);
if (isset($a['help'])) {
    $doc = (string)file_get_contents(__FILE__);
    echo substr($doc, 6, (int)strpos($doc, '*/') - 6), "\n";
    exit(0);
}
$dirs = isset($a['dir']) ? (array)$a['dir'] : [dirname(__DIR__) . '/fonts'];
foreach ($dirs as $d) {
    if (!is_dir($d)) { fwrite(STDERR, "Not a directory: $d\n"); exit(1); }
}
$o = new Jh2Pdf\Options(sys_get_temp_dir(), array_shift($dirs));
foreach ($dirs as $d) $o->addFontDir($d);

$css = Jh2Pdf\FontFaces::css($o, (string)($a['url'] ?? ''), (string)($a['display'] ?? 'block'));
if (isset($a['out'])) {
    file_put_contents((string)$a['out'], $css);
    fwrite(STDERR, 'Wrote ' . substr_count($css, '@font-face {') . " @font-face rules to {$a['out']}\n");
} else {
    echo $css;
}
