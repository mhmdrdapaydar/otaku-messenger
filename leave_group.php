<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['user_id'])) exit;
$user_id = $_SESSION['user_id'];
$group_id = (int)($_POST['group_id'] ?? 0);

$stmt = $pdo->prepare("SELECT created_by FROM groups_channels WHERE id = ?");
$stmt->execute([$group_id]);
$group = $stmt->fetch();
if ($group && $group['created_by'] == $user_id) {
    echo json_encode(['success'=>false, 'error'=>'مالک نمی‌تواند خارج شود.']);
    exit;
}
$pdo->prepare("DELETE FROM group_channel_members WHERE group_id = ? AND user_id = ?")->execute([$group_id, $user_id]);
echo json_encode(['success'=>true]);