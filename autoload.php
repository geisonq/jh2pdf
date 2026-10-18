<?php
/**
 * Autoloader mínimo (PSR-4) para usar a biblioteca sem o Composer:
 *     require '/caminho/Jh2Pdf/autoload.php';
 * Com o Composer, basta `composer require` (o composer.json já declara o autoload).
 * O Dompdf (dependência) precisa estar carregado por conta de quem usa: `composer require dompdf/dompdf`.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Jh2Pdf\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});
