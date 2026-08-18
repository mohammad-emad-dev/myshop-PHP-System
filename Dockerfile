FROM php:8.3-apache-bookworm

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mysqli mbstring gd \
    && php -r 'foreach (["mysqli", "fileinfo", "gd", "mbstring"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: {$extension}\n"); exit(1); } }' \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod headers rewrite

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

HEALTHCHECK --interval=10s --timeout=5s --start-period=20s --retries=5 \
    CMD curl --fail --silent --show-error http://127.0.0.1/ >/dev/null || exit 1
