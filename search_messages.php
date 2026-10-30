<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'items' => []]);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
$conversationId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$length = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
if ($length < 2 || $length > 100 || $conversationId < 1) {
    echo json_encode(['success' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
// Treat SQL wildcard characters as text, not as a way to broaden a search.
$search = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';

if ($type === 'private') {
    if ($conversationId === $userId) {
        echo json_encode(['success' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sql = "SELECT m.id, m.message, m.file_description, m.sent_at, m.sender_id,
                   u.display_name, u.first_name
            FROM private_messages m JOIN users u ON u.id = m.sender_id
            WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
              AND m.deleted_at IS NULL
              AND (m.message LIKE ? ESCAPE '!' OR m.file_description LIKE ? ESCAPE '!')
            ORDER BY m.id DESC LIMIT 40";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $conversationId, $conversationId, $userId, $search, $search]);
} elseif ($type === 'group') {
    $access = $pdo->prepare("SELECT 1 FROM groups_channels g
                             LEFT JOIN group_channel_members m ON m.group_id = g.id AND m.user_id = ?
                             WHERE g.id = ? AND (m.user_id IS NOT NULL OR g.created_by = ?)");
    $access->execute([$userId, $conversationId, $userId]);
    if (!$access->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'items' => []]);
        exit;
    }
    $sql = "SELECT m.id, m.message, m.file_description, m.sent_at, m.sender_id,
                   u.display_name, u.first_name
            FROM group_channel_messages m JOIN users u ON u.id = m.sender_id
            WHERE m.group_id = ? AND m.deleted_at IS NULL
              AND (m.message LIKE ? ESCAPE '!' OR m.file_description LIKE ? ESCAPE '!')
            ORDER BY m.id DESC LIMIT 40";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$conversationId, $search, $search]);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'items' => []]);
    exit;
}

$items = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $items[] = [
        'id' => (int)$row['id'],
        'message' => function_exists('mb_substr') ? mb_substr($row['message'] ?? '', 0, 280, 'UTF-8') : substr($row['message'] ?? '', 0, 280),
        'file_description' => $row['file_description'] ?? '',
        'sent_at' => $row['sent_at'],
        'sender_id' => (int)$row['sender_id'],
        'sender_name' => $row['display_name'] ?: $row['first_name'],
    ];
}
echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
