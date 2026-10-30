<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }
$user_id = $_SESSION['user_id'];
$message_id = (int)($_POST['message_id'] ?? 0);
$type = $_POST['type'] ?? ''; // 'private' or 'group'

if ($type === 'private') {
    $stmt = $pdo->prepare("SELECT sender_id, receiver_id FROM private_messages WHERE id = ?");
    $stmt->execute([$message_id]);
    $msg = $stmt->fetch();
    if (!$msg) { echo json_encode(['success'=>false]); exit; }
    // فرستنده یا گیرنده می‌توانند حذف کنند
    if ($msg['sender_id'] != $user_id && $msg['receiver_id'] != $user_id) {
        echo json_encode(['success'=>false, 'error'=>'شما دسترسی ندارید.']); exit;
    }
    $pdo->prepare("UPDATE private_messages SET deleted_at = NOW() WHERE id = ?")->execute([$message_id]);
    echo json_encode(['success'=>true]);
} elseif ($type === 'group') {
    $stmt = $pdo->prepare("SELECT m.id, m.sender_id, g.created_by, g.id as group_id,
                           (SELECT role FROM group_channel_members WHERE group_id = g.id AND user_id = ?) as role
                           FROM group_channel_messages m 
                           JOIN groups_channels g ON m.group_id = g.id 
                           WHERE m.id = ?");
    $stmt->execute([$user_id, $message_id]);
    $msg = $stmt->fetch();
    if (!$msg) { echo json_encode(['success'=>false]); exit; }

    $is_sender = ($msg['sender_id'] == $user_id);                      // خودش فرستاده
    $is_owner = ($msg['created_by'] == $user_id);                     // مالک گروه
    $is_admin = in_array($msg['role'], ['owner', 'admin']);           // نقش او در گروه

    // 1. اگر خودش فرستنده باشد، بدون هیچ شرطی مجاز است
    if ($is_sender) {
        // مجاز
    }
    // 2. اگر مدیر است (و فرستنده نیست) باید مجوز can_delete_messages داشته باشد
    elseif ($is_admin) {
        // مالک بدون محدودیت
        if (!$is_owner && $msg['role'] === 'admin') {
            $perm = $pdo->prepare("SELECT can_delete_messages FROM admin_permissions WHERE member_id = 
                                  (SELECT id FROM group_channel_members WHERE group_id = ? AND user_id = ?)");
            $perm->execute([$msg['group_id'], $user_id]);
            $p = $perm->fetch();
            if (!$p || !$p['can_delete_messages']) {
                echo json_encode(['success'=>false, 'error'=>'مجوز حذف پیام ندارید.']); exit;
            }
        }
    }
    // 3. در غیر این‌صورت (کاربر عادی که فرستنده نیست) دسترسی ندارد
    else {
        echo json_encode(['success'=>false, 'error'=>'شما دسترسی ندارید.']); exit;
    }

    $pdo->prepare("UPDATE group_channel_messages SET deleted_at = NOW() WHERE id = ?")->execute([$message_id]);
    echo json_encode(['success'=>true]);
}