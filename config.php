<?php
session_start();

$host = 'localhost';
$dbname = 'bzhpbuxh_Otaku_db';
$username = 'bzhpbuxh_Oraku_admin_db';
$password = 'u1a9$#$JByi5O+#1';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("❌ خطا در اتصال به دیتابیس: " . $e->getMessage());
}
?>