#!/bin/sh
# ==============================================================================
# EnjoyFun — Container Entrypoint
# ==============================================================================
# Starts PHP-FPM (background) + Nginx (foreground).
# Migrations are NOT applied automatically — run them manually.
# ==============================================================================

set -e

echo "[entrypoint] Starting PHP-FPM..."
php-fpm -D

echo "[entrypoint] Starting Nginx (foreground)..."
exec nginx -g 'daemon off;'
