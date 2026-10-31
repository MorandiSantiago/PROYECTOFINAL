FROM php:8.4-apache
RUN docker-php-ext-install pdo_mysql
COPY app/ /var/www/app/
COPY ["proyecto integrador/", "/var/www/html/"]
