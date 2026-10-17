# Imagen para Render (o cualquier hosting con Docker).
# Variables de entorno en Render: CAPSOLVER_KEY, y opcionales CV_PROXY y CV_DIAGNOSTICO.
FROM php:8.2-apache

# gd (captcha) y mbstring; curl ya viene en la imagen
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libonig-dev \
 && docker-php-ext-install gd mbstring \
 && rm -rf /var/lib/apt/lists/*

# Permitir .htaccess (bloqueo de privado/, herramientas/, .md, etc.) y escuchar en $PORT de Render
RUN sed -ri 's#AllowOverride None#AllowOverride All#g' /etc/apache2/apache2.conf \
 && sed -ri 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
 && printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/zz-ocultar-version.conf \
 && echo 'expose_php = Off' > /usr/local/etc/php/conf.d/ocultar-version.ini
ENV PORT=10000

COPY . /var/www/html/
RUN rm -rf /var/www/html/.claude \
 && mkdir -p /var/www/html/privado/tmp \
 && chown -R www-data:www-data /var/www/html/privado/tmp

EXPOSE 10000
