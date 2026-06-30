# Use the official PHP image with Apache
FROM php:8.3-apache

# Install system dependencies and PHP extensions if needed
RUN apt-get update && apt-get install -y \
    libpng-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql gd

# Enable Apache mod_rewrite (common for PHP apps/frameworks)
RUN a2enmod rewrite

# Set the working directory
WORKDIR /var/www/html

# Copy your source code (Optional for dev, required for prod)
COPY . .

# Adjust permissions
RUN chown -R www-data:www-data /var/www/html