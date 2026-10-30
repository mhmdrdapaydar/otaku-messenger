<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }
$user_id = $_SESSION['user_id'];
$timeout_seconds = 120; // 2 دقیقه

// 1. به‌روزرسانی کاربر فعلی
$stmt = $pdo->prepare("UPDATE users SET is_online = 1, last_seen = NOW() WHERE id = ?");
$stmt->execute([$user_id]);

// 2. آفلاین کردن کاربرانی که بیش از ۲ دقیقه heartbeat نداده‌اند
$stmt = $pdo->prepare("UPDATE users SET is_online = 0 WHERE is_online = 1 AND last_seen < DATE_SUB(NOW(), INTERVAL ? SECOND)");
$stmt->execute([$timeout_seconds]);

echo json_encode(['success' => true]);