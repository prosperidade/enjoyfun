<?php
$hash = '$2y$10$8K9p/vP6pX1z6pQv5U7vOe6z5Q6z5Q6z5Q6z5Q6z5Q6z5Q6z5Q6z5';
$password = '12345678';
$isValid = password_verify($password, $hash);

header('Content-Type: application/json');
echo json_encode([
    'hash' => $hash,
    'password' => $password,
    'is_valid' => $isValid,
    'info' => password_get_info($hash)
]);
