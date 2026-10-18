/**
 * Jh2Pdf — extrator de layout (lado do navegador).
 *
 * O navegador de quem exporta desenha cada página fora da tela, com o CSS que você já tem, e este módulo percorre o resultado e anota onde está
 * cada coisa (caixas, bordas, palavras, imagens, marcas à mão) numa lista de operações de desenho em JSON. O servidor (PHP, Jh2Pdf\Renderer)
 * só DESENHA essas operações em PDF: não interpreta HTML nem CSS, então não há como o layout sair diferente da tela.
 *
 *   import { extractLayout } from './jh2pdf.js';
 *   const layout = await extractLayout(['<h1>Olá</h1>', '<p>Segunda página</p>'], { width: 1280, height: 720 });
 *   // POST JSON.stringify(layout) para o servidor → PDF
 *
 * Medidas em px CSS, origem no canto superior esquerdo da página; formato das operações em docs/format.md.
 * Módulo ES sem dependências. Nomes de topo começam com `pl` para poder ser embutido por bundlers que juntam módulos num só escopo.
 */

const PL_SHAPES = /^mk-(underline|circle|highlight|box|strike|cross)$/;

/* ── segurança do HTML de entrada ──
 * O HTML de cada página entra no DOM do navegador de quem exporta (para o navegador medir). `<template>` é inerte: scripts não rodam, imagens não
 * carregam e `onerror=` não dispara enquanto o conteúdo está lá dentro; é nele que se remove o que executa código, ANTES de o conteúdo ir para a página.
 * Isto é um filtro de reserva: se o HTML vem de gente em quem você não confia, sanitize com um sanitizador de verdade (DOMPurify) e passe em `sanitize`. */
const PL_BAD_TAGS = 'script,iframe,frame,frameset,object,embed,applet,link,meta,base,noscript,template,portal,dialog';
const PL_URL_ATTRS = new Set(['href', 'src', 'srcset', 'xlink:href', 'action', 'formaction', 'poster', 'data', 'background', 'ping', 'cite', 'manifest']);
const PL_BAD_URL = /^(?:javascript|vbscript|livescript|mocha):|^data:(?!image\/(?:png|jpe?g|gif|webp)[;,])/;

function plCleanTree(root) {
  root.querySelectorAll(PL_BAD_TAGS).forEach((n) => n.remove());
  for (const el of root.querySelectorAll('*')) {
    for (const a of [...el.attributes]) {
      const name = a.name.toLowerCase();
      if (name.startsWith('on') || name === 'srcdoc') { el.removeAttribute(a.name); continue; }
      const v = a.value.replace(/[\u0000-\u0020\u00a0\u1680\u2000-\u200f\u2028\u2029\u202f\u205f\u3000\ufeff]+/g, '').toLowerCase();
      if (PL_URL_ATTRS.has(name) && PL_BAD_URL.test(v)) el.removeAttribute(a.name);
      else if (name === 'style' && /expression\(|javascript:|-moz-binding|behavior:/.test(v)) el.removeAttribute(a.name);
    }
  }
}

/** Põe o HTML no contêiner sem nunca deixar o navegador executar nada do que veio nele. */
function plInsert(host, html, sanitize) {
  const tpl = document.createElement('template');
  tpl.innerHTML = sanitize ? sanitize(html) : html;
  plCleanTree(tpl.content);                       // sempre, mesmo depois do seu sanitizador (barato; e ele pode não ter cobrido tudo)
  host.appendChild(tpl.content);
}

/** Opções da extração em andamento (veja extractLayout). */
let plOpt = { marks: false, skip: null, acceptImage: null };

/* ── cores ── */
const plCanvas = document.createElement('canvas'); plCanvas.width = plCanvas.height = 1;
const plCtx = plCanvas.getContext('2d', { willReadFrequently: true });
const plColorCache = new Map();
const plHex = (n) => Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0');

/** Qualquer cor do CSS (rgb, rgba, color(srgb ...), color-mix...) → { c: '#rrggbb', a: 0..1 }, ou null se for transparente/inválida. */
function plParseColor(str) {
  if (!str || str === 'transparent') return null;
  if (plColorCache.has(str)) return plColorCache.get(str);
  let out = null;
  const m = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)$/.exec(str);
  if (m) {
    let a = m[4] === undefined ? 1 : (m[4].endsWith('%') ? parseFloat(m[4]) / 100 : parseFloat(m[4]));
    out = a > 0 ? { c: '#' + plHex(m[1]) + plHex(m[2]) + plHex(m[3]), a } : null;
  } else {
    plCtx.clearRect(0, 0, 1, 1);
    plCtx.fillStyle = '#000'; plCtx.fillStyle = str;
    plCtx.fillRect(0, 0, 1, 1);
    const d = plCtx.getImageData(0, 0, 1, 1).data;
    out = d[3] > 0 ? { c: '#' + plHex(d[0]) + plHex(d[1]) + plHex(d[2]), a: d[3] / 255 } : null;
  }
  plColorCache.set(str, out);
  return out;
}

const plPx = (v) => { const n = parseFloat(v); return Number.isFinite(n) ? n : 0; };
const plR = (n) => Math.round(n * 100) / 100;

/* ── degradê linear ──
 * O PDF não tem degradê nas operações: ele vira faixas finas, cada uma com a cor do ponto onde está (visualmente igual, sem a emenda de uma faixa para outra).
 * Só `linear-gradient(...)` sozinho no fundo, na vertical ou na horizontal (ângulos em diagonal viram a direção mais próxima); radial, repetido ou em camadas fica de fora. */
function plSplitTop(str) {
  const out = []; let depth = 0, cur = '';
  for (const ch of str) {
    if (ch === '(') depth++; else if (ch === ')') depth--;
    if (ch === ',' && depth === 0) { out.push(cur.trim()); cur = ''; } else cur += ch;
  }
  out.push(cur.trim());
  return out;
}

function plGradientOps(bgImage, rect, rad, ops, opacity) {
  if (!bgImage || bgImage === 'none' || !bgImage.startsWith('linear-gradient(') || !bgImage.endsWith(')')) return;
  const inner = bgImage.slice('linear-gradient('.length, -1);
  if (/(gradient|url)[(]/.test(inner)) return;                         // várias camadas
  const parts = plSplitTop(inner);
  let angle = 180;                                                      // padrão do CSS: de cima para baixo
  if (/^(to |[-0-9.]+(deg|turn|rad|grad))/.test(parts[0])) {
    const d = parts.shift();
    const m = /^([-0-9.]+)(deg|turn|rad|grad)$/.exec(d);
    if (m) { const v = parseFloat(m[1]); angle = m[2] === 'deg' ? v : m[2] === 'turn' ? v * 360 : m[2] === 'rad' ? v * 180 / Math.PI : v * 0.9; }
    else angle = d.includes('right') ? 90 : d.includes('left') ? 270 : d.includes('top') ? 0 : 180;
  }
  angle = ((angle % 360) + 360) % 360;
  const horizontal = (angle > 45 && angle <= 135) || (angle > 225 && angle <= 315);
  const reverse = horizontal ? angle > 225 : (angle > 135 && angle <= 225 ? false : true);   // 180° = de cima para baixo; 90° = da esquerda para a direita
  const { x, y, w, h } = rect;
  const len = horizontal ? w : h;
  if (len <= 0 || (horizontal ? h : w) <= 0) return;
  // paradas: cor + posição (0..1); sem posição, distribui entre as vizinhas
  const stops = parts.map((p) => {
    const m = /^(.*?)(?:\s+(-?[0-9.]+)(%|px))?$/.exec(p);
    const col = plParseColor(m[1].trim());
    return { col: col || { c: '#000000', a: 0 }, pos: m[2] === undefined ? null : (m[3] === '%' ? parseFloat(m[2]) / 100 : parseFloat(m[2]) / len) };
  });
  if (stops.length < 2) return;
  if (stops[0].pos === null) stops[0].pos = 0;
  if (stops[stops.length - 1].pos === null) stops[stops.length - 1].pos = 1;
  for (let i = 1; i < stops.length - 1; i++) {
    if (stops[i].pos !== null) continue;
    let j = i; while (stops[j].pos === null) j++;
    const a0 = stops[i - 1].pos, a1 = stops[j].pos;
    for (let k = i; k < j; k++) stops[k].pos = a0 + (a1 - a0) * (k - i + 1) / (j - i + 1);
  }
  for (let i = 1; i < stops.length; i++) stops[i].pos = Math.max(stops[i].pos, stops[i - 1].pos);
  const rgb = (c) => [parseInt(c.slice(1, 3), 16), parseInt(c.slice(3, 5), 16), parseInt(c.slice(5, 7), 16)];
  const at = (t) => {
    if (t <= stops[0].pos) return stops[0].col;
    for (let i = 1; i < stops.length; i++) {
      if (t <= stops[i].pos) {
        const a = stops[i - 1], b = stops[i], f = b.pos === a.pos ? 1 : (t - a.pos) / (b.pos - a.pos);
        const ca = rgb(a.col.c), cb = rgb(b.col.c);
        return { c: '#' + [0, 1, 2].map((k) => plHex(ca[k] + (cb[k] - ca[k]) * f)).join(''), a: a.col.a + (b.col.a - a.col.a) * f };
      }
    }
    return stops[stops.length - 1].col;
  };
  const n = Math.max(8, Math.min(96, Math.round(len / 3)));
  const round = rad.some((v) => v > 0);
  if (round) ops.push({ t: 'C', x: plR(x), y: plR(y), w: plR(w), h: plR(h), r: rad.map(plR) });
  const step = len / n;
  for (let i = 0; i < n; i++) {
    const t = (i + 0.5) / n;
    const col = at(reverse ? 1 - t : t);
    if (!col || col.a <= 0) continue;
    const from = i * step, size = step + (i < n - 1 ? 1.5 : 0);          // as faixas se sobrepõem um pouco: sem fresta entre elas
    ops.push(horizontal
      ? { t: 'R', x: plR(x + from), y: plR(y), w: plR(size), h: plR(h), c: col.c, a: plR(col.a * opacity) }
      : { t: 'R', x: plR(x), y: plR(y + from), w: plR(w), h: plR(size), c: col.c, a: plR(col.a * opacity) });
  }
  if (round) ops.push({ t: 'E' });
}

/* ── caixas ── */
function plRadii(cs, w, h) {
  const one = (v) => { const n = parseFloat(v); if (!Number.isFinite(n) || n <= 0) return 0; return String(v).includes('%') ? Math.min(w, h) * n / 100 : n; };
  return [one(cs.borderTopLeftRadius), one(cs.borderTopRightRadius), one(cs.borderBottomRightRadius), one(cs.borderBottomLeftRadius)];
}

/** Fundo, bordas e sombra interna de um retângulo (rect em coordenadas da página). */
function plBoxOps(cs, rect, ops, opacity) {
  const { x, y, w, h } = rect;
  if (w <= 0 || h <= 0) return;
  const rad = plRadii(cs, w, h);
  const outer = [], start = ops.length;           // sombras externas entram ANTES do fundo (ficam por baixo dele)
  const bg = plParseColor(cs.backgroundColor);
  if (bg) {
    const op = { t: 'R', x: plR(x), y: plR(y), w: plR(w), h: plR(h), c: bg.c, a: plR(bg.a * opacity) };
    if (rad.some((v) => v > 0)) op.r = rad.map(plR);
    ops.push(op);
  }
  plGradientOps(cs.backgroundImage, rect, rad, ops, opacity);
  const sides = ['Top', 'Right', 'Bottom', 'Left'].map((s) => {
    const bw = plPx(cs['border' + s + 'Width']);
    const st = cs['border' + s + 'Style'];
    const col = plParseColor(cs['border' + s + 'Color']);
    return bw > 0 && st !== 'none' && st !== 'hidden' && col ? [plR(bw), col.c, plR(col.a * opacity)] : [0, '#000000', 0];
  });
  if (sides.some((s) => s[0] > 0)) {
    const op = { t: 'B', x: plR(x), y: plR(y), w: plR(w), h: plR(h), s: sides };
    if (rad.some((v) => v > 0)) op.r = rad.map(plR);
    ops.push(op);
  }
  // sombras sem desfoque: externas ("20px 0 0 cor": os pontinhos de um box-shadow) e internas simples ("inset 4px 0 0 cor": a barra lateral das linhas de código destacadas)
  plShadows(cs.boxShadow).forEach((sh) => {
    const col = plParseColor(sh.color);
    if (!col || sh.blur !== 0) return;
    const a = plR(col.a * opacity);
    if (sh.inset) {
      if (sh.spread !== 0) return;
      const { x: ox, y: oy } = sh;
      if (ox > 0) ops.push({ t: 'R', x: plR(x), y: plR(y), w: plR(ox), h: plR(h), c: col.c, a });
      else if (ox < 0) ops.push({ t: 'R', x: plR(x + w + ox), y: plR(y), w: plR(-ox), h: plR(h), c: col.c, a });
      if (oy > 0) ops.push({ t: 'R', x: plR(x), y: plR(y), w: plR(w), h: plR(oy), c: col.c, a });
      else if (oy < 0) ops.push({ t: 'R', x: plR(x), y: plR(y + h + oy), w: plR(w), h: plR(-oy), c: col.c, a });
    } else {
      outer.push({ x: x + sh.x - sh.spread, y: y + sh.y - sh.spread, w: w + 2 * sh.spread, h: h + 2 * sh.spread, c: col.c, a, r: rad.map((v) => (v > 0 ? Math.max(0, v + sh.spread) : 0)) });
    }
  });
  // a primeira sombra da lista fica por cima: entram por último; todas ficam ABAIXO do fundo do elemento (as operações são pintadas em ordem)
  if (outer.length) ops.splice(start, 0, ...outer.reverse().map((o) => { const op = { t: 'R', x: plR(o.x), y: plR(o.y), w: plR(o.w), h: plR(o.h), c: o.c, a: o.a }; if (o.r.some((v) => v > 0)) op.r = o.r.map(plR); return op; }));
}

/** box-shadow do estilo calculado → [{x, y, blur, spread, color, inset}] (a cor vem no formato rgb()/rgba() que o navegador devolve). */
function plShadows(v) {
  if (!v || v === 'none') return [];
  const parts = []; let depth = 0, cur = '';
  for (const ch of v) {
    if (ch === '(') depth++; else if (ch === ')') depth--;
    if (ch === ',' && depth === 0) { parts.push(cur); cur = ''; } else cur += ch;
  }
  parts.push(cur);
  return parts.map((p) => {
    const m = /(rgba?\([^)]*\)|color\([^)]*\)|#[0-9a-f]+)/i.exec(p);
    const nums = p.replace(m ? m[0] : '', '').match(/-?[\d.]+px/g) || [];
    return { color: m ? m[0] : '', inset: /inset/.test(p), x: plPx(nums[0]), y: plPx(nums[1]), blur: plPx(nums[2]), spread: plPx(nums[3]) };
  });
}

/**
 * Pseudo-elemento (::before / ::after) que é só uma caixa vazia (content: ''): um ponto, um traço, um quadradinho de marcador. Ele não existe no DOM,
 * então a posição é calculada: absoluto, a partir das bordas do bloco que o contém; em fluxo (display: block), logo no começo (::before) ou no fim
 * (::after) da área de conteúdo do elemento. Só fundo, bordas, cantos e sombras sem desfoque; gradientes, imagens, texto e transformações ficam de fora.
 */
function plPseudoOps(el, which, ox, oy, ops, opacity) {
  if (plOpt.marks && el.classList.contains('mk')) return;                 // com marks: true, o desenho da marca é a operação "M", não o ::after da tela
  const p = getComputedStyle(el, which);
  if (!p || (p.content !== '""' && p.content !== "''") || p.display === 'none' || p.visibility === 'hidden') return;
  if (p.position === 'fixed' || p.position === 'sticky') return;
  const num = (v) => { const n = parseFloat(v); return Number.isFinite(n) ? n : null; };
  const pad = [plPx(p.paddingLeft), plPx(p.paddingRight), plPx(p.paddingTop), plPx(p.paddingBottom)];
  const bor = [plPx(p.borderLeftWidth), plPx(p.borderRightWidth), plPx(p.borderTopWidth), plPx(p.borderBottomWidth)];
  const border = p.boxSizing === 'border-box';
  const bw = num(p.width) === null ? 0 : (border ? num(p.width) : num(p.width) + pad[0] + pad[1] + bor[0] + bor[1]);
  const bh = num(p.height) === null ? 0 : (border ? num(p.height) : num(p.height) + pad[2] + pad[3] + bor[2] + bor[3]);
  if (bw <= 0 || bh <= 0) return;
  const m = [plPx(p.marginLeft), plPx(p.marginRight), plPx(p.marginTop), plPx(p.marginBottom)];
  const cs = getComputedStyle(el);
  const er = el.getBoundingClientRect();
  let x, y;
  if (p.position === 'absolute') {
    const cb = cs.position !== 'static' ? el : el.offsetParent;
    if (!cb || cb === document.body || cb === document.documentElement) return;
    const ccs = getComputedStyle(cb), cr = cb.getBoundingClientRect();
    const pl = cr.left + plPx(ccs.borderLeftWidth), pt = cr.top + plPx(ccs.borderTopWidth);
    const pr = cr.right - plPx(ccs.borderRightWidth), pb = cr.bottom - plPx(ccs.borderBottomWidth);
    const L = num(p.left), R = num(p.right), T = num(p.top), B = num(p.bottom);
    x = L !== null ? pl + L + m[0] : (R !== null ? pr - R - m[1] - bw : er.left + plPx(cs.borderLeftWidth) + plPx(cs.paddingLeft) + m[0]);
    y = T !== null ? pt + T + m[2] : (B !== null ? pb - B - m[3] - bh : er.top + plPx(cs.borderTopWidth) + plPx(cs.paddingTop) + m[2]);
  } else if (p.display === 'block') {
    x = er.left + plPx(cs.borderLeftWidth) + plPx(cs.paddingLeft) + m[0];
    y = which === '::before'
      ? er.top + plPx(cs.borderTopWidth) + plPx(cs.paddingTop) + m[2]
      : er.bottom - plPx(cs.borderBottomWidth) - plPx(cs.paddingBottom) - m[3] - bh;
  } else return;
  const o = parseFloat(p.opacity);
  plBoxOps(p, { x: x - ox, y: y - oy, w: bw, h: bh }, ops, opacity * (Number.isFinite(o) ? o : 1));
}

/* ── texto ── */
/** Peso da fonte arredondado de 100 em 100 e limitado a 400–700 (os pesos que há em fonts/). */
function plWeight(v) {
  const n = parseInt(v, 10);
  return Number.isFinite(n) ? Math.max(400, Math.min(700, Math.round(n / 100) * 100)) : (v === 'bold' ? 700 : 400);
}

function plFontOf(cs) {
  const fam = (cs.fontFamily.split(',')[0] || '').replace(/["']/g, '').trim();
  return { f: fam, g: plWeight(cs.fontWeight), i: cs.fontStyle === 'italic' || cs.fontStyle === 'oblique' ? 1 : 0, z: plR(plPx(cs.fontSize)) };
}

function plTransform(s, t) {
  if (t === 'uppercase') return s.toUpperCase();
  if (t === 'lowercase') return s.toLowerCase();
  if (t === 'capitalize') return s.replace(/(^|\s)(\S)/g, (m, a, b) => a + b.toUpperCase());
  return s;
}

/** Cada palavra de um nó de texto, com a caixa que o navegador deu a ela. */
function plTextOps(node, cs, ctx, ox, oy, ops) {
  const raw = node.data;
  if (!/\S/.test(raw)) return;
  const col = plParseColor(cs.color);
  if (!col) return;
  const fo = plFontOf(cs);
  const ls = cs.letterSpacing === 'normal' ? 0 : plPx(cs.letterSpacing);
  const dl = cs.textDecorationLine || '';
  const deco = (dl.includes('underline') ? 1 : 0) | (dl.includes('line-through') ? 2 : 0);
  const a = plR(col.a * ctx.opacity);
  const range = document.createRange();
  const re = /\S+/g;
  let m;
  while ((m = re.exec(raw))) {
    range.setStart(node, m.index);
    range.setEnd(node, m.index + m[0].length);
    const rects = range.getClientRects();
    if (!rects.length) continue;
    const push = (text, r) => {
      if (r.width <= 0 || r.height <= 0) return;
      const op = { t: 'T', x: plR(r.left - ox), y: plR(r.top - oy), w: plR(r.width), h: plR(r.height), s: plTransform(text, cs.textTransform), f: fo.f, z: fo.z, c: col.c };
      if (fo.g !== 400) op.g = fo.g;
      if (fo.i) op.i = 1;
      if (a < 1) op.a = a;
      if (ls) op.l = plR(ls);
      if (deco) op.d = deco;
      ops.push(op);
    };
    if (rects.length === 1) { push(m[0], rects[0]); continue; }
    // palavra quebrada em duas linhas: letra por letra
    for (let k = 0; k < m[0].length; k++) {
      range.setStart(node, m.index + k);
      range.setEnd(node, m.index + k + 1);
      const rr = range.getClientRects();
      if (rr.length) push(m[0][k], rr[0]);
    }
  }
}

/** Primeiro texto visível de um item de lista (fora de sub-listas): dá a altura da 1ª linha, onde o marcador fica. */
function plFirstLineRect(li) {
  const walker = document.createTreeWalker(li, NodeFilter.SHOW_TEXT, {
    acceptNode: (n) => (/\S/.test(n.data) && n.parentElement.closest('li') === li ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT),
  });
  const n = walker.nextNode();
  if (!n) return null;
  const rg = document.createRange();
  rg.selectNodeContents(n);
  const rs = rg.getClientRects();
  return rs.length ? rs[0] : null;
}
/** Marcador (•, ○, ▪ ou "1.") de um item de lista: não existe no DOM, então é desenhado aqui. */
function plListMarker(li, cs, ctx, ox, oy, ops) {
  const type = cs.listStyleType;
  if (!type || type === 'none') return;
  const fr = plFirstLineRect(li);
  if (!fr) return;
  const mk = getComputedStyle(li, '::marker') || cs;                          // o ::marker pode ter cor, peso e tamanho próprios (número grande e colorido)
  const col = plParseColor(mk.color || cs.color);
  if (!col) return;
  const fs = plPx(cs.fontSize);
  const left = li.getBoundingClientRect().left - ox;
  const cy = fr.top - oy + fr.height / 2;
  const a = plR(col.a * ctx.opacity);
  if (type === 'disc' || type === 'circle' || type === 'square') {
    const d = fs * 0.3;
    const x = left - fs * 0.78 - d / 2;
    if (type === 'square') ops.push({ t: 'R', x: plR(x), y: plR(cy - d / 2), w: plR(d), h: plR(d), c: col.c, a });
    else if (type === 'disc') ops.push({ t: 'R', x: plR(x), y: plR(cy - d / 2), w: plR(d), h: plR(d), c: col.c, a, r: [d / 2, d / 2, d / 2, d / 2] });
    else ops.push({ t: 'B', x: plR(x), y: plR(cy - d / 2), w: plR(d), h: plR(d), s: [[1, col.c, a], [1, col.c, a], [1, col.c, a], [1, col.c, a]], r: [d / 2, d / 2, d / 2, d / 2] });
    return;
  }
  // numerada: "1." alinhado à direita, terminando um espaço antes do texto
  const parent = li.parentElement;
  const start = parseInt(parent.getAttribute('start') || '1', 10) || 1;
  let idx = start - 1;
  for (const s of parent.children) { if (s.tagName === 'LI') idx++; if (s === li) break; }
  const val = li.hasAttribute('value') ? parseInt(li.getAttribute('value'), 10) : idx;
  let label = String(val);
  if (type === 'lower-alpha' || type === 'lower-latin') label = String.fromCharCode(96 + ((val - 1) % 26) + 1);
  else if (type === 'upper-alpha' || type === 'upper-latin') label = String.fromCharCode(64 + ((val - 1) % 26) + 1);
  label += '.';
  const fo = plFontOf(mk.fontSize ? mk : cs);
  plCtx.font = `${fo.i ? 'italic ' : ''}${fo.g} ${fo.z}px "${fo.f}"`;
  const w = plCtx.measureText(label).width;
  ops.push({ t: 'T', x: plR(left - fs * 0.3 - w), y: plR(fr.top - oy + (fr.height - fo.z * 1.25) / 2), w: plR(w), h: plR(fo.z * 1.25), s: label, f: fo.f, z: fo.z, c: col.c, ...(fo.g !== 400 ? { g: fo.g } : {}), ...(a < 1 ? { a } : {}) });
}

/* ── imagens ── */
function plImageOp(img, cs, ctx, ox, oy, ops) {
  const r = img.getBoundingClientRect();
  const w = r.width, h = r.height;
  if (w <= 0 || h <= 0 || !img.naturalWidth) return;
  const src = img.getAttribute('src') || '';
  if (!src || (plOpt.acceptImage && !plOpt.acceptImage(src))) return;
  const x = r.left - ox + plPx(cs.borderLeftWidth) + plPx(cs.paddingLeft), y = r.top - oy + plPx(cs.borderTopWidth) + plPx(cs.paddingTop);
  const bw = w - plPx(cs.borderLeftWidth) - plPx(cs.borderRightWidth) - plPx(cs.paddingLeft) - plPx(cs.paddingRight);
  const bh = h - plPx(cs.borderTopWidth) - plPx(cs.borderBottomWidth) - plPx(cs.paddingTop) - plPx(cs.paddingBottom);
  // object-fit: onde a imagem é desenhada dentro da caixa (e o recorte, se ela passa da caixa)
  const fit = cs.objectFit;
  const nw = img.naturalWidth, nh = img.naturalHeight;
  let dw = bw, dh = bh;
  if (fit === 'cover') { const k = Math.max(bw / nw, bh / nh); dw = nw * k; dh = nh * k; }
  else if (fit === 'contain' || fit === 'scale-down') { const k = Math.min(bw / nw, bh / nh); dw = nw * k; dh = nh * k; }
  else if (fit === 'none') { dw = nw; dh = nh; }
  const dx = x + (bw - dw) / 2, dy = y + (bh - dh) / 2;
  const op = { t: 'I', x: plR(dx), y: plR(dy), w: plR(dw), h: plR(dh), u: src, k: [plR(x), plR(y), plR(bw), plR(bh)] };
  const ir = plRadii(cs, bw, bh);
  if (ir.some((v) => v > 0)) op.r = ir.map(plR);
  const f = cs.filter;
  if (f && f !== 'none') op.f = f;
  const a = plR(ctx.opacity);
  if (a < 1) op.a = a;
  ops.push(op);
}

/* ── marcas à mão (opcional: marks: true) ──
 * <span class="mk mk-circle" style="--mk-color:#e5484d">texto</span>. A cor vem de --mk-color (sem ela, a cor do texto); a 2ª variação do desenho vale
 * para os elementos pares (:nth-of-type(even)). Uma marca com display: inline que quebra de linha ganha um desenho por linha. */
function plMarkOp(el, shape, ox, oy, ops) {
  const cs = getComputedStyle(el);
  const col = plParseColor(cs.getPropertyValue('--mk-color').trim()) || plParseColor(cs.color);
  if (!col) return;
  const sib = [...el.parentNode.children].filter((s) => s.tagName === el.tagName);
  const v = (sib.indexOf(el) + 1) % 2 === 0 ? 1 : 0;
  const z = plR(plPx(cs.fontSize));
  const rects = cs.display === 'inline' ? [...el.getClientRects()] : [el.getBoundingClientRect()];
  for (const r of rects) {
    if (r.width <= 0 || r.height <= 0) continue;
    ops.push({ t: 'M', m: shape, v, x: plR(r.left - ox), y: plR(r.top - oy), w: plR(r.width), h: plR(r.height), c: col.c, z });
  }
}

/* ── percurso ── */
const PL_SKIP = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'HEAD', 'META', 'LINK', 'TITLE', 'BR', 'WBR']);

function plWalk(el, ctx, ox, oy, ops) {
  if (PL_SKIP.has(el.tagName)) return;
  const cs = getComputedStyle(el);
  if (cs.display === 'none' || cs.visibility === 'hidden') return;
  if (plOpt.skip && plOpt.skip(el)) return;                                  // ex.: um elemento que outro cobre por inteiro
  const opacity = ctx.opacity * (Number.isFinite(parseFloat(cs.opacity)) ? parseFloat(cs.opacity) : 1);
  const c2 = { opacity };

  if (el.tagName === 'IMG') { plBoxBackground(el, cs, ox, oy, ops, opacity); plImageOp(el, cs, c2, ox, oy, ops); return; }

  // caixa: um bloco tem uma; um trecho em linha que quebra de linha tem uma por linha
  if (cs.display === 'inline') {
    for (const r of el.getClientRects()) plBoxOps(cs, { x: r.left - ox, y: r.top - oy, w: r.width, h: r.height }, ops, opacity);
  } else {
    const r = el.getBoundingClientRect();
    plBoxOps(cs, { x: r.left - ox, y: r.top - oy, w: r.width, h: r.height }, ops, opacity);
  }

  // overflow: hidden com cantos arredondados (tabelas, blocos coloridos): o Chrome recorta o que está dentro, então os fundos dos filhos também ficam arredondados
  let clipped = false;
  if (cs.display !== 'inline' && (cs.overflowX !== 'visible' || cs.overflowY !== 'visible')) {
    const cr = el.getBoundingClientRect();
    const rad = plRadii(cs, cr.width, cr.height);
    if (rad.some((v) => v > 0)) {
      const bl = plPx(cs.borderLeftWidth), bt = plPx(cs.borderTopWidth), br = plPx(cs.borderRightWidth), bb = plPx(cs.borderBottomWidth);
      const inner = rad.map((v) => Math.max(0, v - Math.max(bl, bt, br, bb)));
      ops.push({ t: 'C', x: plR(cr.left - ox + bl), y: plR(cr.top - oy + bt), w: plR(cr.width - bl - br), h: plR(cr.height - bt - bb), r: inner.map(plR) });
      clipped = true;
    }
  }

  if (cs.display === 'list-item' && el.tagName === 'LI') plListMarker(el, cs, c2, ox, oy, ops);
  plPseudoOps(el, '::before', ox, oy, ops, opacity);

  for (const n of el.childNodes) {
    if (n.nodeType === 3) plTextOps(n, cs, c2, ox, oy, ops);
    else if (n.nodeType === 1) plWalk(n, c2, ox, oy, ops);
  }

  plPseudoOps(el, '::after', ox, oy, ops, opacity);
  if (clipped) ops.push({ t: 'E' });

  // marca à mão: desenhada depois do texto (o ::after do Chrome fica por cima)
  if (plOpt.marks && el.classList.contains('mk')) {
    const m = [...el.classList].map((c) => PL_SHAPES.exec(c)).find(Boolean);
    if (m) plMarkOp(el, m[1], ox, oy, ops);
  }
}

function plBoxBackground(el, cs, ox, oy, ops, opacity) {
  const r = el.getBoundingClientRect();
  plBoxOps(cs, { x: r.left - ox, y: r.top - oy, w: r.width, h: r.height }, ops, opacity);
}

/** Espera a imagem carregar (no máximo 4 s: uma imagem quebrada não pode travar a exportação). */
function plWaitImage(im) {
  if (im.complete && im.naturalWidth) return null;
  return new Promise((resolve) => {
    const done = () => resolve();
    im.addEventListener('load', done, { once: true });
    im.addEventListener('error', done, { once: true });
    setTimeout(done, 4000);
  });
}
/**
 * Extrai o layout de cada página.
 *
 * @param {Array<string|{html:string}>} pages  o HTML (um fragmento) de cada página; ou qualquer valor, se você passar `wrap`
 * @param {object}   o
 * @param {number}   o.width            largura da página em px CSS
 * @param {number}   o.height           altura da página em px CSS
 * @param {(page:any, index:number)=>string} [o.wrap]  HTML COMPLETO da página (um único elemento raiz; o fundo dele vira o fundo da página).
 *                                       Padrão: `<div>` + o HTML da página + `</div>`.
 * @param {string}   [o.hostCss]        CSS extra dentro do contêiner de medição (ex.: um reset `box-sizing`/`margin` que seu CSS espere)
 * @param {boolean}  [o.marks=false]    desenhar as marcas à mão (`.mk` + `.mk-circle` etc., veja docs/format.md)
 * @param {(el:Element)=>boolean} [o.skip]  devolva true para NÃO desenhar um elemento (e o que está dentro dele)
 * @param {(src:string)=>boolean} [o.acceptImage]  filtro dos `src` de imagem enviados ao servidor (o servidor decide de verdade)
 * @param {(html:string)=>string} [o.sanitize]  sanitizador do HTML de cada página (ex.: `(h) => DOMPurify.sanitize(h)`). Seja qual for, o módulo ainda remove
 *                                       scripts, iframes, `on*=`, `javascript:` etc. antes de inserir (veja README › Segurança)
 * @returns {Promise<{w:number,h:number,pages:{bg:string,ops:object[]}[]}>}
 */
export async function extractLayout(pages, o) {
  if (!o || !(o.width > 0) || !(o.height > 0)) throw new Error('extractLayout: informe width e height (px)');
  plOpt = { marks: !!o.marks, skip: o.skip || null, acceptImage: o.acceptImage || null };
  const wrap = o.wrap || ((p) => '<div>' + (typeof p === 'string' ? p : (p && p.html) || '') + '</div>');
  if (document.fonts && document.fonts.ready) await document.fonts.ready;
  const host = document.createElement('div');
  host.setAttribute('aria-hidden', 'true');
  host.setAttribute('data-jh2pdf-host', '');
  host.style.cssText = `position:fixed;left:-60000px;top:0;width:${o.width}px;height:${o.height}px;pointer-events:none;`;
  host.innerHTML = o.hostCss ? '<style>' + o.hostCss + '</style>' : '';
  document.body.appendChild(host);
  const out = { w: o.width, h: o.height, pages: [] };
  try {
    for (let i = 0; i < pages.length; i++) {
      if (host.lastElementChild && host.lastElementChild.tagName !== 'STYLE') host.lastElementChild.remove();
      plInsert(host, wrap(pages[i], i), o.sanitize);
      const root = host.lastElementChild;
      await Promise.all([...host.querySelectorAll('img')].map(plWaitImage));
      root.getBoundingClientRect();                                              // força o layout: é nele que o navegador começa a baixar as fontes desta página
      if (document.fonts && document.fonts.ready) await document.fonts.ready;     // sem isso as palavras seriam medidas com a fonte de reserva
      const sr = root.getBoundingClientRect();
      const bg = plParseColor(getComputedStyle(root).backgroundColor);
      const ops = [];
      plWalk(root, { opacity: 1 }, sr.left, sr.top, ops);
      out.pages.push({ bg: bg ? bg.c : '#ffffff', ops });
    }
  } finally {
    host.remove();
  }
  return out;
}
