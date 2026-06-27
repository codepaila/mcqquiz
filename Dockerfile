FROM php:8.3-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    zip \
    curl \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    mysqli \
    pdo \
    pdo_mysql \
    opcache

# Enable Apache modules
RUN a2enmod rewrite headers expires deflate

# Allow .htaccess
RUN sed -ri \
    -e 's!/var/www/!/var/www/html!g' \
    -e 's/AllowOverride None/AllowOverride All/g' \
    /etc/apache2/apache2.conf

# PHP production configuration
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# OPcache configuration
RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.interned_strings_buffer=16"; \
    echo "opcache.max_accelerated_files=10000"; \
    echo "opcache.validate_timestamps=0"; \
    echo "opcache.revalidate_freq=0"; \
    echo "opcache.fast_shutdown=1"; \
} > /usr/local/etc/php/conf.d/opcache.ini

# Copy application
COPY . /var/www/html/

# Permissions
RUN chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html

EXPOSE 80