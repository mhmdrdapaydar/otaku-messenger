<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['user_id'])) exit;
$user_id = $_SESSION['user_id'];
$message_id = (int)$_POST['message_id'];
$emoji = $_POST['emoji'] ?? '';
$action = $_POST['action'] ?? 'add'; // add or remove

// بررسی وجود پیام و عضویت کاربر در گروه
$stmt = $pdo->prepare("SELECT m.group_id FROM group_channel_messages m WHERE m.id = ?");
$stmt->execute([$message_id]);
$row = $stmt->fetch();
if (!$row) { echo json_encode(['success'=>false]); exit; }
$gid = $row['group_id'];

$member = $pdo->prepare("SELECT 1 FROM group_channel_members WHERE group_id = ? AND user_id = ?");
$member->execute([$gid, $user_id]);
if (!$member->fetchColumn()) { echo json_encode(['success'=>false, 'error'=>'عضو نیستید.']); exit; }

if ($action === 'add') {
    $pdo->prepare("INSERT IGNORE INTO message_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)")->execute([$message_id, $user_id, $emoji]);
} else {
    $pdo->prepare("DELETE FROM message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?")->execute([$message_id, $user_id, $emoji]);
}
echo json_encode(['success'=>true]);