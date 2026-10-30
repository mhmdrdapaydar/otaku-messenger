<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}
$gid = (int)($_GET['id'] ?? 0);
$uid = (int)$_SESSION['user_id'];
$access = $pdo->prepare("SELECT 1 FROM groups_channels g
                         LEFT JOIN group_channel_members m ON m.group_id = g.id AND m.user_id = ?
                         WHERE g.id = ? AND (m.user_id IS NOT NULL OR g.created_by = ?)");
$access->execute([$uid, $gid, $uid]);
if (!$access->fetchColumn()) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$after = isset($_GET['after']) ? (int)$_GET['after'] : null;
$before = isset($_GET['before']) ? (int)$_GET['before'] : null;

if ($after) {
    // پیام‌های جدیدتر از after (برای polling)
    $sql = "
        SELECT 
            m.id,
            m.message,
            m.file_url,
            m.file_description,
            m.sender_id,
            m.edited_at,
            m.sent_at,
            u.display_name,
            u.first_name,
            u.last_name,
            u.avatar_url,
            COUNT(DISTINCT r.user_id) as reaction_count,
            GROUP_CONCAT(DISTINCT r.emoji SEPARATOR ' ') as all_emojis,
            (SELECT emoji FROM message_reactions WHERE message_id = m.id AND user_id = ? ORDER BY reacted_at DESC LIMIT 1) as user_emoji,
            CASE WHEN p.message_id IS NOT NULL THEN 1 ELSE 0 END as is_pinned,
            COUNT(DISTINCT rs.user_id) as read_count
        FROM group_channel_messages m
        JOIN users u ON m.sender_id = u.id
        LEFT JOIN message_reactions r ON m.id = r.message_id
        LEFT JOIN pinned_messages p ON m.id = p.message_id
        LEFT JOIN message_read_status rs ON m.id = rs.message_id
        WHERE m.group_id = ? AND m.deleted_at IS NULL AND m.id > ?
        GROUP BY m.id
        ORDER BY m.id ASC
        LIMIT 50
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$uid, $gid, $after]);
} elseif ($before) {
    // پیام‌های قدیمی‌تر از before
    $sql = "
        SELECT 
            m.id,
            m.message,
            m.file_url,
            m.file_description,
            m.sender_id,
            m.edited_at,
            m.sent_at,
            u.display_name,
            u.first_name,
            u.last_name,
            u.avatar_url,
            COUNT(DISTINCT r.user_id) as reaction_count,
            GROUP_CONCAT(DISTINCT r.emoji SEPARATOR ' ') as all_emojis,
            (SELECT emoji FROM message_reactions WHERE message_id = m.id AND user_id = ? ORDER BY reacted_at DESC LIMIT 1) as user_emoji,
            CASE WHEN p.message_id IS NOT NULL THEN 1 ELSE 0 END as is_pinned,
            COUNT(DISTINCT rs.user_id) as read_count
        FROM group_channel_messages m
        JOIN users u ON m.sender_id = u.id
        LEFT JOIN message_reactions r ON m.id = r.message_id
        LEFT JOIN pinned_messages p ON m.id = p.message_id
        LEFT JOIN message_read_status rs ON m.id = rs.message_id
        WHERE m.group_id = ? AND m.deleted_at IS NULL AND m.id < ?
        GROUP BY m.id
        ORDER BY m.id DESC
        LIMIT 50
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$uid, $gid, $before]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = array_reverse($rows);
    $stmt = null;
} else {
    // بارگذاری اولیه
    $sql = "
        SELECT 
            m.id,
            m.message,
            m.file_url,
            m.file_description,
            m.sender_id,
            m.edited_at,
            m.sent_at,
            u.display_name,
            u.first_name,
            u.last_name,
            u.avatar_url,
            COUNT(DISTINCT r.user_id) as reaction_count,
            GROUP_CONCAT(DISTINCT r.emoji SEPARATOR ' ') as all_emojis,
            (SELECT emoji FROM message_reactions WHERE message_id = m.id AND user_id = ? ORDER BY reacted_at DESC LIMIT 1) as user_emoji,
            CASE WHEN p.message_id IS NOT NULL THEN 1 ELSE 0 END as is_pinned,
            COUNT(DISTINCT rs.user_id) as read_count
        FROM group_channel_messages m
        JOIN users u ON m.sender_id = u.id
        LEFT JOIN message_reactions r ON m.id = r.message_id
        LEFT JOIN pinned_messages p ON m.id = p.message_id
        LEFT JOIN message_read_status rs ON m.id = rs.message_id
        WHERE m.group_id = ? AND m.deleted_at IS NULL
        GROUP BY m.id
        ORDER BY m.id DESC
        LIMIT 50
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$uid, $gid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = array_reverse($rows);
    $stmt = null;
}

if (isset($rows)) {
    $data = [];
    foreach ($rows as $m) {
        $m['sender_name'] = $m['display_name'] ?: ($m['first_name'] . ' ' . $m['last_name']);
        $m['is_pinned'] = (bool)$m['is_pinned'];
        $m['all_emojis'] = $m['all_emojis'] ? explode(' ', $m['all_emojis']) : [];
        $data[] = $m;
    }
    echo json_encode($data);
    exit;
}

$data = [];
while ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $m['sender_name'] = $m['display_name'] ?: ($m['first_name'] . ' ' . $m['last_name']);
    $m['is_pinned'] = (bool)$m['is_pinned'];
    $m['all_emojis'] = $m['all_emojis'] ? explode(' ', $m['all_emojis']) : [];
    $data[] = $m;
}
echo json_encode($data);