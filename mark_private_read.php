<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }
$user_id = $_SESSION['user_id'];
$peer_id = (int)($_POST['user_id'] ?? 0);

// تمام پیام‌های دریافتی از کاربر peer که هنوز خوانده نشده‌اند را علامت بزن
$stmt = $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE receiver_id = ? AND sender_id = ? AND is_read = 0");
$stmt->execute([$user_id, $peer_id]);
echo json_encode(['success' => true]);
?>