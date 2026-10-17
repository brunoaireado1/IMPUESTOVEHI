<?php
/**
 * Único punto de entrada que usa la página.
 *
 * GET consulta.php?accion=config
 *     -> tipos de documento y si está en modo demo
 * POST consulta.php   { placa, tipo, documento, acepta_datos }
 *     -> { ok, vehiculo: {...}, calculo: {...}, meta: {...} }
 *
 * La consulta del vehículo, la tabla de avalúos y el cálculo se hacen aquí, en
 * el servidor. Al navegador solo llega el resultado de ESTE vehículo: nunca la
 * tabla, ni motor, chasis, serie, VIN, licencia o token.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header_remove('X-Powered-By');

$config = require __DIR__ . '/privado/config.php';

function responder(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['accion'] ?? '') === 'config') {
    responder(200, [
        'ok' => true,
        'demo' => (bool) $config['modo_demo'],
        'tipos_documento' => $config['tipos_documento'],
    ]);
}

// ---------------- Diagnóstico del servidor (solo con clave) ----------------
if (($_GET['accion'] ?? '') === 'diagnostico') {
    $clave = (string) ($config['clave_diagnostico'] ?? '');
    if ($clave === '' || !hash_equals($clave, (string) ($_GET['clave'] ?? ''))) {
        responder(404, ['ok' => false, 'error' => 'No encontrado.']);
    }
    $ext = [];
    foreach (['curl', 'gd', 'mbstring', 'json'] as $e) $ext[$e] = extension_loaded($e);

    $dirTmp = __DIR__ . '/privado/tmp';
    if (!is_dir($dirTmp)) @mkdir($dirTmp, 0770, true);
    $prueba = $dirTmp . '/.prueba';
    $tmpOk = @file_put_contents($prueba, 'x') !== false;
    @unlink($prueba);

    // IP pública y país con los que el servidor sale a internet (a través del proxy si hay)
    $ch = curl_init('https://ipinfo.io/json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    if (!empty($config['proxy'])) curl_setopt($ch, CURLOPT_PROXY, $config['proxy']);
    $ipinfo = json_decode((string) curl_exec($ch), true) ?: ['error' => curl_error($ch)];

    // Saldo de CapSolver
    $ch = curl_init('https://api.capsolver.com/getBalance');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['clientKey' => $config['capsolver_key']])]);
    $saldo = json_decode((string) curl_exec($ch), true) ?: ['error' => curl_error($ch)];

    $conexion = null;
    if ($ext['curl'] && $ext['gd']) {
        require_once __DIR__ . '/privado/ConsultaVehiculo.php';
        $conexion = (new ConsultaVehiculo($config['capsolver_key'], (int) $config['timeout'], false, $config['proxy'] ?: null))->probar();
    }

    responder(200, [
        'ok'          => true,
        'php'         => PHP_VERSION,
        'extensiones' => $ext,
        'tmp_escribible' => $tmpOk,
        'servidor_sale_por' => ['ip' => $ipinfo['ip'] ?? null, 'pais' => $ipinfo['country'] ?? null,
                                'ciudad' => $ipinfo['city'] ?? null, 'red' => $ipinfo['org'] ?? null,
                                'error' => $ipinfo['error'] ?? null],
        'proxy'       => !empty($config['proxy']),
        'capsolver'   => ['saldo' => $saldo['balance'] ?? null, 'error' => $saldo['errorDescription'] ?? ($saldo['error'] ?? null)],
        'prueba_consulta' => $conexion ?? 'no se probó: faltan extensiones curl o gd',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

// ---------------- Límite de consultas por IP ----------------
$ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
$dirTmp = __DIR__ . '/privado/tmp';
if (!is_dir($dirTmp)) @mkdir($dirTmp, 0770, true);
$rl = $dirTmp . '/rl_' . md5($ip);
$ahora = time();
$hits = is_file($rl) ? (json_decode((string) file_get_contents($rl), true) ?: []) : [];
$hits = array_values(array_filter($hits, fn($t) => $t > $ahora - 60));
if (count($hits) >= $config['rate_limit_por_minuto']) {
    responder(429, ['ok' => false, 'error' => 'Hiciste muchas consultas seguidas. Espera un minuto e inténtalo de nuevo.']);
}
$hits[] = $ahora;
if (@file_put_contents($rl, json_encode($hits), LOCK_EX) === false) {
    error_log('[consulta-placa] no se puede escribir en ' . $dirTmp . ': el límite por IP no está funcionando');
}

// ---------------- Validación ----------------
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$placa     = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($in['placa'] ?? '')));
$tipo      = strtoupper(trim((string) ($in['tipo'] ?? 'C')));
$documento = preg_replace('/[^0-9A-Za-z]/', '', (string) ($in['documento'] ?? ''));
$acepta    = (bool) ($in['acepta_datos'] ?? false);

$errores = [];
// Carros ABC123, motos ABC12D, y placas especiales de 5-6 caracteres
if (!preg_match('/^[A-Z0-9]{5,6}$/', $placa)) $errores[] = 'Escribe la placa sin espacios ni guiones, por ejemplo ABC123.';
if (!isset($config['tipos_documento'][$tipo])) $errores[] = 'Elige un tipo de documento válido.';
if (strlen($documento) < 4 || strlen($documento) > 15) $errores[] = 'Escribe el número de documento del propietario.';
if (!$acepta) $errores[] = 'Debes autorizar el tratamiento de datos para hacer la consulta.';
if ($errores) responder(422, ['ok' => false, 'error' => implode(' ', $errores)]);

// ---------------- Consulta del vehículo ----------------
if ($config['modo_demo']) {
    $json = json_decode((string) file_get_contents(__DIR__ . '/privado/vehiculo-demo.json'), true);
    $json['infoVehiculo']['placa'] = $placa;
} else {
    require_once __DIR__ . '/privado/ConsultaVehiculo.php';
    try {
        $json = (new ConsultaVehiculo($config['capsolver_key'], (int) $config['timeout'], false, $config['proxy'] ?: null))
            ->consultar($placa, $documento, $tipo);
    } catch (Throwable $e) {
        error_log('[consulta-placa] ' . $e->getMessage());
        responder(502, ['ok' => false, 'error' => 'No pudimos completar la consulta en este momento. Inténtalo de nuevo en unos minutos.']);
    }
}

if (!empty($json['error']) || empty($json['infoVehiculo'])) {
    // El mensaje original se queda en el log del servidor, no se muestra
    error_log('[consulta-placa] sin resultado para ' . $placa . ': ' . ($json['descripcionRespuesta'] ?? ''));
    responder(404, ['ok' => false, 'error' =>
        'No encontramos un vehículo con esa placa y ese documento. Revisa que el documento sea el del propietario actual.']);
}

$v = $json['infoVehiculo'];
$txt = fn($k) => isset($v[$k]) && $v[$k] !== '' ? trim((string) $v[$k]) : null;

// Fecha de matrícula: fechaMatricula si viene, si no fechaRegistro
$fecha = $txt('fechaMatricula') ?? $txt('fechaRegistro');
if ($fecha && preg_match('/^\d{4}-\d{2}-\d{2}/', $fecha, $m)) $fecha = $m[0];

$vehiculo = [
    'placa'            => $txt('placa') ?? $placa,
    'marca'            => $txt('marca'),
    'linea'            => $txt('linea'),
    'modelo'           => (int) ($v['modelo'] ?? 0),
    'clase'            => $txt('clase'),
    'clasificacion'    => $txt('clasificacion'),
    'carroceria'       => $txt('tipoCarroceria'),
    'servicio'         => $txt('tipoServicio'),
    'color'            => $txt('color'),
    'cilindraje'       => (int) ($v['cilindraje'] ?? 0),
    'combustible'      => $txt('tipoCombustible'),
    'organismo'        => $txt('organismoTransito'),
    'estado'           => $txt('estadoAutomotor'),
    'gravamenes'       => $txt('gravamenes'),
    'prendas'          => $txt('prendas'),
    'antiguo_clasico'  => $txt('antiguoClasico'),
    'pasajeros'        => $txt('pasajerosSentados') ?? $txt('pasajerosTotal'),
    'puertas'          => $txt('puertas'),
    'fecha_matricula'  => $fecha,
];

// ---------------- Avalúo e impuesto (solo servidor) ----------------
require_once __DIR__ . '/privado/Avaluos.php';
$A = new Avaluos($config['avaluos']);
$fila = $A->emparejar($vehiculo);
$calculo = $fila ? $A->calcular($fila, $vehiculo) : null;

responder(200, [
    'ok' => true,
    'demo' => (bool) $config['modo_demo'],
    'vehiculo' => $vehiculo,
    'documento' => $tipo . ' ***' . substr($documento, -3),
    'calculo' => $calculo,
    'meta' => [
        'anio_fiscal'  => $A->anioFiscal(),
        'min_anio'     => $A->minAnio,
        'tarifas'      => array_column(Avaluos::TARIFAS, 'tarifa'),
        'tarifa_motos' => Avaluos::TARIFA_MOTOS,
    ],
]);
