<p align="center"><img src="assets/logo.png" alt="Jh2pdf — JavaScript HTML 2 PDF" width="340"></p>
<p align="center"><a href="https://geisonq.github.io/jh2pdf/"><b>Visit the website: geisonq.github.io/jh2pdf</b></a></p>

# Jh2Pdf

**English** · [Português](README.pt-BR.md) · [Español](README.es.md)

**Pixel-perfect HTML to PDF, without a browser on the server.** No headless Chrome, no Puppeteer, no wkhtmltopdf. Runs on shared hosting (just PHP + GD).

📖 **[Full documentation](https://geisonq.github.io/jh2pdf/docs/)** · 🎨 **[Examples gallery](https://geisonq.github.io/jh2pdf/demo/)** (tables, gradients, shadows, filtered images, lists, an invoice) · 📄 [MIT license](LICENSE)

## Why it exists

Generating PDFs from HTML usually ends up in one of these dead ends:

| Option | Problem |
| --- | --- |
| Chrome/Puppeteer on the server | needs a VPS/container; does not run on shared hosting |
| wkhtmltopdf | a binary on the server, outdated CSS, abandoned project |
| Dompdf / mPDF / TCPDF parsing the HTML | only understand CSS 2.1: no flexbox, grid, CSS variables, `clamp()`, filters… the PDF looks different from the screen |
| External PDF API | cost, a third-party dependency, and your content leaves your server |

The idea here: **the exporting user's browser is already the best layout engine there is, and it is already open**. Instead of reimplementing CSS in PHP,
the browser computes where every word, box, border and image goes; PHP only draws those positions into a PDF. There is no line breaking or layout
computation on the server to get wrong.

## How it works

```
 BROWSER (JS)                                           SERVER (PHP)
 ────────────                                           ────────────
 your HTML + your CSS                                   Options  (tempDir, fontDir, imageResolver…)
   │ renders off-screen (fonts and images loaded)          │
   ▼                                                       ▼
 extractLayout()  walks the DOM:                        Layout::clean()   validates everything (never trusts the JSON)
   getComputedStyle + Range.getClientRects per word        │
   │                                                       ▼
   ▼                                                    Renderer::render()   Dompdf\Cpdf
 JSON of drawing operations ── POST ─────────────────►    R rectangle · B borders · T text · I image
 { w, h, pages:[{ bg, ops:[…] }] }                         M hand mark · L line · C/E rounded clip
                                                           │
                                                           ▼
                                                        PDF bytes  (selectable text, embedded fonts)
```

What the browser measures and PHP simply reproduces: the position and size of **every word** (exact width, baseline), backgrounds, per-side borders,
rounded corners, `overflow: hidden` with rounded corners (clipping), images with `object-fit`, filters (`grayscale`, `sepia`, `blur`, `saturate`,
`hue-rotate`…), `drop-shadow`, list markers, opacity, `text-transform`, `letter-spacing`, underline/line-through.

## Requirements

- **Browser:** any modern one (Chromium, Firefox, Safari). The layout it computes is the PDF's layout.
- **Server:** PHP ≥ 8.1, the `gd` and `mbstring` extensions, and [`dompdf/dompdf`](https://github.com/dompdf/dompdf) ^3.1.
- Fonts: **static** TTFs (see [Fonts](#fonts)). 12 open-source families (OFL) ship ready to use in `fonts/`.

## Quick start

**1. Browser:** copy `js/jh2pdf.js` to your site, then:

```js
import { extractLayout } from './jh2pdf.js';

const layout = await extractLayout(
  ['<h1>Hello</h1><p>First page</p>', '<p>Second page</p>'],      // one HTML fragment per page
  { width: 1280, height: 720 }                                      // page size in CSS px
);
const res = await fetch('/pdf.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(layout) });
const pdf = await res.blob();                                       // application/pdf
```

Your page's CSS already applies: the module renders each page in an off-screen container inside your own document.

**2. Server:** `composer require geisonq/jh2pdf` (installs Dompdf too), then:

```php
require 'vendor/autoload.php';       // without Composer: require 'jh2pdf/autoload.php' + Dompdf

$layout = json_decode(file_get_contents('php://input'), true);

$options = new Jh2Pdf\Options('/writable/path');         // temp directory (font cache + temporary images)
$pdf = (new Jh2Pdf\Renderer($options))->render($layout, 'Document title');

header('Content-Type: application/pdf');
echo $pdf;
```

A complete, runnable example lives in [`examples/minimal/`](examples/minimal/).

**Examples gallery** (7 A4 pages with tables, gradients, shadows, filtered images, lists and an invoice):

```bash
composer install
php -S localhost:8080
```

Open `http://localhost:8080/demo/` and click **Gerar PDF** (Generate PDF) to compare the preview with the PDF. On XAMPP, just open `http://localhost/jh2pdf/demo/`.

## API

### JavaScript: `extractLayout(pages, options)`

| Option | Type | Description |
| --- | --- | --- |
| `width`, `height` | number (px) | **required.** Page size in CSS px (1280×720 = 16:9; becomes 960×540 pt in the PDF) |
| `wrap(page, i)` | function → string | The page's **complete** HTML (a single root element; its `background-color` becomes the page background). Default: `<div>` + the page's HTML + `</div>` |
| `hostCss` | string | Extra CSS inside the measuring container (e.g. a `box-sizing`/`margin` reset your CSS expects). Scope it with `[data-jh2pdf-host]` |
| `skip(el)` | function → bool | `true` to **not** draw an element (and its contents) |
| `acceptImage(src)` | function → bool | Filters the image `src` values that are sent (the server makes the real decision) |
| `marks` | bool | Draws hand-drawn marks (`.mk` + `.mk-circle`…; see [Hand-drawn marks](#hand-drawn-marks)) |
| `sanitize(html)` | function → string | Sanitizer for each page's HTML (e.g. DOMPurify); the module still strips scripts, `on*=` etc. on top. See [Security](#security) |

Returns `{ w, h, pages: [{ bg, ops }] }`. Waits for fonts (`document.fonts.ready`) and images (up to 4 s each) before measuring.

### PHP

| Class | Role |
| --- | --- |
| `Jh2Pdf\Options` | configuration: `tempDir` (**required**, writable), `fontDir`, `addFont()`, `addFontDir()`, `imageResolver`, `marksCss`, `creator`, `fallbackFont`, `strictFonts`, `onMissingFont`, limits (`maxPages`, `maxOpsPerPage`, `maxOpsTotal`, `maxImages`), `memoryLimit`, `pxToPt` |
| `Jh2Pdf\Renderer` | `render(array $layout, string $title = ''): string` returns the PDF bytes; throws `Jh2Pdf\Exception`; `missingFonts()` lists the fonts of the last render that the server did not have |
| `Jh2Pdf\ImageResolver` | interface `load(string $src): ?string`: **you decide** where images come from |
| `Jh2Pdf\DataUriImageResolver` | default resolver: only `data:image/...;base64,...` |
| `Jh2Pdf\Layout` | `clean()` validates the JSON; `num()`/`color()` are safe converters |
| `Jh2Pdf\ImageFilter` | CSS image filters with GD (usable on its own) |
| `Jh2Pdf\FontFaces` | `css($options, $url)` generates the browser's `@font-face` CSS from the same fonts the PDF uses |
| `Jh2Pdf\FontIndex`, `Fonts`, `Images`, `Marks`, `Svg` | internal: the font list, font choice (CSS rules), images/shadows, marks, SVG paths |

### Images

Each image's `src` comes from the browser and is **not trusted**. By default only `data:` is accepted (no disk, no network). To serve images from
your system, implement a resolver and check what the user is allowed to see:

```php
final class MyResolver implements Jh2Pdf\ImageResolver
{
    public function load(string $src): ?string
    {
        if (!preg_match('~^/uploads/([a-f0-9]{16}\.(png|jpg))$~', $src, $m)) return null;   // only your pattern
        $f = '/var/app/uploads/' . $m[1];
        return is_file($f) ? file_get_contents($f) : null;
    }
}
$options->imageResolver = new MyResolver();
```

In the browser, `acceptImage` avoids sending what the server would refuse.

## Fonts

For the PDF to look exactly like the screen, **the browser and the server must use the same font files**. Dompdf cannot read woff2 or variable fonts, so the server uses **static TTFs**, one per weight. There are three ways to give it fonts:

- the `fonts/` folder (`Options::$fontDir`), with files named `<family>-<weight>[-italic].ttf`: `dm-sans-600.ttf`, `dm-sans-400-italic.ttf` (family in lowercase with hyphens, weight 100–900);
- `$options->addFontDir($dir)`: another folder, same naming (use it for your own fonts);
- `$options->addFont($family, $weight, $file, $italic)`: one file with any name; it wins over the folders.

```php
$options = new Jh2Pdf\Options('/writable/path');
$options->addFont('Roboto', 400, '/fonts/Roboto-Regular.ttf')
        ->addFont('Roboto', 400, '/fonts/Roboto-Italic.ttf', italic: true)
        ->addFont('Roboto', 700, '/fonts/Roboto-Bold.ttf')
        ->addFontDir('/more/fonts');
```

**How a font is chosen** (the same rules as CSS):

- **Family:** the first family of the `font-family` list that the server has (`'Roboto', Arial, sans-serif` uses Roboto, or Arial if you have it; generic names such as `sans-serif` are not fonts). The browser sends up to 5 fallbacks after the first.
- **Style:** italic if the family has an italic file; otherwise the regular style slanted 12°, as the browser does.
- **Weight:** from 100 to 900, the closest file you have: asking for 800 with only 400 and 700 uses 700; 300 uses 400.

**Make the browser use the same files.** Generate the `@font-face` CSS from the very same fonts, so there are never two lists to keep in sync:

```php
// once, when you deploy (reading the names inside the TTFs is slow to do on every request)
file_put_contents('public/fonts.css', Jh2Pdf\FontFaces::css($options, '/fonts/'));
```

or from the command line: `php tools/fonts-css.php --dir fonts --url /fonts/ --out public/fonts.css` (installed with Composer, the script is `vendor/geisonq/jh2pdf/tools/fonts-css.php`).

**Never fail silently.** A family the server does not have is drawn in `fallbackFont` (DejaVu Sans), and the PDF looks different from the screen. To catch it:

```php
$options->strictFonts = true;   // throws Jh2Pdf\Exception: "Missing fonts: Roboto 400, Roboto 700 italic."
$options->onMissingFont = fn(string $family, int $weight, bool $italic) => error_log("Missing font: $family $weight");

$pdf = $renderer->render($layout);
$renderer->missingFonts();      // [['family' => 'Roboto', 'weight' => 400, 'italic' => false], …]
```

The check runs **before** anything is drawn. Use `strictFonts` in development and tests; in production, log with `onMissingFont` or `missingFonts()`.

**Adding a font from a variable font** (e.g. Google Fonts): `pip install fonttools brotli` and `python tools/generate-fonts.py --fonts fonts.json --src font-source-folder --out fonts`
(`--weights 300,400,700` chooses the weights; see the script header for the `fonts.json` format). It writes the right family, style and weight inside each TTF.
To fix the names of static TTFs you already have: `python tools/generate-fonts.py --fix-names folder`. The server does not need Python.
Check that the font's license allows embedding it in PDFs.

Bundled (SIL Open Font License 1.1): Barlow Condensed, DM Sans, Fira Code, Inter, JetBrains Mono, Montserrat, Nunito, Open Sans, and four renamed serif families:
**Jh Serif** (from Lora), **Jh Serif Text** (from Merriweather), **Jh Display** (from Playfair Display) and **Jh Serif Book** (from Source Serif 4).

## Hand-drawn marks

An **optional** extension (off by default; not standard HTML/CSS) for highlighting text with a hand-drawn stroke (circle, underline, highlight, box, strike-through, cross):

```html
<span class="mk mk-circle" style="--mk-color:#e5484d">important</span>
```

With `marks: true`, the extractor sends the position of the marked text and its color (`--mk-color`); PHP draws the shape's SVG (`resources/marks.css`, or your own CSS
via `Options::$marksCss`). Without `--mk-color`, the text color is used. Each shape's 2nd variant applies to even elements (`:nth-of-type(even)`);
a mark with `display: inline` that wraps gets one drawing per line. Without `marks: true`, `.mk` classes are treated like any other element.

## Security

There are two trust boundaries. On each one, whatever comes from the other side is treated as **hostile**.

### Server: the JSON from the browser

Anyone who can call your endpoint can send hand-crafted JSON. By design, the server **never interprets HTML, CSS or JavaScript**:
it only draws numbers, colors and text. That removes the most dangerous class of attacks on HTML-to-PDF generators (Dompdf's/mPDF's HTML/CSS parser,
`@font-face`, `<link>`, `file://`, SSRF): here Dompdf is used only as a PDF canvas.

| Attack | Defense |
| --- | --- |
| Injecting HTML/JS/CSS into text | text is just text: it goes into the PDF as glyphs (hex-encoded) and is never interpreted |
| Injecting PDF operators/objects (`) Tj (…`, `/OpenAction`, `/JS`, `/URI`, `/Launch`) | hex-encoded text; escaped title with no control characters; no action/annotation objects are created. A test searches the PDF and its decompressed streams for these names |
| Font name with `../`, `)`, null byte | reduced to `[A-Za-z0-9 -]` (60 characters) and mapped only to files in `fonts/` |
| Reading local files or making requests (`file://`, `http://`, `../`) | images only through the `ImageResolver`; the default accepts **only** `data:image/(png\|jpeg\|gif\|webp)`. Dompdf's `isRemoteEnabled`, PHP and JS are disabled |
| Image bomb (a 17 KB PNG declaring 144 million pixels) | the header is read **before** decoding: allowed type, side ≤ 20,000 px, ≤ 25 megapixels; working width ≤ 4000 px |
| Exhausting CPU/memory with millions of operations | `maxPages` (1000), `maxOpsPerPage` (60,000), `maxOpsTotal` (400,000), `maxImages` (200), nested clips ≤ 32, capped shadows/blurs, `set_time_limit(180)` |
| Absurd numbers/types (`1e999`, `NaN`, an array instead of a number, color `url(...)`) | everything goes through `Layout::num()` (fixed range) and `Layout::color()` (only `#rgb`/`#rrggbb`) |
| Leaking server paths in error messages | `Renderer::render` only throws `Jh2Pdf\Exception` with a generic message; details are in the previous exception (`getPrevious()`, for your logs) |
| Leftover temporary files | random names only inside `tempDir`, deleted at the end of each PDF and (if the process died) swept after 1 hour |

**What the library does not do (your responsibility):** authentication, CSRF, rate limiting and a **request body size limit** (rendering PDFs is expensive; `examples/` shows
a 5 MB limit), and the `ImageResolver`: if it returns files, check that **this user** may see **this** file (never concatenate `src` into a path).
Keep `dompdf/dompdf` and PHP/GD up to date: image decoding (GD) is where third-party bugs show up most.

### Browser: the input HTML

The extractor puts each page's HTML into the DOM of **the user who is exporting**, so the browser can measure it. If that HTML comes from someone else, it is hostile input
against **the exporter's browser** (classic XSS: `<img onerror=…>`). Defenses:

- the HTML is first placed in a `<template>` (inert: nothing runs, nothing loads), where `script`, `iframe`, `object`, `embed`, `link`, `meta`, `base`,
  `on*` attributes, `srcdoc`, non-image `javascript:`/`vbscript:`/`data:` URLs and styles with `expression()` are removed; only then does the content go into the page.
  Tested: `<img onerror>`, `<script>`, `<a href="javascript:">`, `<svg onload>`, `<iframe srcdoc>`, `onclick`, `data:text/html`: nothing runs;
- it is a **fallback filter**. If the content comes from third parties, sanitize it with a real sanitizer and pass it in `sanitize`
  (`extractLayout(pages, { sanitize: (h) => DOMPurify.sanitize(h) })`); the library's filter still runs on top;
- `<style>` and `url()` in CSS remain allowed (CSS does not run code, but it can **request** a URL, i.e. track). If that matters, filter it in `sanitize`.

## What is not drawn (yet)

It handles documents, reports, tables and slides well (see the [gallery](https://geisonq.github.io/jh2pdf/demo/)). Today these do **not** come out identical in the PDF:

- blurred `box-shadow` (unblurred ones, inset or outer, are drawn), `text-shadow`, `background-image: url()`, `transform`, `clip-path`, inline SVG/`<canvas>`;
- `linear-gradient` comes out vertical or horizontal (diagonals snap to the nearest direction); `radial-gradient`, repeating or layered gradients don't;
- `dashed`/`dotted`/`double` borders render as solid; underline and line-through are drawn word by word (they don't run under spaces);
- font ligatures (e.g. `=>` in JetBrains Mono becomes two characters);
- pseudo-elements with text or counters, `::first-letter`, `<video>`, `<iframe>`. Pseudo-elements that are just an **empty box** (`content: ''` with a background, border, rounded corners or an unblurred `box-shadow`) **are drawn** when `position: absolute` or `display: block`;
- fractional weights of variable fonts (450 is rounded to 500), and fonts without a static TTF on the server (they fall back to DejaVu; see [Fonts](#fonts));
- automatic pagination: each item in `pages` is one page; anything taller is cut off.

These make good contributions: each one becomes a new operation in the JSON (see [Contributing](#contributing)). The full table is in [docs › Supported CSS](https://geisonq.github.io/jh2pdf/docs/#css).

## Structure

```
jh2pdf/
├── LICENSE               MIT
├── assets/               logo, icon and favicon (logo-original.png is the source)
├── README.md             this file (+ README.pt-BR.md and README.es.md)
├── composer.json         Composer package (PSR-4 autoload: Jh2Pdf\ → src/)
├── autoload.php          autoloader for projects without Composer
├── src/                  PHP: Renderer, Layout, Options, ImageResolver, Images, ImageFilter, Fonts, Marks, Svg
├── js/jh2pdf.js          layout extractor (ES module, no dependencies)
├── fonts/                static TTFs (OFL)
├── resources/marks.css   SVG drawings of the hand-drawn marks
├── docs/                 full documentation: index.html (en), pt-BR.html, es.html
├── docs/format.md        the operations format (the "contract" between JS and PHP)
├── demo/                 examples gallery (open demo/index.html through a PHP server)
├── examples/minimal/     minimal example (HTML + PHP endpoint)
├── tools/fonts-css.php   generates the browser's @font-face CSS from the same fonts
├── tools/generate-fonts.py   generates the TTFs from variable fonts
└── tests/run.php         tests (php tests/run.php or composer test)
```

## Contributing

- **Tests:** `php tests/run.php` (needs Dompdf: `composer install` here, or the `vendor/` of the project hosting the folder). There is no test framework.
- **Compare with the browser:** the quality bar is *matching the screen*. Produce a PDF from the browser (Print → Save as PDF) and from this library and
  compare them side by side, zoomed in (e.g. PyMuPDF `page.get_pixmap(dpi=200)`).
- **New operation** (e.g. radial gradients): (1) the extractor in `js/jh2pdf.js` emits `{t:'G',…}`; (2) `Layout::OPS` gets the letter;
  (3) `Renderer::render` gets the `case`; (4) document it in `docs/format.md`; (5) test it in `tests/run.php`. Validate **every** new field (it is hostile input).
- **Style:** PHP 8.1, `declare(strict_types=1)`, no dependencies besides Dompdf; dependency-free JS, top-level names prefixed with `pl`.
- When in doubt, open an *issue* describing the HTML/CSS that comes out differently and attach the browser's PDF.

## License and credits

- **Code:** [MIT license](LICENSE), © 2026 Geison Quevedo. Use, modify and sell it freely, including in closed-source projects, as long as you keep the copyright notice and the license text.
- **Dompdf** (LGPL-2.1) and its dependencies (php-font-lib LGPL-2.1+, php-svg-lib LGPL-3.0+, masterminds/html5 MIT, php-css-parser MIT) are installed by Composer and used as an unmodified library. Details in [docs › Dependencies](https://geisonq.github.io/jh2pdf/docs/#dependencies).
- **Fonts** in `fonts/`: SIL Open Font License 1.1 (© their respective authors; static instances generated from the original variable fonts).
  Notices and license text in `fonts/OFL.txt`. Lora, Merriweather, Playfair Display and Source Serif 4 have a Reserved Font Name (RFN), so their static
  instances ship renamed as Jh Serif, Jh Serif Text, Jh Display and Jh Serif Book. If you generate new instances of RFN fonts, rename them too.
