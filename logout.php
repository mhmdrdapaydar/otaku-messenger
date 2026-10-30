<?php
require_once 'config.php';
if (isset($_SESSION['user_id'])) {
    $pdo->prepare("UPDATE users SET is_online = 0, last_seen = NOW() WHERE id = ?")
        ->execute([$_SESSION['user_id']]);
}
session_destroy();
header('Location: login.php');
exit;