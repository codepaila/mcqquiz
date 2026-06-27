FROM php:8.3-apache

# Install required PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

RUN docker-php-ext-install opcache

# Enable Apache rewrite module
RUN a2enmod rewrite

# Allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copy project files
COPY . /var/www/html/

# Set file ownership
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80