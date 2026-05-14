FROM drupal:11-php8.4-apache@sha256:cf92b0c767af06d6dc356c49c7eee8ecd9752cdde37b3e7fd85fd1f236010358

RUN a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT=/opt/drupal/web

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

WORKDIR /opt/drupal
