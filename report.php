<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) exit;
$reporter = $_SESSION['user_id'];
$target_type = $_POST['target_type'] ?? 'message';
$target_id = (int)($_POST['target_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

if ($target_id <= 0 || empty($reason)) { echo json_encode(['success'=>false]); exit; }

$stmt = $pdo->prepare("INSERT INTO user_reports (reporter_id, target_type, target_id, reason) VALUES (?, ?, ?, ?)");
$stmt->execute([$reporter, $target_type, $target_id, $reason]);
echo json_encode(['success'=>true]);