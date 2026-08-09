FROM litespeedtech/openlitespeed:1.8.2-lsphp83

RUN set -eux; \
    export DEBIAN_FRONTEND=noninteractive; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        -o Dpkg::Options::="--force-confold" \
        lsphp83-sqlite3; \
    /usr/local/lsws/lsphp83/bin/php -r "exit(\
        in_array('sqlite', PDO::getAvailableDrivers(), true)\
        && extension_loaded('fileinfo')\
        && extension_loaded('gd')\
        && extension_loaded('sodium')\
        && function_exists('imagewebp') ? 0 : 1\
    );"; \
    rm -rf /var/lib/apt/lists/*
