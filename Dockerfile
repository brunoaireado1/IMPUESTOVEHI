FROM php:8.2-apache

# --------------------------------------------------
# Extensiones PHP
# --------------------------------------------------
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# --------------------------------------------------
# Apache: solo rewrite en build. El MPM se corrige en el CMD (ver abajo),
# porque Railway vuelve a activar mpm_event/mpm_worker al arrancar el
# contenedor, así que fijarlo solo en el build no es suficiente.
# --------------------------------------------------
RUN a2enmod rewrite

WORKDIR /var/www/html
COPY . .

# Directorio de sesiones
RUN mkdir -p /var/www/html/sessions \
    && chmod 777 /var/www/html/sessions

# Configuración de la aplicación
RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/app.conf \
    && a2enconf app

# --------------------------------------------------
# Puerto: Railway inyecta PORT en tiempo de EJECUCIÓN, no de build.
# MPM: se corrige aquí también, cada vez que arranca el contenedor,
# porque Railway reactiva mpm_event/mpm_worker al iniciar.
# (Se usa forma JSON para el CMD, como recomienda el linter de Docker.)
# --------------------------------------------------
ENV PORT=8080
EXPOSE 8080

CMD ["bash", "-lc", "set -e; \
    a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true; \
    rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* 2>/dev/null || true; \
    a2enmod mpm_prefork >/dev/null; \
    sed -i \"s/^Listen .*/Listen ${PORT}/\" /etc/apache2/ports.conf; \
    sed -i \"s/<VirtualHost \\*:[0-9]*>/<VirtualHost *:${PORT}>/\" /etc/apache2/sites-available/000-default.conf; \
    apache2ctl -t; \
    exec apache2-foreground"]
