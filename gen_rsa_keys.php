<?php
// Guard rail para garantir que a extensão está ativa
if (!extension_loaded('openssl')) {
    die("OpenSSL extension is required to generate RSA keys.\n");
}

// Configurações da chave
$configArgs = [
    "digest_alg" => "sha256",
    "private_key_bits" => 2048,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
];

// O Pulo do Gato para o Windows: Apontar onde está o openssl.cnf
$winConfigPath = 'C:\php\extras\ssl\openssl.cnf';
if (file_exists($winConfigPath)) {
    $configArgs["config"] = $winConfigPath;
}

// Tenta gerar a chave
$res = openssl_pkey_new($configArgs);

// Se falhar, captura o erro interno do OpenSSL e mostra na tela
if ($res === false) {
    echo "Falha ao gerar as chaves. Motivo:\n";
    while ($msg = openssl_error_string()) {
        echo " - " . $msg . "\n";
    }
    die("\nVerifique se o arquivo openssl.cnf existe em C:\\php\\extras\\ssl\\\n");
}

// Exporta a chave privada
openssl_pkey_export($res, $privKey, null, $configArgs);

// Extrai a chave pública
$keyDetails = openssl_pkey_get_details($res);
$pubKey = $keyDetails["key"];

// Salva os arquivos no projeto
file_put_contents(__DIR__ . '/private_key.pem', $privKey);
file_put_contents(__DIR__ . '/public_key.pem', $pubKey);

echo "Sucesso! Chaves RS256 geradas:\n";
echo " - private_key.pem\n";
echo " - public_key.pem\n";