# 1. Usa PHP con Apache
FROM php:8.2-apache

# 2. Instalar extensiones necesarias (INCLUYENDO POSTGRESQL)
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    && docker-php-ext-install \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    mysqli \
    zip

# 3. Instalar Composer (si usas dependencias)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. Habilitar mod_rewrite de Apache
RUN a2enmod rewrite

# 5. Copiar todos los archivos al directorio web
COPY . /var/www/html/

# 6. Configurar permisos para carpetas como uploads
RUN chown -R www-data:www-data /var/www/html/uploads 2>/dev/null || true \
    && chmod -R 755 /var/www/html/uploads 2>/dev/null || true

# 7. Instalar dependencias de Composer (si tienes composer.json)
RUN cd /var/www/html && composer install --no-dev --optimize-autoloader 2>/dev/null || true

# 8. Exponer el puerto 80
EXPOSE 80