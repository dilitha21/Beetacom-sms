# Use the official PHP Apache image
FROM php:8.2-apache

# Install PDO and MySQL extensions so your database connection works
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache rewrite module (standard for PHP apps)
RUN a2enmod rewrite

# Copy all your Beetacom SMS files into the server's public folder
COPY . /var/www/html/

# Set the correct permissions so the server can read the files
RUN chown -R www-data:www-data /var/www/html
