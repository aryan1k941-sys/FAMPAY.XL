
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite
WORKDIR /var/www/html
COPY . /var/www/html/
RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/data
EXPOSE 80
