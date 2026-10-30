<?php
require_once 'config.php';
require_once 'media_links.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'ابتدا وارد حساب شوید.']);
    exit;
}
$uid = (int)$_SESSION['user_id'];
$sourceType = $_POST['source_type'] ?? '';
$sourceId = (int)($_POST['source_id'] ?? 0);
$destinationType = $_POST['destination_type'] ?? '';
$destinationId = (int)($_POST['destination_id'] ?? 0);
if (!in_array($sourceType, ['private', 'group'], true)
    || !in_array($destinationType, ['private', 'group'], true)
    || $sourceId < 1 || $destinationId < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'گفتگو یا پیام مقصد نامعتبر است.']);
    exit;
}

// Only a participant can forward a private message; group forwards require membership.
if ($sourceType === 'private') {
    $source = $pdo->prepare("SELECT m.message, m.file_url, m.file_description, m.sender_id,
                                    COALESCE(NULLIF(u.display_name, ''), u.first_name, u.username) AS sender_name,
                                    CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END AS peer_id
                             FROM private_messages m JOIN users u ON u.id = m.sender_id
                             WHERE m.id = ? AND m.deleted_at IS NULL
                               AND (m.sender_id = ? OR m.receiver_id = ?)");
    $source->execute([$uid, $sourceId, $uid, $uid]);
    $item = $source->fetch();
    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'پیام پیدا نشد یا به آن دسترسی ندارید.']);
        exit;
    }
    $sourceConversation = 'گفتگوی خصوصی';
} else {
    $source = $pdo->prepare("SELECT m.message, m.file_url, m.file_description, m.sender_id,
                                    COALESCE(NULLIF(u.display_name, ''), u.first_name, u.username) AS sender_name,
                                    g.name AS conversation_name
                             FROM group_channel_messages m
                             JOIN groups_channels g ON g.id = m.group_id
                             JOIN users u ON u.id = m.sender_id
                             JOIN group_channel_members mine ON mine.group_id = m.group_id AND mine.user_id = ?
                             WHERE m.id = ? AND m.deleted_at IS NULL");
    $source->execute([$uid, $sourceId]);
    $item = $source->fetch();
    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'پیام پیدا نشد یا عضو گفتگو نیستید.']);
        exit;
    }
    $sourceConversation = $item['conversation_name'];
}

if (!isAllowedMediaLink($pdo, $item['file_url'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'لینک رسانه‌ی این پیام دیگر در دامنه‌های مجاز نیست.']);
    exit;
}

$restricted = $pdo->prepare('SELECT restricted_until, restriction_type FROM users WHERE id = ?');
$restricted->execute([$uid]);
$restriction = $restricted->fetch();
$activeRestriction = $restriction && $restriction['restricted_until'] && strtotime($restriction['restricted_until']) > time();
if ($activeRestriction && in_array($restriction['restriction_type'], ['ban', 'mute'], true)) {
    echo json_encode(['success' => false, 'error' => 'حساب شما اجازه ارسال پیام ندارد.']);
    exit;
}
if ($activeRestriction && $restriction['restriction_type'] === 'message_ban' && $destinationType === 'private') {
    echo json_encode(['success' => false, 'error' => 'حساب شما اجازه ارسال پیام خصوصی ندارد.']);
    exit;
}

if ($destinationType === 'private') {
    $peer = $destinationId;
    if ($peer === $uid) {
        echo json_encode(['success' => false, 'error' => 'ارسال پیام به خودتان ممکن نیست.']);
        exit;
    }
    $exists = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
    $exists->execute([$peer]);
    if (!$exists->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'کاربر مقصد پیدا نشد.']);
        exit;
    }
    $blocked = $pdo->prepare('SELECT 1 FROM blocked_users WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)');
    $blocked->execute([$uid, $peer, $peer, $uid]);
    if ($blocked->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'امکان ارسال پیام به این کاربر وجود ندارد.']);
        exit;
    }
} else {
    $destination = $pdo->prepare("SELECT g.id, g.name, g.type, g.created_by, m.id AS member_id,
                                         m.role, m.can_send_message
                                  FROM groups_channels g
                                  LEFT JOIN group_channel_members m ON m.group_id = g.id AND m.user_id = ?
                                  WHERE g.id = ?");
    $destination->execute([$uid, $destinationId]);
    $group = $destination->fetch();
    if (!$group || (!$group['member_id'] && (int)$group['created_by'] !== $uid)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'عضو گفتگوی مقصد نیستید.']);
        exit;
    }
    $isCreator = (int)$group['created_by'] === $uid;
    $isOwner = $isCreator || $group['role'] === 'owner';
    if ($activeRestriction && $restriction['restriction_type'] === 'message_ban' && !$isCreator) {
        echo json_encode(['success' => false, 'error' => 'حساب شما اجازه ارسال در این گفتگو را ندارد.']);
        exit;
    }
    if (!$isOwner && $group['role'] === 'admin') {
        $permission = $pdo->prepare('SELECT can_send_message FROM admin_permissions WHERE member_id = ?');
        $permission->execute([$group['member_id']]);
        $adminPermission = $permission->fetch();
        if (!$adminPermission || !(bool)$adminPermission['can_send_message']) {
            echo json_encode(['success' => false, 'error' => 'مجوز ارسال پیام در این گفتگو را ندارید.']);
            exit;
        }
    } elseif (!$isOwner && $group['type'] === 'channel') {
        echo json_encode(['success' => false, 'error' => 'در کانال فقط مدیران می‌توانند پیام ارسال کنند.']);
        exit;
    } elseif (!$isOwner && empty($group['can_send_message'])) {
        echo json_encode(['success' => false, 'error' => 'مجوز ارسال پیام در این گفتگو را ندارید.']);
        exit;
    }
}

// Store attribution inside the text envelope; no schema migration or file upload is needed.
$forwardInfo = [
    'name' => $item['sender_name'] ?: 'کاربر',
    'conversation' => $sourceConversation,
];
$encodedInfo = base64_encode(json_encode($forwardInfo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
$messageText = '[forward:v1:' . $encodedInfo . "]\n" . ($item['message'] ?? '');
if (strlen($messageText) > 60000) {
    echo json_encode(['success' => false, 'error' => 'این پیام برای فوروارد بیش از حد بزرگ است.']);
    exit;
}

try {
    $pdo->beginTransaction();
    if ($destinationType === 'private') {
        $pdo->prepare("INSERT INTO private_messages (sender_id, receiver_id, message, file_url, file_description) VALUES (?, ?, ?, ?, ?)")
            ->execute([$uid, $destinationId, $messageText, $item['file_url'] ?: null, $item['file_description'] ?: null]);
        $messageId = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, 'private_message', ?, ?)")
            ->execute([$destinationId, $messageId, '↪️ پیام فورواردشده از ' . ($_SESSION['username'] ?? 'کاربر')]);
    } else {
        $pdo->prepare("INSERT INTO group_channel_messages (group_id, sender_id, message, file_url, file_description) VALUES (?, ?, ?, ?, ?)")
            ->execute([$destinationId, $uid, $messageText, $item['file_url'] ?: null, $item['file_description'] ?: null]);
        $messageId = $pdo->lastInsertId();
        $members = $pdo->prepare('SELECT user_id FROM group_channel_members WHERE group_id = ? AND user_id != ?');
        $members->execute([$destinationId, $uid]);
        $notify = $pdo->prepare("INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, 'group_message', ?, ?)");
        while ($member = $members->fetch(PDO::FETCH_ASSOC)) {
            $notify->execute([$member['user_id'], $messageId, '↪️ پیام فورواردشده در ' . $group['name']]);
        }
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Message forwarding failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'فوروارد پیام انجام نشد.']);
    exit;
}
echo json_encode(['success' => true]);
