<?php
require 'src/Helpers/JWT.php';

try {
    echo "Lendo chaves...\n";
    $priv = JWT::getPrivateKey();
    $pub = JWT::getPublicKey();
    echo "Priv length: " . strlen($priv) . "\n";
    echo "Pub length: " . strlen($pub) . "\n";

    echo "Gerando token...\n";
    $t = JWT::encode(['sub' => 1]);
    echo "Token criado: " . substr($t, 0, 20) . "...\n";

    echo "Decodificando token...\n";
    $dec = JWT::decode($t);
    echo "Resultado do decode:\n";
    print_r($dec);

} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
