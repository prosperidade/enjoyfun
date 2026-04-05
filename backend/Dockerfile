# ==============================================================================
# EnjoyFun Backend — PHP 8.2 FPM (Alpine)
# ==============================================================================
FROM php:8.2-fpm-alpine AS base

# Install build dependencies and PHP extensions
RUN apk add --no-cache \
        postgresql-dev \
        icu-dev \
        oniguruma-dev \
        linux-headers \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        mbstring \
        opcache \
        intl \
    && apk del --no-cache linux-headers

# Production PHP configuration
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.revalidate_freq=0'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.save_comments=1'; \
    echo 'opcache.fast_shutdown=1'; \
} > /usr/local/etc/php/conf.d/opcache-prod.ini \
&& { \
    echo 'display_errors=Off'; \
    echo 'display_startup_errors=Off'; \
    echo 'log_errors=On'; \
    echo 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_STRICT'; \
    echo 'error_log=/proc/self/fd/2'; \
    echo 'expose_php=Off'; \
    echo 'date.timezone=America/Sao_Paulo'; \
    echo 'upload_max_filesize=20M'; \
    echo 'post_max_size=25M'; \
    echo 'memory_limit=256M'; \
    echo 'max_execution_time=60'; \
} > /usr/local/etc/php/conf.d/production.ini

# Create non-root user
RUN addgroup -g 1000 -S enjoyfun \
    && adduser -u 1000 -S enjoyfun -G enjoyfun

WORKDIR /var/www/backend

# Copy application source
COPY --chown=enjoyfun:enjoyfun . .

# Ensure the public directory is accessible
RUN chmod -R 755 /var/www/backend/public

# Switch to non-root user
USER enjoyfun

EXPOSE 9000

CMD ["php-fpm"]
