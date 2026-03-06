<?php
// Script para gerar as chaves localmente via PHP e salvar em /secrets/
$config = [
    "private_key_bits" => 2048,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
];
$res = openssl_pkey_new($config);
if (!$res) {
    die("Falha ao criar o par de chaves.\n");
}
openssl_pkey_export($res, $privKey);
$pubKey = openssl_pkey_get_details($res);
$pubKey = $pubKey["key"];

$secretsDir = __DIR__ . '/../secrets';
if (!is_dir($secretsDir)) {
    mkdir($secretsDir, 0777, true);
}

file_put_contents($secretsDir . '/jwt_private.pem', $privKey);
file_put_contents($secretsDir . '/jwt_public.pem', $pubKey);

echo "Chaves Geradas com Sucesso em: $secretsDir\n";
