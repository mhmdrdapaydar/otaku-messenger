<?php
require_once 'config.php';
require_once 'media_links.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }
$user_id = (int)$_SESSION['user_id'];
$message_id = (int)($_POST['message_id'] ?? 0);
$new_text = trim($_POST['message'] ?? '');
$file_url = trim($_POST['file_url'] ?? '');
$file_description = trim($_POST['file_description'] ?? '');
$textLength = function_exists('mb_strlen') ? mb_strlen($new_text, 'UTF-8') : strlen($new_text);
$descriptionLength = function_exists('mb_strlen') ? mb_strlen($file_description, 'UTF-8') : strlen($file_description);

if ($message_id < 1 || ($new_text === '' && $file_url === '')) {
    echo json_encode(['success' => false, 'error' => 'متن یا لینک رسانه باید وارد شود.']);
    exit;
}
if ($textLength > 10000 || $descriptionLength > 255) {
    echo json_encode(['success' => false, 'error' => 'طول پیام یا توضیح پیوند بیش از حد مجاز است.']);
    exit;
}
if (!isAllowedMediaLink($pdo, $file_url)) {
    echo json_encode(['success' => false, 'error' => 'لینک رسانه باید HTTPS و از دامنه مجاز باشد.']);
    exit;
}

$stmt = $pdo->prepare("SELECT m.sender_id, m.group_id, g.created_by,
                              (SELECT role FROM group_channel_members WHERE group_id = m.group_id AND user_id = ?) AS role
                       FROM group_channel_messages m
                       JOIN groups_channels g ON g.id = m.group_id
                       WHERE m.id = ? AND m.deleted_at IS NULL");
$stmt->execute([$user_id, $message_id]);
$msg = $stmt->fetch();
if (!$msg) {
    echo json_encode(['success' => false, 'error' => 'پیام یافت نشد.']);
    exit;
}

$isOwner = (int)$msg['created_by'] === $user_id || $msg['role'] === 'owner';
$canEdit = (int)$msg['sender_id'] === $user_id || $isOwner;
if (!$canEdit && $msg['role'] === 'admin') {
    $permission = $pdo->prepare("SELECT can_edit_messages FROM admin_permissions
                                 WHERE member_id = (SELECT id FROM group_channel_members WHERE group_id = ? AND user_id = ?)");
    $permission->execute([$msg['group_id'], $user_id]);
    $row = $permission->fetch();
    $canEdit = $row && (bool)$row['can_edit_messages'];
}
if (!$canEdit) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'مجاز به ویرایش این پیام نیستید.']);
    exit;
}

$pdo->prepare("UPDATE group_channel_messages SET message = ?, file_url = ?, file_description = ?, edited_at = NOW() WHERE id = ?")
    ->execute([$new_text, $file_url ?: null, $file_description ?: null, $message_id]);
echo json_encode(['success' => true]);
