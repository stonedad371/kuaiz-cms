FROM php:7.4-apache-bullseye

RUN set -eux; \
    apt-get -o Acquire::Retries=5 update; \
    apt-get -o Acquire::Retries=5 install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libsqlite3-dev \
        libwebp-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd pdo_sqlite; \
    a2enmod rewrite headers; \
    printf '%s\n' \
        '<Directory /var/www/html>' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/kuaiz-cms.conf; \
    printf '%s\n' 'ServerName localhost' > /etc/apache2/conf-available/server-name.conf; \
    a2enconf kuaiz-cms; \
    a2enconf server-name; \
    php -r "exit(\
        PHP_VERSION_ID >= 70400 && PHP_VERSION_ID < 80000\
        && in_array('sqlite', PDO::getAvailableDrivers(), true)\
        && extension_loaded('fileinfo')\
        && extension_loaded('gd')\
        && function_exists('imagewebp') ? 0 : 1\
    );"; \
    rm -rf /var/lib/apt/lists/*
