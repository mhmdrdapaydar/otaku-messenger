<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) exit;
$user_id = $_SESSION['user_id'];
$target_id = (int)$_POST['target_id'];
$action = $_POST['action'] ?? 'block'; // block / unblock

if ($action === 'block') {
    $pdo->prepare("INSERT IGNORE INTO blocked_users (blocker_id, blocked_id) VALUES (?, ?)")->execute([$user_id, $target_id]);
} else {
    $pdo->prepare("DELETE FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?")->execute([$user_id, $target_id]);
}
echo json_encode(['success'=>true]);