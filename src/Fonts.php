<?php
declare(strict_types=1);

namespace Jh2Pdf;

use Dompdf\FontMetrics;

/**
 * Escolhe a fonte de cada palavra e a registra no Dompdf. As fontes vêm do {@see FontIndex}: TTF ESTÁTICOS (o Dompdf não lê woff2 nem
 * fontes variáveis), registrados com {@see Options::addFont()} ou nomeados `<familia-em-minusculas-com-hifens>-<peso>[-italic].ttf`
 * em `fontDir`/`addFontDir()` — ex.: `dm-sans-600.ttf`, `lora-400-italic.ttf`; pesos de 100 a 900.
 *
 * A escolha segue o CSS: primeiro a família (a primeira da pilha `font-family` que o servidor tem), depois o estilo (itálico, ou inclinação
 * simulada se a família não tem), depois o peso mais próximo ({@see nearestWeight()}). Cada arquivo vira uma "família" própria no Dompdf,
 * que só entende normal/negrito.
 */
final class Fonts
{
    /** No máximo quantas famílias de reserva (`fb`) uma palavra pode trazer. */
    private const MAX_FALLBACKS = 5;

    private FontIndex $index;
    /** @var array<string,string|null>  arquivo => nome da família no Dompdf (null = não foi possível registrar) */
    private array $registered = [];

    public function __construct(private FontMetrics $fm, private Options $o, ?FontIndex $index = null)
    {
        $this->index = $index ?? new FontIndex($o);
    }

    /** Nome de família vindo do JSON (hostil): só letras, números, espaço e hífen, até 60 caracteres. */
    public static function cleanFamily(mixed $v): string
    {
        return is_string($v) ? mb_substr(trim((string)preg_replace('/[^A-Za-z0-9 \-]/', '', $v)), 0, 60) : '';
    }

    /**
     * A pilha de famílias de uma operação de texto: `f` (a primeira) e `fb` (as de reserva, separadas por vírgula).
     * @return array{0:string,1:string[]}  [primeira, reservas]
     */
    public static function stack(array $op, string $default = ''): array
    {
        $first = self::cleanFamily($op['f'] ?? null);
        $rest = [];
        if (is_string($op['fb'] ?? null)) {
            foreach (array_slice(explode(',', mb_substr($op['fb'], 0, 400)), 0, self::MAX_FALLBACKS) as $x) {
                $x = self::cleanFamily($x);
                if ($x !== '') $rest[] = $x;
            }
        }
        return [$first !== '' ? $first : $default, $rest];
    }

    /**
     * Peso mais próximo entre os disponíveis, pela regra do CSS (font matching):
     * - peso pedido entre 400 e 500: do pedido até 500 (subindo), depois abaixo (descendo), depois acima de 500 (subindo);
     * - abaixo de 400: do pedido para baixo (descendo), depois acima (subindo);
     * - acima de 500: do pedido para cima (subindo), depois abaixo (descendo).
     * @param int[] $available
     */
    public static function nearestWeight(int $want, array $available): ?int
    {
        if (!$available) return null;
        sort($available);
        if (in_array($want, $available, true)) return $want;
        $up = fn(int $from, int $to) => array_values(array_filter($available, fn($w) => $w >= $from && $w <= $to));
        $down = fn(int $from, int $to) => array_reverse(array_values(array_filter($available, fn($w) => $w <= $from && $w >= $to)));
        $order = match (true) {
            $want >= 400 && $want <= 500 => array_merge($up($want, 500), $down($want, 0), $up(501, 1000)),
            $want < 400 => array_merge($down($want, 0), $up($want, 1000)),
            default => array_merge($up($want, 1000), $down($want, 0)),
        };
        return $order[0] ?? $available[0];
    }

    /**
     * Caminho (sem extensão) da fonte registrada para a pilha de famílias, peso e estilo pedidos. Sem nenhuma, cai em `fallbackFont`.
     * @param string[] $fallbacks  famílias de reserva (o resto do `font-family`)
     * @param bool $slant  saída: true se a família não tem itálico e o texto deve ser desenhado inclinado (o navegador faz o mesmo)
     */
    public function resolve(string $family, int $weight, bool $italic, ?bool &$slant = null, array $fallbacks = []): string
    {
        $slant = false;
        foreach (array_merge([$family], $fallbacks) as $cand) {
            $slug = FontIndex::slug($cand);
            $faces = $this->index->faces($slug);
            if (!$faces) continue;
            $usedItalic = $italic;                                                         // estilo primeiro, como no CSS
            $byWeight = array_filter($faces, fn($f) => isset($f[$usedItalic ? 1 : 0]));
            if (!$byWeight) {
                $usedItalic = !$italic;
                $byWeight = array_filter($faces, fn($f) => isset($f[$usedItalic ? 1 : 0]));
            }
            $w = self::nearestWeight($weight, array_keys($byWeight));
            if ($w === null) continue;
            $font = $this->register($slug, $w, $usedItalic, $byWeight[$w][$usedItalic ? 1 : 0]);
            if ($font === null) continue;
            $slant = $italic && !$usedItalic;
            return $font;
        }
        return $this->fm->getFont($this->o->fallbackFont, $weight >= 600 ? 'bold' : 'normal') ?: $this->fm->getFont($this->o->fallbackFont, 'normal');
    }

    /** Registra o arquivo no Dompdf (uma vez) e devolve o caminho da fonte; null se não deu. */
    private function register(string $slug, int $weight, bool $italic, string $file): ?string
    {
        if (!array_key_exists($file, $this->registered)) {
            // o nome leva a data do arquivo: trocar a fonte no mesmo caminho nunca reaproveita o cache antigo do Dompdf
            $name = sprintf('%s w%d%s %s', $slug, $weight, $italic ? ' i' : '', substr(md5($file . '|' . (int)@filemtime($file)), 0, 8));
            if (!isset($this->fm->getFontFamilies()[mb_strtolower($name)])) {
                $this->fm->registerFont(['family' => $name, 'style' => 'normal', 'weight' => 'normal'], str_replace('\\', '/', $file));
            }
            $this->registered[$file] = $this->fm->getFont($name, 'normal') ? $name : null;
        }
        $name = $this->registered[$file];
        return $name === null ? null : ($this->fm->getFont($name, 'normal') ?: null);
    }
}
