# Formato do layout (contrato entre o JS e o PHP)

O extrator (`js/jh2pdf.js`) produz e o `Renderer` (PHP) consome este JSON. Todas as medidas são em **px CSS**, com origem no **canto superior
esquerdo** da página; cores como `"#rrggbb"` (ou `"#rgb"`); opacidades de 0 a 1. O PHP converte px → pt (`Options::$pxToPt`, 0,75).

```json
{
  "w": 1280, "h": 720,
  "pages": [
    { "bg": "#ffffff", "ops": [ { "t": "R", "x": 0, "y": 0, "w": 1280, "h": 720, "c": "#ffffff", "a": 1 }, … ] }
  ]
}
```

- `w`, `h`: tamanho da página (limitados a 200–4000 px).
- Cada página: `bg` (fundo) e `ops` (operações, desenhadas **em ordem** — a ordem é a ordem de pintura).
- Toda operação tem `t` (uma letra) e os campos abaixo. Campos ausentes ou inválidos viram o valor neutro (0, preto, opaco); operações com `t`
  desconhecido são descartadas.

## Operações

### `R` — retângulo cheio
`{"t":"R","x","y","w","h","c":"#rrggbb","a":1,"r":[tl,tr,br,bl]}`
`a` = opacidade; `r` = raios dos cantos (opcional). Usada para fundos, marcadores de lista, barras de destaque.

### `B` — bordas
`{"t":"B","x","y","w","h","s":[[largura,"#cor",a],…4],"r":[…]}`
`s` = os 4 lados na ordem **topo, direita, base, esquerda** (`[0,…]` = sem borda). Com `r` e os 4 lados iguais, é desenhada uma linha contínua
acompanhando os cantos arredondados; senão, uma faixa por lado.

### `T` — texto (uma palavra por operação)
`{"t":"T","x","y","w","h","s":"palavra","f":"DM Sans","g":600,"i":1,"z":23,"c":"#rrggbb","a":1,"l":0.5,"d":3}`

| Campo | Significado |
| --- | --- |
| `x,y,w,h` | caixa que o navegador deu à palavra (`Range.getClientRects`) |
| `s` | o texto (máx. 300 caracteres); já com `text-transform` aplicado |
| `f` | família (só `[A-Za-z0-9 -]`) → `fonts/<slug>-<peso>.ttf` |
| `g` | peso 400/500/600/700 (omitido = 400) |
| `i` | 1 = itálico (inclina 12° se a família não tem itálico) |
| `z` | tamanho da fonte em px |
| `l` | `letter-spacing` em px (informativo; a largura final é a medida pelo navegador) |
| `d` | bits: 1 sublinhado, 2 riscado |

O PHP posiciona a palavra na **linha de base** calculada a partir da caixa e das métricas da fonte, e ajusta o espaço entre letras para a palavra ter
**exatamente** a largura `w` medida pelo navegador. É por isso que a quebra de linha nunca difere da tela.

### `I` — imagem
`{"t":"I","x","y","w","h","u":"src","k":[cx,cy,cw,ch],"f":"filtro CSS","a":1,"r":[…]}`
`u` = o `src` (passa pelo `ImageResolver`); `k` = retângulo de recorte (a caixa do `<img>`, quando `object-fit` faz a imagem passar dela);
`r` = cantos arredondados do recorte; `f` = valor computado de `filter`. Filtros suportados: `grayscale sepia brightness contrast invert blur saturate hue-rotate opacity drop-shadow`.

### `M` — marca à mão
`{"t":"M","m":"circle","v":0,"x","y","w","h","c","z":20}`
`m` = `underline|circle|highlight|box|strike|cross`; `v` = variação (0/1); `x,y,w,h` = caixa do trecho marcado; `z` = tamanho da fonte (a marca escala com ele).

### `L` — linha
`{"t":"L","x","y","x2","y2","c","w":2,"a":1}`

### `C` / `E` — recorte arredondado
`{"t":"C","x","y","w","h","r":[…]}` começa um recorte (`overflow: hidden` com `border-radius`); tudo o que vier até o `{"t":"E"}` correspondente é
recortado. Podem ser aninhados. Recortes que sobrarem abertos no fim da página são fechados.

## Limites de segurança (Options)

`maxPages` (1000), `maxOpsPerPage` (60 000), `maxOpsTotal` (400 000), `maxImages` (200): o que passar é descartado. Strings de texto (300), nomes de fonte (60) e filtros (300) têm tamanho máximo; imagens: ≤ 25 megapixels e ≤ 20 000 px de lado; recortes aninhados ≤ 32. Veja README › Segurança.
