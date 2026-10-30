<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['user_id'])) exit;
$user_id = $_SESSION['user_id'];
$group_id = (int)($_POST['group_id'] ?? 0);

if ($group_id > 0) {
    // ۱. به‌روزرسانی آخرین پیام خوانده‌شده برای کاربر در گروه
    $pdo->prepare("UPDATE group_channel_members 
                   SET last_read_message_id = (SELECT COALESCE(MAX(id), 0) 
                                               FROM group_channel_messages 
                                               WHERE group_id = ?) 
                   WHERE group_id = ? AND user_id = ?")
        ->execute([$group_id, $group_id, $user_id]);

    // ۲. ثبت خوانده‌شدن تمام پیام‌های خوانده‌نشده در message_read_status
    $unread = $pdo->prepare("SELECT id FROM group_channel_messages 
                             WHERE group_id = ? 
                               AND id > (SELECT COALESCE(last_read_message_id, 0) 
                                         FROM group_channel_members 
                                         WHERE group_id = ? AND user_id = ?)");
    $unread->execute([$group_id, $group_id, $user_id]);
    $insert = $pdo->prepare("INSERT IGNORE INTO message_read_status (message_id, user_id) VALUES (?, ?)");
    while ($msg = $unread->fetch(PDO::FETCH_ASSOC)) {
        $insert->execute([$msg['id'], $user_id]);
    }

    // ۳. غیرفعال‌سازی خودکار اعلان‌های مرتبط با پیام‌های این گروه
    $pdo->prepare("UPDATE notifications 
                   SET is_read = 1 
                   WHERE user_id = ? 
                     AND type = 'group_message' 
                     AND reference_id IN (SELECT id FROM group_channel_messages WHERE group_id = ?) 
                     AND is_read = 0")
        ->execute([$user_id, $group_id]);
}

echo json_encode(['success' => true]);