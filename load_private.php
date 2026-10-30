<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) exit;
$uid = $_SESSION['user_id'];
$peer = (int)($_GET['user'] ?? 0);
$msgs = $pdo->prepare("SELECT m.message, m.file_url, m.file_description, m.sender_id, m.sent_at, m.id,
                       u.display_name, u.first_name 
                       FROM private_messages m 
                       JOIN users u ON m.sender_id = u.id 
                       WHERE ((m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?)) 
                         AND m.deleted_at IS NULL 
                       ORDER BY m.sent_at ASC LIMIT 100");
$msgs->execute([$uid, $peer, $peer, $uid]);
$data = [];
while ($m = $msgs->fetch(PDO::FETCH_ASSOC)) {
    $m['sender_name'] = $m['display_name'] ?: $m['first_name'];
    $data[] = $m;
}
echo json_encode($data);