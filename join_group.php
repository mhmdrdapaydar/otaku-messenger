<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403); exit;
}
$user_id = $_SESSION['user_id'];
$group_id = (int)($_POST['group_id'] ?? 0);

// وجود گروه و عمومی بودن را بررسی کن
$group = $pdo->prepare("SELECT id, privacy FROM groups_channels WHERE id = ?");
$group->execute([$group_id]);
$g = $group->fetch();
if (!$g || $g['privacy'] != 'public') {
    echo json_encode(['success'=>false, 'error'=>'امکان عضویت وجود ندارد.']);
    exit;
}

// بررسی بن
$ban = $pdo->prepare("SELECT 1 FROM group_bans WHERE group_id = ? AND user_id = ?");
$ban->execute([$group_id, $user_id]);
if ($ban->fetchColumn()) {
    echo json_encode(['success'=>false, 'error'=>'شما توسط مدیران از این گروه مسدود شده‌اید.']);
    exit;
}

// آیا قبلاً عضو است؟
$member = $pdo->prepare("SELECT 1 FROM group_channel_members WHERE group_id = ? AND user_id = ?");
$member->execute([$group_id, $user_id]);
if ($member->fetchColumn()) {
    echo json_encode(['success'=>false, 'error'=>'شما قبلاً عضو هستید.']);
    exit;
}

// افزودن به عنوان عضو
$pdo->prepare("INSERT INTO group_channel_members (group_id, user_id, role, can_send_message) VALUES (?, ?, 'member', 1)")->execute([$group_id, $user_id]);
echo json_encode(['success'=>true]);