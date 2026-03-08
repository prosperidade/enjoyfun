<?php
require_once __DIR__ . '/backend/src/Database/Database.php';

try {
    $db = Database::getInstance();
    $email = 'admin@enjoyfun.com.br';
    $hash = '$2y$12$2MdcRM.ayP6/5U.KZ6XvruQ0ULHutKo/CZG6n61zuCtLKcPnXlowW';

    // Verify user exists first
    $stmt = $db->prepare('SELECT id, password FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Update password
        $updateStmt = $db->prepare('UPDATE users SET password = ? WHERE email = ?');
        $success = $updateStmt->execute([$hash, $email]);

        if ($success) {
            echo "Successfully updated password for $email to '123456'.\n";
            echo "Old hash: " . $user['password'] . "\n";
            echo "New hash: " . $hash . "\n";
        } else {
            echo "Failed to update password for $email.\n";
        }
    } else {
        echo "User $email not found in database.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
