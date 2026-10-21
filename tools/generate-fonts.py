"""
Gera os TTF estáticos que a biblioteca usa (`fonts/<familia>-<peso>[-italic].ttf`) a partir de fontes variáveis (woff2/ttf).
O Dompdf não lê woff2 nem fontes variáveis, então cada fonte vira uma instância fixa por peso (padrão: 400, 500, 600 e 700), mais o itálico onde há arquivo.
É um passo de desenvolvimento (precisa de Python + `pip install fonttools brotli`); o servidor NÃO precisa de Python: só usa os .ttf prontos.

    python generate-fonts.py --fonts fonts.json --src pasta/das/fontes --out ../fonts [--weights 300,400,700]

`fonts.json` descreve as famílias:
    { "DM Sans": { "files": [ {"file": "dm-sans.woff2", "style": "normal"}, {"file": "dm-sans-italic.woff2", "style": "italic"} ] } }
Fontes que não são variáveis (sem eixo `wght`) são copiadas como estão para cada peso; se o item traz `"weight": "400"` (um só número),
a fonte é estática e vale apenas para esse peso (ex.: Barlow Condensed, um arquivo por peso).

Cada arquivo sai com o nome da família, o estilo e o peso CERTOS dentro do TTF (nomes 1, 2, 4, 6, 16 e 17, `usWeightClass`, itálico/negrito):
é o que aparece nas propriedades do PDF e na cópia de texto. Para corrigir TTFs estáticos que você já tem (nomeados `<familia>-<peso>[-italic].ttf`):

    python generate-fonts.py --fix-names pasta/dos/ttf
"""
import argparse, glob, json, os, re
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer

WEIGHTS = (400, 500, 600, 700)   # padrão; o extrator envia de 100 a 900 e o servidor usa o arquivo mais próximo (como o CSS)
STYLE = {100: 'Thin', 200: 'ExtraLight', 300: 'Light', 400: 'Regular', 500: 'Medium', 600: 'SemiBold', 700: 'Bold', 800: 'ExtraBold', 900: 'Black'}
slug = lambda n: "-".join("".join(c if c.isalnum() else " " for c in n.lower()).split())


def set_names(font, family, weight, italic):
    """Grava família, estilo e peso certos no TTF (tabelas name, OS/2 e head)."""
    wname = STYLE.get(weight, str(weight))
    style = ('Italic' if weight == 400 else wname + ' Italic') if italic else wname          # nome tipográfico (17)
    ribbi = weight in (400, 700)                                                              # regular/itálico/negrito/negrito itálico: família "limpa"
    legacy_family = family if ribbi else '%s %s' % (family, wname)                            # id 1: nos demais pesos o peso entra no nome da família
    legacy_style = ('Bold Italic' if italic else 'Bold') if weight == 700 else ('Italic' if italic else 'Regular')   # id 2
    full = '%s %s' % (family, style)
    ps = '%s-%s' % (family.replace(' ', ''), style.replace(' ', ''))
    name = font['name']
    version = (name.getDebugName(5) or 'Version 1.000').split(';')[0]
    vendor = (font['OS/2'].achVendID or 'NONE').strip() or 'NONE'
    for nid in (1, 2, 3, 4, 6, 16, 17, 21, 22, 25):
        name.removeNames(nameID=nid)
    for plat in ((3, 1, 0x409), (1, 0, 0)):                                                   # Windows e Mac
        for nid, value in ((1, legacy_family), (2, legacy_style), (3, '%s;%s;%s' % (version.replace('Version ', ''), vendor, ps)),
                           (4, full), (6, ps), (16, family), (17, style)):
            name.setName(value, nid, *plat)
    os2, head = font['OS/2'], font['head']
    os2.usWeightClass = weight
    sel = os2.fsSelection & ~((1 << 0) | (1 << 5) | (1 << 6))                                  # limpa itálico, negrito e regular
    sel |= (1 << 0) if italic else 0
    sel |= (1 << 5) if weight == 700 else 0
    sel |= (1 << 6) if not italic and weight != 700 else 0
    os2.fsSelection = sel
    head.macStyle = (head.macStyle & ~0b11) | (1 if weight == 700 else 0) | (2 if italic else 0)


def family_from_names(font, file_slug):
    """O nome de exibição da família dentro do TTF que gera o mesmo slug do arquivo (tira palavras do fim: "DM Sans 9pt" → "DM Sans")."""
    name = font['name']
    for nid in (16, 1):
        n = (name.getDebugName(nid) or '').strip()
        for _ in range(4):
            if n and slug(n) == file_slug:
                return n
            n = n.rsplit(' ', 1)[0] if ' ' in n else ''
    return ' '.join(w.capitalize() for w in file_slug.split('-'))


def fix_names(folder):
    n = 0
    for path in sorted(glob.glob(os.path.join(folder, '*.ttf'))):
        m = re.match(r'^(.+)-([1-9]00)(-italic)?\.ttf$', os.path.basename(path), re.I)
        if not m:
            print('ignorado (nome fora do padrão <familia>-<peso>[-italic].ttf):', os.path.basename(path))
            continue
        font = TTFont(path)
        set_names(font, family_from_names(font, m.group(1).lower()), int(m.group(2)), bool(m.group(3)))
        font.save(path)
        n += 1
    print('nomes corrigidos em %d arquivos de %s' % (n, folder))


def generate(a):
    weights = tuple(int(x) for x in a.weights.split(',')) if a.weights else WEIGHTS
    fonts = json.load(open(a.fonts, encoding="utf-8"))
    os.makedirs(a.out, exist_ok=True)
    total = 0
    for name, f in fonts.items():
        for spec in f["files"]:
            src = os.path.join(a.src, spec["file"])
            only = spec.get("weight", "").strip()   # peso único ("400"): fonte estática, vale só para esse peso
            for weight in ([int(only)] if only.isdigit() else weights):
                font = TTFont(src)
                if "fvar" in font:
                    lo = [x.minValue for x in font["fvar"].axes if x.axisTag == "wght"][0]
                    hi = [x.maxValue for x in font["fvar"].axes if x.axisTag == "wght"][0]
                    font = instancer.instantiateVariableFont(font, {"wght": max(lo, min(hi, weight))}, inplace=False)
                font.flavor = None
                italic = spec["style"] == "italic"
                set_names(font, name, weight, italic)
                dest = os.path.join(a.out, "%s-%d%s.ttf" % (slug(name), weight, "-italic" if italic else ""))
                font.save(dest)
                total += os.path.getsize(dest)
    print("fontes geradas em %s: %d arquivos, %.1f MB" % (a.out, len(os.listdir(a.out)), total / 1048576))


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--fonts", help="JSON com as famílias e arquivos")
    ap.add_argument("--src", help="pasta dos arquivos de fonte (woff2/ttf)")
    ap.add_argument("--out", help="pasta de saída dos TTF")
    ap.add_argument("--weights", help="pesos a gerar, ex.: 300,400,700 (padrão: 400,500,600,700)")
    ap.add_argument("--fix-names", metavar="PASTA", help="só corrige os nomes dentro dos TTF estáticos da pasta")
    a = ap.parse_args()
    if a.fix_names:
        fix_names(a.fix_names)
    elif a.fonts and a.src and a.out:
        generate(a)
    else:
        ap.error("informe --fonts, --src e --out (ou --fix-names PASTA)")


if __name__ == "__main__":
    main()
