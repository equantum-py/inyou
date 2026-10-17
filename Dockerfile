FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers expires \
    && printf '%s\n' 'DirectoryIndex index.php index.html' > /etc/apache2/conf-available/inyou-directory-index.conf \
    && a2enconf inyou-directory-index

WORKDIR /var/www/html
COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html/assets/products \
    && chmod -R 775 /var/www/html/assets/products

ENV PORT=3000

CMD ["sh", "-lc", "sed -ri 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf && sed -ri 's/:80>/:${PORT}>/' /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
