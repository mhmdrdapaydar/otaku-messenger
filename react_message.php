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
    try {
        $pdo->query('SELECT 1 FROM private_message_reactions LIMIT 0');
    } catch (PDOException $error) {
        if ($error->getCode() === '42S02') {
            http_response_code(503);
            echo json_encode(['success' => false, 'error' => 'برای واکنش خصوصی، migration مربوطه باید روی دیتابیس اجرا شود.']);
            exit;
        }
        throw $error;
    }
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
    // The UI represents one reaction per member/message; replace an earlier choice.
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM {$table} WHERE message_id = ? AND user_id = ?")
            ->execute([$message_id, $user_id]);
        $pdo->prepare("INSERT INTO {$table} (message_id, user_id, emoji) VALUES (?, ?, ?)")
            ->execute([$message_id, $user_id, $emoji]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
} else {
    // Clear stale duplicate reactions too, e.g. rows created before the one-reaction rule.
    $pdo->prepare("DELETE FROM {$table} WHERE message_id = ? AND user_id = ?")
        ->execute([$message_id, $user_id]);
}
echo json_encode(['success' => true]);
