<?php
require 'src/Helpers/JWT.php';

try {
    echo "Gerando token usando HS256 (Nativo)...\n";
    $t = JWT::encode(['sub' => 1, 'role' => 'admin']);
    echo "Token criado: " . substr($t, 0, 40) . "...\n";

    echo "Decodificando token...\n";
    $dec = JWT::decode($t);
    echo "Resultado do decode:\n";
    print_r($dec);

} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
