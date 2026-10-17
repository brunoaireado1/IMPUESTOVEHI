<?php
/**
 * Prueba la consulta desde la terminal (no funciona desde el navegador).
 *   php privado/cli.php <PLACA> <DOCUMENTO> [TIPO]
 *   CV_DEBUG=1 php privado/cli.php ...   -> guarda las imágenes del captcha
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if ($argc < 3) {
    fwrite(STDERR, "Uso: php privado/cli.php <PLACA> <DOCUMENTO> [TIPO: C|E|N|P]\n");
    exit(2);
}

require __DIR__ . '/ConsultaVehiculo.php';
$config = require __DIR__ . '/config.php';

try {
    $cv = new ConsultaVehiculo($config['capsolver_key'], (int) $config['timeout'], (bool) getenv('CV_DEBUG'));
    $res = $cv->consultar($argv[1], $argv[2], $argv[3] ?? 'C');
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
