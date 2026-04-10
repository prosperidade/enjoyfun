# ==============================================================================
# EnjoyFun Platform — Unified Multi-Stage Dockerfile
# ==============================================================================
# Single-container build: Frontend (Vite) + Backend (PHP-FPM) + Nginx
# PostgreSQL is external (not included).
#
# Build:  docker build -t enjoyfun:latest .
# Run:    docker run -d --env-file backend/.env -p 80:80 enjoyfun:latest
# ==============================================================================

# --------------- Stage 1: Frontend Build --------------------------------------
FROM node:20-alpine AS frontend-build

WORKDIR /app/frontend

# Copy dependency manifests first for layer caching
COPY frontend/package.json frontend/package-lock.json* ./

RUN npm ci

# Copy source and build
COPY frontend/ ./
RUN npm run build

# --------------- Stage 2: Production -----------------------------------------
FROM php:8.2-fpm-alpine AS production

# Install runtime dependencies + PHP extensions
RUN apk add --no-cache \
        nginx \
        postgresql-dev \
        icu-dev \
        oniguruma-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        mbstring \
        opcache \
        intl

# Production PHP configuration (opcache + hardening)
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

# Configure PHP-FPM to listen on 127.0.0.1:9000 (container-local)
RUN sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' /usr/local/etc/php-fpm.d/zz-docker.conf

# Nginx configuration for unified container
# Frontend: static files served directly
# Backend: FastCGI to local PHP-FPM
RUN mkdir -p /etc/nginx/http.d && cat > /etc/nginx/http.d/default.conf <<'NGINX'
# Rate limiting zones
limit_req_zone $binary_remote_addr zone=api_limit:10m rate=30r/s;
limit_req_zone $binary_remote_addr zone=auth_limit:10m rate=5r/s;

server {
    listen 80;
    server_name _;

    client_max_body_size 25m;

    # Gzip
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_min_length 256;
    gzip_types text/plain text/css text/javascript application/json
               application/javascript application/xml image/svg+xml font/woff2;

    # Security headers
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;

    # ------ API routes -> PHP-FPM ------
    location /api/ {
        limit_req zone=api_limit burst=20 nodelay;
        limit_req_status 429;

        root /var/www/html/backend/public;

        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/html/backend/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param REQUEST_URI $request_uri;
        fastcgi_param DOCUMENT_ROOT /var/www/html/backend/public;

        fastcgi_connect_timeout 10s;
        fastcgi_send_timeout 60s;
        fastcgi_read_timeout 60s;
        fastcgi_buffer_size 32k;
        fastcgi_buffers 16 16k;
    }

    # Stricter rate limit for auth
    location /api/auth/ {
        limit_req zone=auth_limit burst=5 nodelay;
        limit_req_status 429;

        root /var/www/html/backend/public;

        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/html/backend/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param REQUEST_URI $request_uri;
        fastcgi_param DOCUMENT_ROOT /var/www/html/backend/public;

        fastcgi_connect_timeout 10s;
        fastcgi_send_timeout 30s;
        fastcgi_read_timeout 30s;
    }

    # Health check (no rate limit)
    location = /api/health {
        fastcgi_pass 127.0.0.1:9000;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/html/backend/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param REQUEST_URI $request_uri;
        fastcgi_param DOCUMENT_ROOT /var/www/html/backend/public;
    }

    # ------ Frontend (static SPA) ------
    root /var/www/html/frontend/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;

        add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-Frame-Options "DENY" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    }

    # Cache static assets aggressively
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|json)$ {
        root /var/www/html/frontend/dist;
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # Block hidden files
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }
}
NGINX

# Copy backend source
COPY backend/ /var/www/html/backend/

# Copy frontend build from stage 1
COPY --from=frontend-build /app/frontend/dist /var/www/html/frontend/dist

# Copy database migrations (reference only, NOT auto-applied)
COPY database/ /var/www/html/database/

# Create uploads directory and fix permissions
RUN mkdir -p /var/www/html/backend/public/uploads \
    && mkdir -p /var/log/nginx \
    && mkdir -p /run/nginx \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/backend/public

# Remove .env if accidentally copied (defense in depth)
RUN rm -f /var/www/html/backend/.env

# Entrypoint
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=10s \
    CMD wget -qO /dev/null http://127.0.0.1/api/health || exit 1

CMD ["/docker-entrypoint.sh"]
