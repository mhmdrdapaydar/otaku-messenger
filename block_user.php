<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'ابتدا وارد حساب شوید.']);
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$target_id = (int)($_POST['target_id'] ?? 0);
$action = $_POST['action'] ?? '';
if ($target_id < 1 || $target_id === $user_id || !in_array($action, ['block', 'unblock'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'درخواست نامعتبر است.']);
    exit;
}

if ($action === 'block') {
    $exists = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
    $exists->execute([$target_id]);
    if (!$exists->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'کاربر پیدا نشد.']);
        exit;
    }
    $pdo->prepare('INSERT IGNORE INTO blocked_users (blocker_id, blocked_id) VALUES (?, ?)')
        ->execute([$user_id, $target_id]);
} else {
    $pdo->prepare('DELETE FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?')
        ->execute([$user_id, $target_id]);
}
echo json_encode(['success' => true]);
