<?php
declare(strict_types=1);

namespace Jh2Pdf;

/** Configuração do {@see Renderer}. Só `tempDir` é obrigatório. */
final class Options
{
    /** Pasta gravável: cache de fontes do Dompdf e imagens temporárias (apagadas ao fim de cada PDF). */
    public string $tempDir;

    /** Pasta com os TTF estáticos (`<familia>-<peso>[-italic].ttf`). Padrão: a pasta `fonts/` da biblioteca. */
    public string $fontDir;

    /** De onde vêm as imagens. Padrão: só `data:image/...` ({@see DataUriImageResolver}). */
    public ?ImageResolver $imageResolver = null;

    /** Arquivo CSS com os desenhos das marcas à mão (`--mk-shape`). Padrão: `resources/marks.css` da biblioteca. */
    public ?string $marksCss = null;

    /** Metadado "Creator" do PDF. */
    public string $creator = 'Jh2Pdf';

    /** Fonte usada quando a família pedida não existe (acompanha o Dompdf). Veja também `strictFonts`. */
    public string $fallbackFont = 'DejaVu Sans';

    /**
     * true = em vez de trocar em silêncio por `fallbackFont`, `Renderer::render()` lança `Jh2Pdf\Exception` ("Missing fonts: Roboto 400, …")
     * ANTES de desenhar qualquer coisa. Recomendado em desenvolvimento: uma fonte esquecida vira erro visível, não um PDF diferente da tela.
     */
    public bool $strictFonts = false;

    /**
     * Chamado uma vez por fonte que faltou (família, peso, itálico), antes de desenhar. Serve para registrar no log.
     * @var (callable(string $family, int $weight, bool $italic): void)|null
     */
    public $onMissingFont = null;

    /** @var array<int,array{family:string,weight:int,italic:bool,file:string}> */
    private array $fontFiles = [];
    /** @var string[] */
    private array $fontDirs = [];

    /** Limites: o JSON vem do navegador e não pode pedir trabalho sem fim. */
    public int $maxPages = 1000;
    public int $maxOpsPerPage = 60000;
    public int $maxOpsTotal = 400000;

    /** No máximo quantas imagens (operações "I") são processadas por PDF; as demais são ignoradas (cada uma custa CPU e memória da GD). */
    public int $maxImages = 200;

    /** `memory_limit` mínimo durante a geração (a GD e o Dompdf precisam). Só SOBE o limite atual, nunca baixa; null = não mexer. */
    public ?string $memoryLimit = '512M';

    /** Tamanho do texto/imagens em pt por px CSS (96 dpi → 0,75). */
    public float $pxToPt = 0.75;

    public function __construct(string $tempDir, ?string $fontDir = null)
    {
        $this->tempDir = $tempDir;
        $this->fontDir = $fontDir ?? dirname(__DIR__) . '/fonts';
    }

    /**
     * Registra um TTF estático para uma família, peso e estilo, com o nome de arquivo que você quiser.
     * `$family` é o nome que o CSS usa (`font-family: 'Roboto'`); `$weight` vai de 100 a 900 (múltiplos de 100).
     * Vale mais que os arquivos das pastas. O que continuar faltando é tratado por `fallbackFont`, `strictFonts` e `onMissingFont`.
     */
    public function addFont(string $family, int $weight, string $file, bool $italic = false): static
    {
        $family = trim($family);
        if (FontIndex::slug($family) === '') throw new Exception('addFont: the family name needs letters or numbers.');
        if ($weight < 100 || $weight > 900 || $weight % 100 !== 0) throw new Exception('addFont: the weight must be 100, 200, … 900.');
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'ttf' || !is_file($file) || !is_readable($file)) {
            throw new Exception("addFont: '$family' $weight needs an existing, readable static .ttf file.");
        }
        $this->fontFiles[] = ['family' => $family, 'weight' => $weight, 'italic' => $italic, 'file' => $file];
        return $this;
    }

    /** Mais uma pasta de TTF no padrão `<familia>-<peso>[-italic].ttf` (além de `fontDir`). */
    public function addFontDir(string $dir): static
    {
        if (!is_dir($dir)) throw new Exception('addFontDir: the directory does not exist.');
        $this->fontDirs[] = $dir;
        return $this;
    }

    /** @return array<int,array{family:string,weight:int,italic:bool,file:string}>  o que foi registrado com addFont() */
    public function fontFiles(): array
    {
        return $this->fontFiles;
    }

    /** @return string[]  `fontDir` e as pastas de addFontDir(), nessa ordem */
    public function allFontDirs(): array
    {
        return array_merge([$this->fontDir], $this->fontDirs);
    }
}
