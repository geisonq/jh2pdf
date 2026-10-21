<?php
declare(strict_types=1);

namespace Jh2Pdf;

/**
 * Lista das fontes que o servidor tem: as registradas com {@see Options::addFont()} e os TTF das pastas
 * ({@see Options::$fontDir} e {@see Options::addFontDir()}) nomeados `<familia-em-minusculas>-<peso>[-italic].ttf`
 * (ex.: `dm-sans-600.ttf`, `lora-400-italic.ttf`). Pesos de 100 a 900.
 *
 * É a fonte de verdade para o PDF ({@see Fonts}) e para o CSS do navegador ({@see FontFaces}): os dois usam os mesmos arquivos.
 * Em caso de empate vale a ordem: addFont, depois fontDir, depois cada addFontDir.
 */
final class FontIndex
{
    /** @var array<string,array<int,array<int,string>>>  slug da família => peso => 0 (normal) | 1 (itálico) => arquivo */
    private array $faces = [];
    /** @var array<string,string>  slug => nome de exibição, só das famílias registradas com addFont */
    private array $names = [];

    public function __construct(Options $o)
    {
        foreach ($o->fontFiles() as $f) {
            $slug = self::slug($f['family']);
            $this->faces[$slug][$f['weight']][$f['italic'] ? 1 : 0] ??= str_replace('\\', '/', $f['file']);
            $this->names[$slug] ??= $f['family'];
        }
        foreach ($o->allFontDirs() as $dir) {
            foreach (glob(rtrim(str_replace('\\', '/', $dir), '/') . '/*.[tT][tT][fF]') ?: [] as $file) {
                if (!preg_match('~^([a-z0-9]+(?:-[a-z0-9]+)*)-([1-9]00)(-italic)?\.ttf$~i', basename($file), $m)) continue;
                $this->faces[strtolower($m[1])][(int)$m[2]][empty($m[3]) ? 0 : 1] ??= $file;
            }
        }
    }

    /** "DM Sans" → "dm-sans": só letras e números em minúsculas, separados por hífen. */
    public static function slug(string $family): string
    {
        return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($family)), '-');
    }

    public function has(string $slug): bool
    {
        return $slug !== '' && isset($this->faces[$slug]);
    }

    /** O servidor tem alguma fonte para essa pilha de famílias (a primeira e as de reserva)? */
    public function hasAny(string $family, array $fallbacks = []): bool
    {
        foreach (array_merge([$family], $fallbacks) as $f) {
            if ($this->has(self::slug($f))) return true;
        }
        return false;
    }

    /** @return array<int,array<int,string>>  peso => 0|1 => arquivo */
    public function faces(string $slug): array
    {
        return $this->faces[$slug] ?? [];
    }

    /** @return string[]  slugs de todas as famílias, em ordem alfabética */
    public function families(): array
    {
        $s = array_keys($this->faces);
        sort($s);
        return $s;
    }

    /** Nome de exibição dado em addFont(), ou null (famílias que vieram só do nome do arquivo). */
    public function name(string $slug): ?string
    {
        return $this->names[$slug] ?? null;
    }
}
