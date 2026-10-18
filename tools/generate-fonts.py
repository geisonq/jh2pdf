"""
Gera os TTF estáticos que a biblioteca usa (`fonts/<familia>-<peso>[-italic].ttf`) a partir de fontes variáveis (woff2/ttf).
O Dompdf não lê woff2 nem fontes variáveis, então cada fonte vira uma instância fixa por peso (400, 500, 600 e 700), mais o itálico onde há arquivo.
É um passo de desenvolvimento (precisa de Python + `pip install fonttools brotli`); o servidor NÃO precisa de Python: só usa os .ttf prontos.

    python generate-fonts.py --fonts fonts.json --src pasta/das/fontes --out ../fonts

`fonts.json` descreve as famílias:
    { "DM Sans": { "files": [ {"file": "dm-sans.woff2", "style": "normal"}, {"file": "dm-sans-italic.woff2", "style": "italic"} ] } }
Fontes que não são variáveis (sem eixo `wght`) são copiadas como estão para cada peso; se o item traz `"weight": "400"` (um só número),
a fonte é estática e vale apenas para esse peso (ex.: Barlow Condensed, um arquivo por peso).
"""
import argparse, json, os
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer

WEIGHTS = (400, 500, 600, 700)   # os pesos que o extrator envia (400–700); o PDF precisa de um arquivo por peso


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--fonts", required=True, help="JSON com as famílias e arquivos")
    ap.add_argument("--src", required=True, help="pasta dos arquivos de fonte (woff2/ttf)")
    ap.add_argument("--out", required=True, help="pasta de saída dos TTF")
    a = ap.parse_args()

    fonts = json.load(open(a.fonts, encoding="utf-8"))
    os.makedirs(a.out, exist_ok=True)
    slug = lambda n: "-".join("".join(c if c.isalnum() else " " for c in n.lower()).split())
    total = 0
    for name, f in fonts.items():
        for spec in f["files"]:
            src = os.path.join(a.src, spec["file"])
            only = spec.get("weight", "").strip()   # peso único ("400"): fonte estática, vale só para esse peso
            for weight in ([int(only)] if only.isdigit() else WEIGHTS):
                font = TTFont(src)
                if "fvar" in font:
                    lo = [x.minValue for x in font["fvar"].axes if x.axisTag == "wght"][0]
                    hi = [x.maxValue for x in font["fvar"].axes if x.axisTag == "wght"][0]
                    font = instancer.instantiateVariableFont(font, {"wght": max(lo, min(hi, weight))}, inplace=False)
                font.flavor = None
                dest = os.path.join(a.out, "%s-%d%s.ttf" % (slug(name), weight, "-italic" if spec["style"] == "italic" else ""))
                font.save(dest)
                total += os.path.getsize(dest)
    print("fontes geradas em %s: %d arquivos, %.1f MB" % (a.out, len(os.listdir(a.out)), total / 1048576))


if __name__ == "__main__":
    main()
