<?php
// Configure these values in the hosting environment; never commit production credentials.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

// Test-host defaults retained from the original project configuration.
// Override these with environment variables when deploying to another server.
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'bzhpbuxh_Otaku_db';
$username = getenv('DB_USER') ?: 'bzhpbuxh_Oraku_admin_db';
$password = getenv('DB_PASSWORD') ?: 'u1a9$#$JByi5O+#1';

if ($dbname === '' || $username === '') {
    http_response_code(500);
    exit('Database configuration is missing. Set DB_HOST, DB_NAME, DB_USER, and DB_PASSWORD.');
}

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Database connection failed. Check the server logs and database settings.');
}
?>
