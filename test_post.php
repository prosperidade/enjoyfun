<?php
$data = ['event_id' => 1, 'name' => 'Água Premium 2026', 'price' => 6.50, 'stock_qty' => 100];
$options = [
    'http' => [
        'header'  => "Content-type: application/json\r\nAuthorization: Bearer test_mock\r\n",
        'method'  => 'POST',
        'content' => json_encode($data),
        'ignore_errors' => true
    ]
];
$context  = stream_context_create($options);
$result = file_get_contents('http://localhost:8080/api/bar/products', false, $context);

echo "HTTP Headers:\n";
print_r($http_response_header);
echo "\nBody:\n";
echo $result;
echo "\n";
