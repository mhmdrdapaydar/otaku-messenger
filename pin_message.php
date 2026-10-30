<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) exit;
$user_id = $_SESSION['user_id'];
$message_id = (int)$_POST['message_id'];
$action = $_POST['action'] ?? 'pin'; // pin or unpin

// یافتن گروه پیام
$stmt = $pdo->prepare("SELECT m.group_id, g.created_by FROM group_channel_messages m 
                       JOIN groups_channels g ON m.group_id = g.id WHERE m.id = ?");
$stmt->execute([$message_id]);
$row = $stmt->fetch();
if (!$row) { echo json_encode(['success'=>false]); exit; }
$group_id = $row['group_id'];

// بررسی دسترسی: مالک یا مدیری که مجوز سنجاق دارد
$member = $pdo->prepare("SELECT role FROM group_channel_members WHERE group_id = ? AND user_id = ?");
$member->execute([$group_id, $user_id]);
$m = $member->fetch();
$is_owner = ($row['created_by'] == $user_id);
$is_admin = $m && in_array($m['role'], ['owner', 'admin']);
if (!$is_owner && !$is_admin) { echo json_encode(['success'=>false, 'error'=>'دسترسی ندارید.']); exit; }

if (!$is_owner) {
    $perm = $pdo->prepare("SELECT can_pin_message FROM admin_permissions WHERE member_id = 
                           (SELECT id FROM group_channel_members WHERE group_id = ? AND user_id = ?)");
    $perm->execute([$group_id, $user_id]);
    $p = $perm->fetch();
    if (!$p || !$p['can_pin_message']) { echo json_encode(['success'=>false, 'error'=>'مجوز سنجاق ندارید.']); exit; }
}

if ($action === 'pin') {
    $pdo->prepare("INSERT IGNORE INTO pinned_messages (group_id, message_id, pinned_by) VALUES (?, ?, ?)")->execute([$group_id, $message_id, $user_id]);
} else {
    $pdo->prepare("DELETE FROM pinned_messages WHERE message_id = ? AND group_id = ?")->execute([$message_id, $group_id]);
}
echo json_encode(['success'=>true]);