<?php

$publicDir = __DIR__ . DIRECTORY_SEPARATOR . 'public';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $requestPath);
$candidate = realpath($publicDir . $normalizedPath);

if ($candidate !== false && str_starts_with($candidate, realpath($publicDir)) && is_file($candidate)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publicDir . DIRECTORY_SEPARATOR . 'index.php';

require $publicDir . DIRECTORY_SEPARATOR . 'index.php';
