<?php
/**
 * Consulta de datos del vehículo (solo servidor; requiere curl y gd).
 *
 * Flujo:
 *   1. GET  captcha (libre-captcha)  -> { id, imagen(base64) }
 *   2. CapSolver ImageToTextTask     -> texto (minúsculas) + CaptchaCase -> mayúsculas
 *   3. POST auth                     -> infoVehiculo
 *
 * Prueba desde la terminal:  php privado/cli.php <PLACA> <DOCUMENTO> [TIPO]
 */

declare(strict_types=1);

require_once __DIR__ . '/CaptchaCase.php';

class ConsultaVehiculo
{
    const API_BASE     = 'https://runtproapi.runt.gov.co/CYRConsultaVehiculoMS';
    const ORIGEN       = 'https://portalpublico.runt.gov.co';
    const MAX_INTENTOS = 3;

    /** @var resource|CurlShareHandle cookies compartidas en memoria entre peticiones */
    private $cookies;
    private string $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36';

    /**
     * @param string|null $proxy  p. ej. 'http://usuario:clave@host:puerto' (solo para la fuente, no para CapSolver)
     */
    public function __construct(private string $capsolverKey, private int $timeout = 30, private bool $debug = false,
                                private ?string $proxy = null)
    {
        $this->cookies = curl_share_init();
        curl_share_setopt($this->cookies, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
    }

    private function request(string $method, string $url, ?array $json = null): array
    {
        $headers = [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: es-419,es;q=0.9',
            'Origin: ' . self::ORIGEN,
            'Referer: ' . self::ORIGEN . '/',
            'X-Funcionalidad: SHELL-PUBLIC',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-site',
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERAGENT      => $this->ua,
            CURLOPT_COOKIEFILE     => '',   // activa cookies en memoria
            CURLOPT_SHARE          => $this->cookies,
            CURLOPT_ENCODING       => '',
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        if ($this->proxy) curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        if ($body === false) {
            throw new RuntimeException("cURL error en $url: $err");
        }
        return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
    }

    /** Diagnóstico: intenta pedir un captcha y resolverlo, sin consultar ningún vehículo. */
    public function probar(): array
    {
        $out = [];
        $t = microtime(true);
        try {
            [, $img] = $this->obtenerCaptcha();
            $out['fuente'] = ['ok' => true, 'ms' => (int) ((microtime(true) - $t) * 1000)];
        } catch (Throwable $e) {
            $out['fuente'] = ['ok' => false, 'ms' => (int) ((microtime(true) - $t) * 1000), 'error' => $e->getMessage()];
            return $out;
        }
        try {
            $texto = (new CaptchaCase(base64_decode($img)))->corregir($this->resolverCaptcha($img));
            $out['captcha'] = ['ok' => true, 'texto' => $texto];
        } catch (Throwable $e) {
            $out['captcha'] = ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
        }
        return $out;
    }

    /** Obtiene captcha: devuelve [id, base64 sin prefijo data:] */
    private function obtenerCaptcha(): array
    {
        $r = $this->request('GET', self::API_BASE . '/captcha/libre-captcha/generar');
        if ($r['code'] !== 200 || !is_array($r['json'])) {
            throw new RuntimeException("Captcha HTTP {$r['code']}: " . substr($r['body'], 0, 300));
        }
        $j = $r['json'];
        $id  = $j['id'] ?? $j['idLibreCaptcha'] ?? $j['uuid'] ?? null;
        $img = $j['imagen'] ?? $j['image'] ?? $j['img'] ?? $j['captcha'] ?? null;
        if (!$id || !$img) {
            throw new RuntimeException('Respuesta de captcha inesperada: ' . substr($r['body'], 0, 300));
        }
        return [$id, preg_replace('#^data:image/[^;]+;base64,#', '', $img)];
    }

    /** Resuelve imagen con CapSolver (ImageToTextTask) */
    private function resolverCaptcha(string $base64): string
    {
        $ch = curl_init('https://api.capsolver.com/createTask');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'clientKey' => $this->capsolverKey,
                'task' => ['type' => 'ImageToTextTask', 'body' => $base64, 'module' => 'common'],
            ]),
            CURLOPT_TIMEOUT        => 60,
        ]);
        $res = json_decode((string) curl_exec($ch), true);

        if (($res['errorId'] ?? 1) !== 0) {
            throw new RuntimeException('CapSolver: ' . ($res['errorDescription'] ?? json_encode($res)));
        }
        $texto = $res['solution']['text'] ?? null;
        if (!$texto) {
            throw new RuntimeException('CapSolver sin texto: ' . json_encode($res));
        }
        return $texto;
    }

    /**
     * Devuelve la respuesta completa (con infoVehiculo) o, si la fuente responde
     * con un error de negocio (vehículo/documento no coinciden), ['error' => true, 'descripcionRespuesta' => ...].
     * Lanza RuntimeException ante fallos técnicos.
     */
    public function consultar(string $placa, string $documento, string $tipoDoc = 'C'): array
    {
        $ultimo = null;
        for ($i = 1; $i <= self::MAX_INTENTOS; $i++) {
            [$idCaptcha, $img] = $this->obtenerCaptcha();
            // CapSolver devuelve todo en minúsculas; el captcha distingue mayúsculas
            $texto = (new CaptchaCase(base64_decode($img)))->corregir($this->resolverCaptcha($img));
            if ($this->debug) {
                $f = sys_get_temp_dir() . "/cv_captcha_$i.png";
                file_put_contents($f, base64_decode($img));
                fwrite(STDERR, "[intento $i] captcha=$texto imagen=$f\n");
            }

            $r = $this->request('POST', self::API_BASE . '/auth', [
                'procedencia'           => 'NACIONAL',
                'tipoConsulta'          => '1',
                'placa'                 => strtoupper($placa),
                'tipoDocumento'         => $tipoDoc,
                'documento'             => $documento,
                'vin'                   => null,
                'soat'                  => null,
                'aseguradora'           => '',
                'rtm'                   => null,
                'reCaptcha'             => null,
                'captcha'               => $texto,
                'valueCaptchaEncripted' => '',
                'idLibreCaptcha'        => $idCaptcha,
                'verBannerSoat'         => true,
                'configuracion'         => ['tiempoInactividad' => '900', 'tiempoCuentaRegresiva' => '10'],
            ]);
            $j = $r['json'];

            if ($r['code'] === 200 && is_array($j) && empty($j['error']) && !empty($j['infoVehiculo'])) {
                return $j;
            }
            $ultimo = (string) ($j['mensajeError'] ?? $j['descripcionRespuesta'] ?? substr($r['body'], 0, 300));
            // Error que no es del captcha (p. ej. documento no coincide): no reintentar
            if (stripos($ultimo, 'captcha') === false && is_array($j)) {
                return ['error' => true, 'descripcionRespuesta' => $ultimo];
            }
        }
        throw new RuntimeException("Captcha rechazado " . self::MAX_INTENTOS . " veces: $ultimo");
    }
}
