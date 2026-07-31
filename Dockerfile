# Use the official PHP Apache image
FROM php:8.2-apache

# Install CA Certificates and PDO MySQL extensions
RUN apt-get update && apt-get install -y ca-certificates
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy all files into the server's public folder
COPY . /var/www/html/

# Set the correct permissions
RUN chown -R www-data:www-data /var/www/html
