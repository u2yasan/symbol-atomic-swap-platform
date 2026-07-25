FROM drupal:11-php8.4-apache@sha256:cf92b0c767af06d6dc356c49c7eee8ecd9752cdde37b3e7fd85fd1f236010358

RUN a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT=/opt/drupal/web

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf && \
    sed -ri -e 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf && \
    sed -ri -e 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/*.conf && \
    install -d -o www-data -g www-data \
      /var/run/apache2 \
      /var/lock/apache2 \
      /var/log/apache2

WORKDIR /opt/drupal

USER www-data

EXPOSE 8080
