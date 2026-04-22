FROM php:8.2-apache

# Instalar extensiones...
RUN apt-get update && apt-get install -y \
        libpq-dev \
        libzip-dev \
        zip \
        unzip \
        git \
        curl \
    && docker-php-ext-install pdo pdo_mysql mysqli zip

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Habilitar mod_rewrite
RUN a2enmod rewrite

# Copiar archivos
COPY . /var/www/html/

# Instalar dependencias de Composer
RUN cd /var/www/html && composer install --no-dev --optimize-autoloader

# Permisos para uploads
RUN chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 755 /var/www/html/uploads

EXPOSE 80