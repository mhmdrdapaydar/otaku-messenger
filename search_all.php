<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) exit;
$uid = $_SESSION['user_id'];
$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) exit(json_encode([]));
$like = "%$q%";

$results = [];

// ۱. کاربران (با نام، نام کاربری، یا ID)
$sql = "SELECT id, username, display_name, first_name, last_name FROM users WHERE (username LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR display_name LIKE ?";
$params = [$like, $like, $like, $like];
if (is_numeric($q)) {
    $sql .= " OR id = ?";
    $params[] = (int)$q;
}
$sql .= ") AND id != ? LIMIT 10";
$params[] = $uid;
$users = $pdo->prepare($sql);
$users->execute($params);
while ($u = $users->fetch(PDO::FETCH_ASSOC)) {
    $results[] = [
        'type' => 'user',
        'id' => $u['id'],
        'name' => $u['display_name'] ?: $u['first_name'].' '.$u['last_name'],
        'username' => $u['username']
    ];
}

// ۲. گروه‌ها/کانال‌های عمومی (بر اساس نام یا ID، مرتب‌سازی بر اساس تعداد اعضا)
$sqlGroup = "SELECT g.id, g.name, g.type, (SELECT COUNT(*) FROM group_channel_members WHERE group_id = g.id) as member_count 
             FROM groups_channels g 
             WHERE g.privacy='public' AND (g.name LIKE ?";
$paramsGroup = [$like];
if (is_numeric($q)) {
    $sqlGroup .= " OR g.id = ?";
    $paramsGroup[] = (int)$q;
}
$sqlGroup .= ") ORDER BY member_count DESC LIMIT 5";
$groups = $pdo->prepare($sqlGroup);
$groups->execute($paramsGroup);
while ($g = $groups->fetch(PDO::FETCH_ASSOC)) {
    $results[] = [
        'type' => $g['type'] == 'channel' ? 'channel' : 'group',
        'id' => $g['id'],
        'name' => $g['name'],
        'member_count' => $g['member_count']
    ];
}

// ۳. چت‌های خصوصی نزدیک (از بین افرادی که قبلاً باهاشون گفتگو داشتی)
$sqlPrivate = "SELECT u.id, u.display_name, u.first_name, u.last_name, u.username,
                (SELECT MAX(sent_at) FROM private_messages WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id)) as last_time
               FROM users u
               WHERE u.id IN (
                   SELECT DISTINCT sender_id FROM private_messages WHERE receiver_id = ?
                   UNION
                   SELECT DISTINCT receiver_id FROM private_messages WHERE sender_id = ?
               )
               AND u.id != ?
               AND (u.display_name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?";
$paramsPrivate = [$uid, $uid, $uid, $uid, $uid, $like, $like, $like, $like];
if (is_numeric($q)) {
    $sqlPrivate .= " OR u.id = ?";
    $paramsPrivate[] = (int)$q;
}
$sqlPrivate .= ") ORDER BY last_time DESC LIMIT 5";
$privates = $pdo->prepare($sqlPrivate);
$privates->execute($paramsPrivate);
while ($p = $privates->fetch(PDO::FETCH_ASSOC)) {
    $name = $p['display_name'] ?: $p['first_name'].' '.$p['last_name'];
    $results[] = [
        'type' => 'private_chat',
        'id' => $p['id'],
        'name' => $name,
        'username' => $p['username']
    ];
}

echo json_encode(array_slice($results, 0, 15));