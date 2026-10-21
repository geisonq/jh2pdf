# Changelog

All notable changes to Jh2Pdf. Versions follow [Semantic Versioning](https://semver.org/).

## 1.1.0

Fonts: a PDF that matches the screen with **any** font, and no more silent fallbacks.

### Added
- `Options::addFont($family, $weight, $file, $italic = false)`: register a static TTF with any file name. Wins over the folders.
- `Options::addFontDir($dir)`: more folders of TTFs named `<family>-<weight>[-italic].ttf`.
- `Options::$strictFonts`: throw `Jh2Pdf\Exception` (`Missing fonts: Roboto 400.`) before drawing instead of falling back to `fallbackFont`.
- `Options::$onMissingFont` callback and `Renderer::missingFonts()`: find out which fonts the server did not have.
- Font choice follows CSS: weights from **100 to 900** (the closest file you have, by the CSS matching rules) and the whole `font-family` list (the browser now sends up to 5 non-generic fallbacks in a new `fb` field).
- `Jh2Pdf\FontFaces::css()` and `tools/fonts-css.php`: generate the browser's `@font-face` CSS from the very same fonts the PDF embeds.
- `Jh2Pdf\FontIndex`: the single list of fonts the server has (used by the PDF and by `FontFaces`).
- `tools/generate-fonts.py --weights` and `--fix-names`.
- Documentation in English, Portuguese and Spanish for all of the above.

### Changed
- Every static TTF now carries the right family, style and weight inside the file (PDF font properties and text copy show `Montserrat-Bold` instead of `Montserrat-Thin`). Glyphs and metrics are untouched.
- Each font file is registered in Dompdf under its own name (with the file's modification time), so replacing a font in place never reuses a stale cache. The first PDF after upgrading registers the fonts again (slower once).
- The extractor sends weights from 100 to 900 (was 400–700).

### Compatibility
- Fully backwards compatible: without the new options the behavior is the same as 1.0.0, except for the font choice improvements above.
- A 1.1 extractor talking to a 1.0 server: the new `fb` field is ignored, and weights outside 400–700 are clamped as in 1.0.

## 1.0.1

Website and packaging only; the library code is unchanged.

- Published as `geisonq/jh2pdf` on Packagist, with the website at https://geisonq.github.io/jh2pdf/.
- SEO: titles, descriptions, canonical URLs, hreflang, Open Graph/Twitter cards, structured data and a sitemap.
- Website, gallery and documentation in English, Portuguese and Spanish, with the logo.

## 1.0.0

First public release.
