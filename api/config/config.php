<?php
/**
 * EnjoyFun 2.0 — Application Configuration
 */

// Environment (development | production)
define('APP_ENV',    getenv('APP_ENV')    ?: 'development');
define('APP_NAME',   'EnjoyFun');
define('APP_VERSION','2.0.0');
define('APP_URL',    getenv('APP_URL')    ?: 'http://localhost:8080');

// JWT
define('JWT_SECRET',    getenv('JWT_SECRET')    ?: 'change-me-in-production-super-secret-key-32chars!');
define('JWT_EXPIRY',    getenv('JWT_EXPIRY')    ?: 3600);        // Access token: 1 hour
define('JWT_REFRESH',   getenv('JWT_REFRESH')   ?: 2592000);    // Refresh token: 30 days

// Database
define('DB_HOST',   getenv('DB_HOST')   ?: '127.0.0.1');
define('DB_PORT',   getenv('DB_PORT')   ?: '5432');   // PostgreSQL default port
define('DB_NAME',   getenv('DB_NAME')   ?: 'enjoyfun');
define('DB_USER',   getenv('DB_USER')   ?: 'postgres'); // PostgreSQL default user
define('DB_PASS',   getenv('DB_PASS')   ?: '');

// WhatsApp (Evolution API compatible)
define('WA_API_URL',   getenv('WA_API_URL')   ?: '');
define('WA_API_KEY',   getenv('WA_API_KEY')   ?: '');
define('WA_INSTANCE',  getenv('WA_INSTANCE')  ?: '');

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// CORS Allowed origins
define('CORS_ORIGINS', getenv('CORS_ORIGINS') ?: '*');

// Storage paths
define('STORAGE_PATH', __DIR__ . '/../storage');
define('UPLOAD_PATH',  STORAGE_PATH . '/uploads');
define('QR_PATH',      STORAGE_PATH . '/qrcodes');

// Error reporting
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
