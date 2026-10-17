<?php
/**
 * Configuración de la consulta por placa.
 */
return [
    // Clave de CapSolver para resolver el captcha.
    'capsolver_key' => getenv('CAPSOLVER_KEY') ?: 'CAP-D901B1BB40623924A9DED85DE5CBA85B81E6A83CC7E55C4091C86BD595C0361A',

    // Proxy para llegar a la fuente si el servidor está fuera de Colombia y lo bloquean.
    // Formato: 'http://usuario:clave@host:puerto'. null = conexión directa.
    // También se puede definir con la variable de entorno CV_PROXY.
    'proxy' => getenv('CV_PROXY') ?: null,

    // Clave para abrir consulta.php?accion=diagnostico&clave=... (null = desactivado).
    // Actívalo solo mientras revisas un servidor nuevo y vuelve a ponerlo en null.
    'clave_diagnostico' => getenv('CV_DIAGNOSTICO') ?: null,

    // Tiempo máximo de espera por petición (segundos).
    'timeout' => 30,

    // Consultas permitidas por IP por minuto.
    'rate_limit_por_minuto' => 10,

    // true = no consulta la fuente; responde con privado/vehiculo-demo.json.
    // Útil para probar la página sin gastar captchas.
    'modo_demo' => false,

    // Tabla de avalúos del año fiscal (generada con herramientas/generar_datos.py).
    'avaluos' => __DIR__ . '/avaluos-2026.php',

    // Tipos de documento (código => nombre que ve el usuario).
    'tipos_documento' => [
        'C' => 'Cédula de ciudadanía',
        'E' => 'Cédula de extranjería',
        'N' => 'NIT',
        'P' => 'Pasaporte',
    ],
];
