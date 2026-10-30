<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'ابتدا وارد حساب شوید.']);
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$message_id = (int)($_POST['message_id'] ?? 0);
$emoji = $_POST['emoji'] ?? '';
$action = $_POST['action'] ?? 'add';
$type = $_POST['type'] ?? 'group';
$allowedEmojis = ['👍', '❤️', '😂', '😢', '😡'];
if ($message_id < 1 || !in_array($emoji, $allowedEmojis, true) || !in_array($action, ['add', 'remove'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'درخواست واکنش نامعتبر است.']);
    exit;
}

if ($type === 'private') {
    $message = $pdo->prepare("SELECT sender_id, receiver_id FROM private_messages WHERE id = ? AND deleted_at IS NULL");
    $message->execute([$message_id]);
    $row = $message->fetch();
    if (!$row || ($row['sender_id'] != $user_id && $row['receiver_id'] != $user_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'به این پیام دسترسی ندارید.']);
        exit;
    }
    $table = 'private_message_reactions';
} elseif ($type === 'group') {
    $message = $pdo->prepare("SELECT m.group_id FROM group_channel_messages m
                              JOIN group_channel_members gcm ON gcm.group_id = m.group_id AND gcm.user_id = ?
                              WHERE m.id = ? AND m.deleted_at IS NULL");
    $message->execute([$user_id, $message_id]);
    $row = $message->fetch();
    if (!$row) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'پیام یافت نشد یا عضو گروه نیستید.']);
        exit;
    }
    $table = 'message_reactions';
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'نوع گفتگو نامعتبر است.']);
    exit;
}

if ($action === 'add') {
    $pdo->prepare("INSERT IGNORE INTO {$table} (message_id, user_id, emoji) VALUES (?, ?, ?)")
        ->execute([$message_id, $user_id, $emoji]);
} else {
    $pdo->prepare("DELETE FROM {$table} WHERE message_id = ? AND user_id = ? AND emoji = ?")
        ->execute([$message_id, $user_id, $emoji]);
}
echo json_encode(['success' => true]);
