FROM php:8.3-apache-bookworm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd; \
    a2enmod rewrite headers; \
    printf '%s\n' \
        '<Directory /var/www/html>' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/kuaiz-cms.conf; \
    printf '%s\n' 'ServerName localhost' > /etc/apache2/conf-available/server-name.conf; \
    printf '%s\n' 'disable_functions=sodium_crypto_sign_verify_detached' \
        > /usr/local/etc/php/conf.d/kuaiz-no-sodium-verifier.ini; \
    a2enconf kuaiz-cms; \
    a2enconf server-name; \
    php -r "exit(\
        in_array('sqlite', PDO::getAvailableDrivers(), true)\
        && extension_loaded('fileinfo')\
        && extension_loaded('gd')\
        && !function_exists('sodium_crypto_sign_verify_detached')\
        && function_exists('imagewebp') ? 0 : 1\
    );"; \
    rm -rf /var/lib/apt/lists/*
