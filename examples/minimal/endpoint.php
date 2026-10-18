<?php
/**
 * Endpoint de exemplo: recebe o layout (JSON) do navegador e devolve o PDF.
 * Em produção: exija login, CSRF e limite a taxa de pedidos (gerar PDF é caro), e limite o tamanho do corpo.
 *
 * Rodar o exemplo:   composer install   (na pasta Jh2Pdf)   e   php -S localhost:8080 -t Jh2Pdf
 * Abrir:             http://localhost:8080/examples/minimal/index.html
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/autoload.php';
foreach ([dirname(__DIR__, 2) . '/vendor/autoload.php', dirname(__DIR__, 4) . '/vendor/autoload.php'] as $auto) {
    if (is_file($auto)) { require $auto; break; }
}

$raw = file_get_contents('php://input', false, null, 0, 5_000_000);          // no máx. 5 MB
$layout = json_decode((string)$raw, true);
if (!is_array($layout)) { http_response_code(400); exit('JSON inválido'); }

$tmp = sys_get_temp_dir() . '/jh2pdf-example';
if (!is_dir($tmp)) mkdir($tmp, 0775, true);

try {
    $pdf = (new Jh2Pdf\Renderer(new Jh2Pdf\Options($tmp)))->render($layout, 'Exemplo');
} catch (Jh2Pdf\Exception $e) {
    http_response_code(500);
    exit('Não foi possível gerar o PDF');
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="exemplo.pdf"');
echo $pdf;
