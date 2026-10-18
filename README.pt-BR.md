# Jh2Pdf

[English](README.md) · **Português** · [Español](README.es.md)

**HTML para PDF fiel ao navegador, sem navegador no servidor.** Funciona em hospedagem compartilhada (só PHP + GD).

📖 **[Documentação completa](docs/pt-BR.html)** · 🎨 **[Galeria de exemplos](demo/pt-BR.html)** (tabelas, degradês, sombras, imagens com filtros, listas, fatura) · 📄 [Licença MIT](LICENSE)

## Por que existe

Gerar PDF de HTML costuma cair em um destes becos:

| Opção | Problema |
| --- | --- |
| Chrome/Puppeteer no servidor | precisa de VPS/container; não roda em hospedagem compartilhada |
| wkhtmltopdf | binário no servidor, CSS antigo, projeto parado |
| Dompdf / mPDF / TCPDF lendo o HTML | entendem só CSS 2.1: sem flexbox, grid, variáveis CSS, `clamp()`, filtros… o PDF sai diferente da tela |
| API externa de PDF | custo, dependência e o conteúdo sai do seu servidor |

A ideia aqui: **o navegador de quem exporta já é o melhor motor de layout que existe e já está aberto**. Em vez de reimplementar CSS no PHP, o
navegador calcula onde cada palavra, caixa, borda e imagem fica; o PHP só desenha essas posições num PDF. Não há quebra de linha nem cálculo de
layout no servidor para errar.

## Como funciona

```
 NAVEGADOR (JS)                                         SERVIDOR (PHP)
 ───────────────                                        ──────────────
 seu HTML + seu CSS                                     Options  (tempDir, fontDir, imageResolver…)
   │ desenha fora da tela (fontes e imagens carregadas)    │
   ▼                                                       ▼
 extractLayout()  percorre o DOM:                       Layout::clean()   valida tudo (não confia no JSON)
   getComputedStyle + Range.getClientRects por palavra     │
   │                                                       ▼
   ▼                                                    Renderer::render()   Dompdf\Cpdf
 JSON de operações de desenho ── POST ───────────────►    R retângulo · B bordas · T texto · I imagem
 { w, h, pages:[{ bg, ops:[…] }] }                         M marca à mão · L linha · C/E recorte arredondado
                                                           │
                                                           ▼
                                                        bytes do PDF  (texto selecionável, fontes embutidas)
```

O que o navegador mede e o PHP só reproduz: posição e tamanho de **cada palavra** (largura exata, linha de base), fundos, bordas por lado,
cantos arredondados, `overflow: hidden` com cantos (recorte), imagens com `object-fit`, filtros (`grayscale`, `sepia`, `blur`, `saturate`,
`hue-rotate`…), `drop-shadow`, marcadores de lista, opacidade, `text-transform`, `letter-spacing`, sublinhado/riscado.

## Requisitos

- **Navegador:** qualquer um moderno (Chromium, Firefox, Safari) — o layout que ele calcula é o do PDF.
- **Servidor:** PHP ≥ 8.1, extensões `gd` e `mbstring`, e [`dompdf/dompdf`](https://github.com/dompdf/dompdf) ^3.1.
- Fontes: TTF **estáticos** (veja [Fontes](#fontes)). Vêm 12 famílias abertas (OFL) prontas em `fonts/`.

## Início rápido

**1. Navegador** — copie `js/jh2pdf.js` para o seu site e:

```js
import { extractLayout } from './jh2pdf.js';

const layout = await extractLayout(
  ['<h1>Olá</h1><p>Primeira página</p>', '<p>Segunda página</p>'],   // um fragmento de HTML por página
  { width: 1280, height: 720 }                                      // tamanho da página em px CSS
);
const res = await fetch('/pdf.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(layout) });
const pdf = await res.blob();                                       // application/pdf
```

O CSS da sua página já vale: o módulo desenha cada página num contêiner fora da tela, dentro do seu documento.

**2. Servidor** — `composer require dompdf/dompdf` e:

```php
require 'Jh2Pdf/autoload.php';       // ou: composer require jh2pdf/jh2pdf

$layout = json_decode(file_get_contents('php://input'), true);

$options = new Jh2Pdf\Options('/caminho/gravavel');      // pasta temporária (cache de fontes + imagens temporárias)
$pdf = (new Jh2Pdf\Renderer($options))->render($layout, 'Título do documento');

header('Content-Type: application/pdf');
echo $pdf;
```

Um exemplo completo e executável está em [`examples/minimal/`](examples/minimal/).

**Galeria de exemplos** (7 páginas A4 com tabelas, degradês, sombras, imagens com filtros, listas e uma fatura):

```bash
composer install
php -S localhost:8080
```

Abra `http://localhost:8080/demo/` e clique em **Gerar PDF** para comparar a prévia com o PDF. Num XAMPP, basta abrir `http://localhost/jh2pdf/demo/`.

## API

### JavaScript — `extractLayout(pages, options)`

| Opção | Tipo | Descrição |
| --- | --- | --- |
| `width`, `height` | número (px) | **obrigatórios.** Tamanho da página em px CSS (1280×720 = 16:9; no PDF vira 960×540 pt) |
| `wrap(page, i)` | função → string | HTML **completo** da página (um único elemento raiz; o `background-color` dele vira o fundo da página). Padrão: `<div>` + HTML da página + `</div>` |
| `hostCss` | string | CSS extra dentro do contêiner de medição (ex.: um reset `box-sizing`/`margin` que o seu CSS espere). Escope com `[data-jh2pdf-host]` |
| `skip(el)` | função → bool | `true` para **não** desenhar um elemento (e o que há dentro) |
| `acceptImage(src)` | função → bool | filtra os `src` de imagem enviados (o servidor é quem decide de verdade) |
| `marks` | bool | desenha as marcas à mão (`.mk` + `.mk-circle`…; veja [Marcas](#marcas-à-mão)) |
| `sanitize(html)` | função → string | sanitizador do HTML de cada página (ex.: DOMPurify); o módulo ainda remove scripts, `on*=` etc. por cima — veja [Segurança](#segurança) |

Devolve `{ w, h, pages: [{ bg, ops }] }`. Espera as fontes (`document.fonts.ready`) e as imagens (até 4 s cada) antes de medir.

### PHP

| Classe | Papel |
| --- | --- |
| `Jh2Pdf\Options` | configuração: `tempDir` (**obrigatório**, gravável), `fontDir`, `imageResolver`, `marksCss`, `creator`, `fallbackFont`, limites (`maxPages`, `maxOpsPerPage`, `maxOpsTotal`, `maxImages`), `memoryLimit`, `pxToPt` |
| `Jh2Pdf\Renderer` | `render(array $layout, string $title = ''): string` — devolve os bytes do PDF; lança `Jh2Pdf\Exception` |
| `Jh2Pdf\ImageResolver` | interface `load(string $src): ?string` — **você decide** de onde vêm as imagens |
| `Jh2Pdf\DataUriImageResolver` | resolvedor padrão: só `data:image/...;base64,...` |
| `Jh2Pdf\Layout` | `clean()` valida o JSON; `num()`/`color()` conversores seguros |
| `Jh2Pdf\ImageFilter` | filtros de imagem do CSS com a GD (usável sozinho) |
| `Jh2Pdf\Fonts`, `Images`, `Marks`, `Svg` | internos: fontes por peso, imagens/sombras, marcas, caminhos SVG |

### Imagens

O `src` de cada imagem vem do navegador e **não é confiável**. Por padrão só `data:` é aceito (nada de disco nem de rede). Para servir imagens
do seu sistema, implemente o resolvedor, validando o que o usuário pode ver:

```php
final class MeuResolver implements Jh2Pdf\ImageResolver
{
    public function load(string $src): ?string
    {
        if (!preg_match('~^/uploads/([a-f0-9]{16}\.(png|jpg))$~', $src, $m)) return null;   // só o seu padrão
        $f = '/var/app/uploads/' . $m[1];
        return is_file($f) ? file_get_contents($f) : null;
    }
}
$options->imageResolver = new MeuResolver();
```

No navegador, `acceptImage` evita mandar o que o servidor recusaria.

## Fontes

O Dompdf não lê woff2 nem fontes variáveis, e o navegador usa as variáveis. Por isso `fonts/` tem **TTF estáticos**, um por peso:

```
fonts/dm-sans-400.ttf  dm-sans-500.ttf  dm-sans-600.ttf  dm-sans-700.ttf  dm-sans-400-italic.ttf …
        └ família (minúsculas, hífens) └ peso 400/500/600/700            └ itálico, se a família tiver
```

- O navegador informa a **família e o peso exatos** de cada palavra; o PHP usa o arquivo daquele peso. Sem ele, cai na fonte de reserva (DejaVu).
- Família sem itálico (ex.: JetBrains Mono): o PDF inclina a normal em 12°, como o navegador faz.
- **Adicionar uma fonte:** `pip install fonttools brotli` e `python tools/generate-fonts.py --fonts fonts.json --src pasta-das-fontes --out fonts`
  (veja o cabeçalho do script para o formato do `fonts.json`). O servidor não precisa de Python.

Incluídas (SIL Open Font License 1.1): Barlow Condensed, DM Sans, Fira Code, Inter, JetBrains Mono, Montserrat, Nunito, Open Sans e quatro serifadas renomeadas:
**Jh Serif** (da Lora), **Jh Serif Text** (da Merriweather), **Jh Display** (da Playfair Display) e **Jh Serif Book** (da Source Serif 4).

## Marcas à mão

Extensão **opcional** (desligada por padrão; não é HTML/CSS padrão) para destacar trechos com um desenho feito à mão sobre o texto (círculo, sublinhado, destaque, caixa, riscado, X):

```html
<span class="mk mk-circle" style="--mk-color:#e5484d">importante</span>
```

Com `marks: true`, o extrator envia a posição do trecho e a cor (`--mk-color`); o PHP desenha o SVG da forma (`resources/marks.css`, ou o seu CSS
em `Options::$marksCss`). Sem `--mk-color`, usa a cor do texto. A 2ª variação de cada forma vale para os elementos pares (`:nth-of-type(even)`);
uma marca com `display: inline` que quebra de linha ganha um desenho por linha. Sem `marks: true`, as classes `.mk` são tratadas como qualquer outro elemento.

## Segurança

Há duas fronteiras. Em cada uma, o que vem do outro lado é tratado como **hostil**.

### Servidor: o JSON do navegador

Qualquer pessoa que consiga chamar o seu endpoint pode mandar um JSON feito à mão. Por desenho, o servidor **nunca interpreta HTML, CSS nem JavaScript**:
só desenha números, cores e texto. Isso tira de cena a classe de ataque mais perigosa dos geradores de PDF por HTML (o parser de HTML/CSS do Dompdf/mPDF,
`@font-face`, `<link>`, `file://`, SSRF) — aqui o Dompdf é usado só como canvas de PDF.

| Ataque | Defesa |
| --- | --- |
| Injetar HTML/JS/CSS no texto | texto é só texto: vai para o PDF como glifos (hexadecimal), nunca é interpretado |
| Injetar operadores/objetos do PDF (`) Tj (…`, `/OpenAction`, `/JS`, `/URI`, `/Launch`) | texto em hexadecimal; título escapado e sem caracteres de controle; nenhum objeto de ação/anotação é criado. Há teste que procura esses nomes no PDF e nos streams descomprimidos |
| Nome de fonte com `../`, `)`, byte nulo | reduzido a `[A-Za-z0-9 -]` (60 caracteres) e mapeado só para arquivos de `fonts/` |
| Ler arquivo local ou fazer requisição (`file://`, `http://`, `../`) | imagens só pelo `ImageResolver`; o padrão aceita **apenas** `data:image/(png\|jpeg\|gif\|webp)`. `isRemoteEnabled`, PHP e JS do Dompdf desligados |
| Imagem-bomba (PNG de 17 KB que declara 144 milhões de pixels) | o cabeçalho é lido **antes** de decodificar: tipo permitido, lado ≤ 20 000 px, ≤ 25 megapixels; largura de trabalho ≤ 4000 px |
| Esgotar CPU/memória com milhões de operações | `maxPages` (1000), `maxOpsPerPage` (60 000), `maxOpsTotal` (400 000), `maxImages` (200), recortes aninhados ≤ 32, sombras/desfoques com teto, `set_time_limit(180)` |
| Números/tipos absurdos (`1e999`, `NaN`, array no lugar de número, cor `url(...)`) | tudo passa por `Layout::num()` (intervalo fixo) e `Layout::color()` (só `#rgb`/`#rrggbb`) |
| Vazamento de caminhos do servidor em mensagens de erro | `Renderer::render` só lança `Jh2Pdf\Exception` com mensagem genérica; o detalhe fica na exceção anterior (`getPrevious()`, para o seu log) |
| Arquivos temporários esquecidos | nomes aleatórios só em `tempDir`, apagados ao fim de cada PDF e (se o processo morreu) varridos depois de 1 hora |

**O que a biblioteca não faz e é seu:** autenticação, CSRF, *rate limit* e **limite do tamanho do corpo** da requisição (gerar PDF é caro; o `examples/` mostra
um limite de 5 MB), e o `ImageResolver` — se ele devolver arquivos, valide que **aquele usuário** pode ver **aquele** arquivo (nunca concatene o `src` num caminho).
Mantenha `dompdf/dompdf` e o PHP/GD atualizados: a decodificação de imagens (GD) é o ponto onde bugs de terceiros mais aparecem.

### Navegador: o HTML de entrada

O extrator coloca o HTML de cada página no DOM de **quem está exportando** para o navegador medir. Se esse HTML vier de outra pessoa, ele é uma entrada hostil
contra o **navegador do exportador** (XSS clássico: `<img onerror=…>`). Defesas:

- o HTML é primeiro posto num `<template>` (inerte: nada executa, nada carrega), onde são removidos `script`, `iframe`, `object`, `embed`, `link`, `meta`, `base`,
  atributos `on*`, `srcdoc`, URLs `javascript:`/`vbscript:`/`data:` que não sejam imagem e estilos com `expression()`; só então o conteúdo vai para a página.
  Testado: `<img onerror>`, `<script>`, `<a href="javascript:">`, `<svg onload>`, `<iframe srcdoc>`, `onclick`, `data:text/html` — nada executa;
- é um **filtro de reserva**. Se o conteúdo é de terceiros, sanitize com um sanitizador de verdade e passe em `sanitize`
  (`extractLayout(pages, { sanitize: (h) => DOMPurify.sanitize(h) })`); o filtro da biblioteca ainda roda por cima;
- `<style>` e `url()` em CSS continuam permitidos (CSS não executa código, mas pode **pedir** uma URL, ou seja, rastrear). Se isso importa, filtre no `sanitize`.

## O que (ainda) não é desenhado

Cobre bem documentos, relatórios, tabelas e slides (veja a [galeria](demo/pt-BR.html)). Hoje **não** saem iguais no PDF:

- `box-shadow` desfocado (o sem desfoque, interno ou externo, é desenhado), `text-shadow`, `background-image: url()`, `transform`, `clip-path`, SVG/`<canvas>` inline;
- `linear-gradient` sai na vertical ou na horizontal (diagonais viram a direção mais próxima); `radial-gradient`, degradês repetidos ou em camadas, não;
- bordas `dashed`/`dotted`/`double` saem sólidas; sublinhado e riscado são desenhados palavra por palavra (não passam sob os espaços);
- ligaduras de fonte (ex.: `=>` na JetBrains Mono vira dois caracteres);
- pseudo-elementos com texto ou contador, `::first-letter`, `<video>`, `<iframe>`. Os pseudo-elementos que são só uma **caixa vazia** (`content: ''` com fundo, borda, cantos arredondados ou `box-shadow` sem desfoque) **são desenhados** quando `position: absolute` ou `display: block`;
- peso fora de 400–700 (é arredondado para o mais próximo);
- paginação automática: cada item de `pages` é uma página; o que passar da altura é cortado.

São boas contribuições — cada item vira uma operação nova no JSON (veja [Contribuindo](#contribuindo)). A tabela completa está em [docs › CSS suportado](docs/pt-BR.html#css).

## Estrutura

```
jh2pdf/
├── LICENSE               MIT
├── README.md             em inglês + README.pt-BR.md (este) e README.es.md
├── composer.json         pacote Composer (autoload PSR-4: Jh2Pdf\ → src/)
├── autoload.php          autoloader para quem não usa o Composer
├── src/                  PHP: Renderer, Layout, Options, ImageResolver, Images, ImageFilter, Fonts, Marks, Svg
├── js/jh2pdf.js          extrator de layout (módulo ES, sem dependências)
├── fonts/                TTF estáticos (OFL)
├── resources/marks.css   desenhos SVG das marcas à mão
├── docs/                 documentação completa: index.html (en), pt-BR.html, es.html
├── docs/format.md        formato das operações (o "contrato" entre JS e PHP)
├── demo/                 galeria de exemplos (abra demo/index.html por um servidor PHP)
├── examples/minimal/     exemplo mínimo (HTML + endpoint PHP)
├── tools/generate-fonts.py   gera os TTF a partir de fontes variáveis
└── tests/run.php         testes (php tests/run.php ou composer test)
```

## Contribuindo

- **Testes:** `php tests/run.php` (precisa do Dompdf: `composer install` aqui, ou o `vendor/` do projeto que hospeda a pasta). Não há framework de teste.
- **Comparar com o navegador:** o critério de qualidade é *ficar igual à tela*. Gere o PDF do navegador (Imprimir → PDF) e o desta biblioteca e
  compare lado a lado, ampliando (ex.: PyMuPDF `page.get_pixmap(dpi=200)`).
- **Nova operação** (ex.: gradiente): (1) o extrator em `js/jh2pdf.js` passa a emitir `{t:'G',…}`; (2) `Layout::OPS` ganha a letra;
  (3) `Renderer::render` ganha o `case`; (4) documente em `docs/format.md`; (5) teste em `tests/run.php`. Valide **todo** campo novo (é entrada hostil).
- **Estilo:** PHP 8.1, `declare(strict_types=1)`, sem dependências além do Dompdf; JS sem dependências, nomes de topo com prefixo `pl`.
- Em dúvida, abra uma *issue* descrevendo o HTML/CSS que sai diferente e anexe o PDF do navegador.

## Licença e créditos

- **Código:** [licença MIT](LICENSE) — © 2026 Geison Quevedo. Use, modifique e venda à vontade, inclusive em projetos fechados, mantendo o aviso de copyright e o texto da licença.
- **Dompdf** (LGPL-2.1) e suas dependências (php-font-lib LGPL-2.1+, php-svg-lib LGPL-3.0+, masterminds/html5 MIT, php-css-parser MIT) são instalados pelo Composer e usados como biblioteca, sem alteração. Detalhes em [docs › Dependências](docs/pt-BR.html#dependencies).
- **Fontes** em `fonts/`: SIL Open Font License 1.1 (© dos respectivos autores; instâncias estáticas geradas das fontes variáveis originais).
  Avisos e texto da licença em `fonts/OFL.txt`. Lora, Merriweather, Playfair Display e Source Serif 4 têm nome reservado (RFN), por isso as instâncias
  estáticas vêm renomeadas como Jh Serif, Jh Serif Text, Jh Display e Jh Serif Book. Se gerar instâncias novas de fontes com RFN, renomeie-as também.
