FROM php:8.3-apache
RUN docker-php-ext-install pdo_sqlite
RUN a2enmod rewrite
WORKDIR /var/www/html
COPY . /var/www/html/
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html/data
EXPOSE 80
