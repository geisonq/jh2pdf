<?php
/**
 * Endpoint da página de exemplos: recebe o layout (JSON) que o navegador extraiu e devolve o PDF.
 *
 * É uma demonstração local. Em produção, acrescente o que é seu (veja docs › Segurança): login, CSRF, limite de taxa
 * e um ImageResolver próprio se as imagens não vierem como data:.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';            // Dompdf (composer install na raiz do projeto)

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Use POST'); }

$raw = file_get_contents('php://input', false, null, 0, 12_000_000);           // no máx. 12 MB (as imagens dos exemplos vão como data:)
$layout = json_decode((string)$raw, true);
if (!is_array($layout)) { http_response_code(400); exit('JSON inválido'); }

$title = isset($_GET['title']) ? mb_substr((string)$_GET['title'], 0, 120) : 'Jh2Pdf — exemplos';
$tmp = sys_get_temp_dir() . '/jh2pdf-demo';
if (!is_dir($tmp)) mkdir($tmp, 0775, true);

$t0 = microtime(true);
try {
    $pdf = (new Jh2Pdf\Renderer(new Jh2Pdf\Options($tmp)))->render($layout, $title);
} catch (Jh2Pdf\Exception $e) {
    error_log('jh2pdf demo: ' . $e->getMessage() . ($e->getPrevious() ? ' — ' . $e->getPrevious()->getMessage() : ''));
    http_response_code(500);
    exit('Não foi possível gerar o PDF');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="jh2pdf-exemplo.pdf"');
header('X-Jh2Pdf-Ms: ' . (int)round((microtime(true) - $t0) * 1000));
header('Access-Control-Expose-Headers: X-Jh2Pdf-Ms');
echo $pdf;
