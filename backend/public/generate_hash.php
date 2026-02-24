<?php
ini_set('display_errors', '0');
if (ob_get_length()) ob_clean();

$hash = password_hash('12345678', PASSWORD_BCRYPT, ['cost' => 12]);
echo "O Hash Real de 12345678 no PHP é:\n";
echo $hash;
