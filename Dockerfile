FROM php:8.3-apache

# Install system packages
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    zip \
    curl \
    ca-certificates \
    && update-ca-certificates \
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
RUN sed -i 's/AllowOverride None/AllowOverride All/g' \
    /etc/apache2/apache2.conf

# Production php.ini
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# OPcache
RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.interned_strings_buffer=16"; \
    echo "opcache.max_accelerated_files=10000"; \
    echo "opcache.validate_timestamps=0"; \
    echo "opcache.revalidate_freq=0"; \
} > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

# Copy application
COPY . .

# Set ownership
RUN chown -R www-data:www-data /var/www/html

# Health check
HEALTHCHECK --interval=30s --timeout=5s \
CMD curl -f http://localhost/ || exit 1

EXPOSE 80