<?php
// Script de teste local para contornar o Frontend
require_once dirname(__DIR__) . '/config/Database.php';
require_once dirname(__DIR__) . '/src/Helpers/JWT.php';

// Mock token
$secret  = getenv('JWT_SECRET') ?: 'change-me-in-production!';
$token = JWT::encode([
    'sub'   => 1,
    'name'  => 'Admin',
    'email' => 'admin@enjoyfun.com',
    'roles' => ['admin']
], $secret, 3600);

echo "Token gerado: " . $token . "\n\n";

// Simula a requisição HTTP local
$ch = curl_init('http://localhost:8080/api/events');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $token,
    "Accept: application/json"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "--- HTTP CODE: $httpcode ---\n";
echo $response;
curl_close($ch);
