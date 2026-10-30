<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$uid = (int) $_SESSION['user_id'];
$peer = filter_input(INPUT_GET, 'user', FILTER_VALIDATE_INT) ?: 0;
if ($peer < 1 || $peer === $uid) {
    echo json_encode([]);
    exit;
}

$after = filter_input(INPUT_GET, 'after', FILTER_VALIDATE_INT) ?: 0;
$before = filter_input(INPUT_GET, 'before', FILTER_VALIDATE_INT) ?: 0;
$sql = "SELECT m.id, m.message, m.file_url, m.file_description, m.sender_id, m.sent_at,
               u.display_name, u.first_name,
               (SELECT r.emoji FROM private_message_reactions r WHERE r.message_id = m.id AND r.user_id = ? ORDER BY r.reacted_at DESC LIMIT 1) AS user_emoji,
               (SELECT GROUP_CONCAT(DISTINCT r.emoji SEPARATOR ' ')
                  FROM private_message_reactions r WHERE r.message_id = m.id) AS all_emojis
        FROM private_messages m
        JOIN users u ON u.id = m.sender_id
        WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
          AND m.deleted_at IS NULL";
$params = [$uid, $uid, $peer, $peer, $uid];

if ($after > 0) {
    $sql .= ' AND m.id > ? ORDER BY m.id ASC LIMIT 50';
    $params[] = $after;
} elseif ($before > 0) {
    $sql .= ' AND m.id < ? ORDER BY m.id DESC LIMIT 50';
    $params[] = $before;
} else {
    $sql .= ' ORDER BY m.id DESC LIMIT 50';
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} catch (PDOException $error) {
    // Do not take the entire private-chat timeline offline if the optional
    // reaction migration has not yet been applied on an existing install.
    if ($error->getCode() !== '42S02') {
        throw $error;
    }
    $sql = "SELECT m.id, m.message, m.file_url, m.file_description, m.sender_id, m.sent_at,
                   u.display_name, u.first_name
            FROM private_messages m JOIN users u ON u.id = m.sender_id
            WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
              AND m.deleted_at IS NULL";
    if ($after > 0) {
        $sql .= ' AND m.id > ? ORDER BY m.id ASC LIMIT 50';
        $fallbackParams = [$uid, $peer, $peer, $uid, $after];
    } elseif ($before > 0) {
        $sql .= ' AND m.id < ? ORDER BY m.id DESC LIMIT 50';
        $fallbackParams = [$uid, $peer, $peer, $uid, $before];
    } else {
        $sql .= ' ORDER BY m.id DESC LIMIT 50';
        $fallbackParams = [$uid, $peer, $peer, $uid];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($fallbackParams);
}
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($before === 0 && $after === 0) {
    $messages = array_reverse($messages);
} elseif ($before > 0) {
    $messages = array_reverse($messages);
}

foreach ($messages as &$message) {
    $message['sender_name'] = $message['display_name'] ?: $message['first_name'];
    $message['user_emoji'] = $message['user_emoji'] ?? null;
    $message['all_emojis'] = !empty($message['all_emojis']) ? explode(' ', $message['all_emojis']) : [];
}
unset($message);
echo json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
