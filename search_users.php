<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }
$current_user_id = $_SESSION['user_id'];
$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit; }

$stmt = $pdo->prepare("SELECT id, username, first_name, last_name, display_name FROM users
    WHERE (username LIKE :q1 OR first_name LIKE :q2 OR last_name LIKE :q3 OR display_name LIKE :q4)
    AND id != :uid LIMIT 10");
$like = "%$q%";
$stmt->execute([':q1'=>$like, ':q2'=>$like, ':q3'=>$like, ':q4'=>$like, ':uid'=>$current_user_id]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($users);