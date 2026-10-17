# Impuesto Vehicular por Placa

La persona escribe la placa y el documento del propietario. El servidor:
1. consulta los datos del vehículo (`privado/ConsultaVehiculo.php`, captcha con CapSolver),
2. busca su avalúo en las 9 tablas oficiales 2026 (Resolución 20253040048935 de 2025),
3. calcula el impuesto 2026 y devuelve solo el resultado de ese vehículo.

## Estructura

```
├── index.html            ← la página
├── app.js                ← solo pinta el resultado (no tiene la tabla ni el cálculo)
├── estilos.css
├── consulta.php          ← único punto de entrada de la página
├── politica-datos.html   ← plantilla Ley 1581 (complétala)
├── lib/departamentos.js  ← organismo de tránsito → departamento
├── .htaccess             ← sin listado de carpetas; bloquea .md, .py, .json, herramientas/
├── privado/              ← bloqueada por .htaccess: nada de aquí se sirve por URL
│   ├── config.php            ← clave CapSolver, límite por IP, modo demo, tipos de documento
│   ├── ConsultaVehiculo.php  ← consulta + captcha
│   ├── CaptchaCase.php       ← corrige mayúsculas/minúsculas del captcha
│   ├── Avaluos.php           ← búsqueda en la tabla y cálculo del impuesto
│   ├── avaluos-2026.php      ← 15.416 líneas de las 9 tablas oficiales
│   ├── vehiculo-demo.json    ← respuesta de ejemplo para el modo demo
│   ├── cli.php               ← prueba desde la terminal
│   └── tmp/                  ← contador del límite por IP (Apache debe poder escribir)
└── herramientas/generar_datos.py
```

Requisitos: PHP 8.0+ con `curl`, `gd` y `mbstring`. Apache con `AllowOverride All`
(para que funcionen los `.htaccess`). Con nginx hay que bloquear `privado/` y `herramientas/`
en la configuración del servidor.

## Probar

- Desde la terminal: `php privado/cli.php <PLACA> <DOCUMENTO> [C|E|N|P]`
  (`CV_DEBUG=1` guarda las imágenes del captcha).
- Sin gastar captchas: en `privado/config.php` pon `'modo_demo' => true`.

## Qué ve el navegador

`consulta.php` devuelve solo: datos generales del vehículo, documento enmascarado (`C ***857`),
avalúo, tarifa e impuesto. Nunca la tabla de avalúos, motor, chasis, serie, VIN, licencia ni token.
Los mensajes de error son genéricos; el detalle queda en el log de PHP.

## Cómo encuentra el avalúo

Siempre elige la versión exacta o la más parecida de la tabla oficial (misma marca):
palabras de la línea en común (también parciales: "CX30" ~ "CX-30"), cilindraje cercano,
la tabla que corresponde a la clase (CAMIONETA → camionetas y camperos, MOTOCICLETA → motos…),
combustible eléctrico/híbrido, carrocería COUPE y versiones TAXI solo para servicio público.
Si hay empate, prefiere la que tiene avalúo para el año modelo. Solo queda sin resultado si la
marca no está en la tabla.

## Reglas del cálculo

- Particulares: 1,5 % hasta $57.349.000; 2,5 % hasta $129.032.000; 3,5 % por encima (Decreto 1457 de 2025).
- Motos de más de 125 c.c.: 1,5 %. Las de hasta 125 c.c. no pagan.
- Eléctricos: máximo 1 % (Ley 1964 de 2019).
- Antiguos y clásicos: 50 % del avalúo del modelo 2001.
- Servicio público: muestra el avalúo y avisa que el impuesto lo cobra el municipio.
- Modelo 2026: no está en la tabla; se liquida con la factura.

Para cambiar tarifas: `privado/Avaluos.php` → `TARIFAS`.
Para el año siguiente:
`python3 herramientas/generar_datos.py anexo1.pdf ... anexo9.pdf --anio 2027 --fuente "..." --salida privado/avaluos-2027.php`
y cambia `avaluos` en `privado/config.php`.

## Antes de publicar

- Completa `politica-datos.html`.
- Usa HTTPS.
- Publica en la raíz del dominio o en una carpeta con otro nombre (la URL incluye el nombre de la carpeta).
- Da permisos de escritura sobre `privado/tmp/` solo al usuario del servidor web.
- Verifica que las condiciones de uso de la fuente de datos permiten esta consulta.
