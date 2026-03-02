# backend/Dockerfile
FROM php:8.2-apache

# System deps + PHP extensions
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev \
 && docker-php-ext-install pdo pdo_pgsql pgsql \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Set Apache docroot to Laravel /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

# Copy source
COPY . .

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PHP deps + optimize
RUN composer install --no-dev --optimize-autoloader \
 && php artisan config:clear \
 && php artisan route:clear \
 && php artisan view:clear

# Permissions (Laravel needs storage + cache writable)
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache