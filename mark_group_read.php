<?php
// mark_group_read.php
require_once 'config.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}
$user_id = $_SESSION['user_id'];
$group_id = (int)($_POST['group_id'] ?? 0);

if ($group_id <= 0) {
    echo json_encode(['success' => false]);
    exit;
}

// دریافت آخرین پیام گروه
$lastMsgId = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM group_channel_messages WHERE group_id = ?");
$lastMsgId->execute([$group_id]);
$maxId = $lastMsgId->fetchColumn();

// به‌روزرسانی آخرین پیام خوانده‌شده برای کاربر در جدول عضویت
$pdo->prepare("UPDATE group_channel_members 
               SET last_read_message_id = ? 
               WHERE group_id = ? AND user_id = ?")
    ->execute([$maxId, $group_id, $user_id]);

// ثبت خوانده‌شدن همه‌ی پیام‌های خوانده‌نشده در message_read_status
$unread = $pdo->prepare("SELECT m.id FROM group_channel_messages m
                         LEFT JOIN message_read_status r ON m.id = r.message_id AND r.user_id = ?
                         WHERE m.group_id = ? AND m.deleted_at IS NULL
                           AND r.message_id IS NULL");
$unread->execute([$user_id, $group_id]);
$insert = $pdo->prepare("INSERT IGNORE INTO message_read_status (message_id, user_id) VALUES (?, ?)");
while ($msgId = $unread->fetchColumn()) {
    $insert->execute([$msgId, $user_id]);
}

echo json_encode(['success' => true]);