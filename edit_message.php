<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['user_id'])) exit;
$user_id = $_SESSION['user_id'];
$message_id = (int)$_POST['message_id'];
$new_text = trim($_POST['message'] ?? '');
$file_url = trim($_POST['file_url'] ?? '');
$file_description = trim($_POST['file_description'] ?? '');

if (!$message_id || ($new_text === '' && $file_url === '')) {
    echo json_encode(['success'=>false, 'error'=>'متن یا فایل باید پر شود.']);
    exit;
}

// بررسی نوع پیام (گروه)
$stmt = $pdo->prepare("SELECT sender_id, group_id FROM group_channel_messages WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$message_id]);
$msg = $stmt->fetch();
if (!$msg) { echo json_encode(['success'=>false, 'error'=>'پیام یافت نشد.']); exit; }

// فقط فرستنده یا ادمین با مجوز ویرایش می‌تواند
$can_edit = ($msg['sender_id'] == $user_id);
if (!$can_edit) {
    $perm = $pdo->prepare("SELECT can_edit_messages FROM admin_permissions WHERE member_id = (SELECT id FROM group_channel_members WHERE group_id = ? AND user_id = ?)");
    $perm->execute([$msg['group_id'], $user_id]);
    $p = $perm->fetch();
    $can_edit = ($p && $p['can_edit_messages']);
}

if ($can_edit) {
    $pdo->prepare("UPDATE group_channel_messages SET message = ?, file_url = ?, file_description = ?, edited_at = NOW() WHERE id = ?")
        ->execute([$new_text, $file_url ?: null, $file_description ?: null, $message_id]);
    echo json_encode(['success'=>true]);
} else {
    echo json_encode(['success'=>false, 'error'=>'مجاز به ویرایش نیستید.']);
}