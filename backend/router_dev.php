<?php

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicRoot = __DIR__ . '/public';
$targetFile = realpath($publicRoot . $requestPath);

if (
    $requestPath !== '/'
    && $targetFile !== false
    && str_starts_with($targetFile, realpath($publicRoot) ?: $publicRoot)
    && is_file($targetFile)
) {
    return false;
}

require $publicRoot . '/index.php';
