FROM php:8.3-apache

# ZIP, BZ2 i program 7z (dla archiwów .7z); zlib i iconv są już w obrazie
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libbz2-dev p7zip-full \
    && docker-php-ext-install zip bz2 \
    && rm -rf /var/lib/apt/lists/*

ENV LANG=C.UTF-8 LC_ALL=C.UTF-8 \
    UNPACK_WORK_DIR=/var/lib/rozpakowywarka

RUN mkdir -p "$UNPACK_WORK_DIR" && chown www-data:www-data "$UNPACK_WORK_DIR"

COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY index.php /var/www/html/index.php

EXPOSE 80
