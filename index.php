<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$current_user_id = $_SESSION['user_id'];

// ---------- دریافت وضعیت محدودیت کاربر ----------
$restrictStmt = $pdo->prepare("SELECT restricted_until, restriction_type, restriction_reason FROM users WHERE id = ?");
$restrictStmt->execute([$current_user_id]);
$restrictData = $restrictStmt->fetch();

$isBanned = false;
$isMessageBanned = false;
$isMuted = false;
$restrictionInfo = null;

if ($restrictData && $restrictData['restricted_until'] && strtotime($restrictData['restricted_until']) > time()) {
    $type = $restrictData['restriction_type'];
    $restrictionInfo = [
        'type' => $type,
        'reason' => $restrictData['restriction_reason'] ?? 'نامشخص',
        'until' => $restrictData['restricted_until'],
        'remaining' => strtotime($restrictData['restricted_until']) - time()
    ];
    if ($type === 'ban') {
        $isBanned = true;
    } elseif ($type === 'message_ban') {
        $isMessageBanned = true;
    } elseif ($type === 'mute') {
        $isMuted = true;
    }
}

// ---------- اگر کاربر مسدود کامل است ----------
if ($isBanned) {
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>حساب مسدود</title>
        <style>
            body { font-family: Tahoma; background: #0a0a1a; color: #fff; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; text-shadow: 0 0 3px rgba(255,255,255,0.4); }
            .box { text-align: center; background: rgba(255,255,255,0.05); backdrop-filter: blur(10px); padding: 30px; border-radius: 20px; border: 1px solid #ff4d6d; }
            h1 { color: #ff4d6d; }
            a { color: #ff8fa3; }
        </style>
    </head>
    <body>
        <div class="box">
            <h1>⛔ حساب شما مسدود شده است</h1>
            <p><strong>نوع محدودیت:</strong> مسدودیت کامل</p>
            <p><strong>دلیل:</strong> <?= htmlspecialchars($restrictionInfo['reason']) ?></p>
            <p><strong>تا تاریخ:</strong> <?= date('Y/m/d H:i', strtotime($restrictionInfo['until'])) ?></p>
            <p><strong>زمان باقی‌مانده:</strong> <?= floor($restrictionInfo['remaining'] / 86400) ?> روز و <?= floor(($restrictionInfo['remaining'] % 86400) / 3600) ?> ساعت</p>
            <p>امکان استفاده از پیام‌رسان وجود ندارد.</p>
            <p><a href="logout.php">خروج</a></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ---------- پشتیبانی از درخواست‌های AJAX ----------
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // دریافت تعداد پیام‌های خوانده نشده
    if ($_GET['action'] === 'unread_counts') {
        $counts = [];

        $stmt = $pdo->prepare("SELECT u.id, COUNT(*) as cnt FROM private_messages pm
                               JOIN users u ON u.id = pm.sender_id
                               WHERE pm.receiver_id = ? AND pm.is_read = 0 AND pm.deleted_at IS NULL
                               GROUP BY u.id");
        $stmt->execute([$current_user_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[] = ['type' => 'private', 'id' => (int)$row['id'], 'unread' => (int)$row['cnt']];
        }

        $stmt = $pdo->prepare("SELECT g.id, COUNT(*) as cnt
                               FROM group_channel_messages gm
                               JOIN groups_channels g ON gm.group_id = g.id
                               JOIN group_channel_members m ON m.group_id = g.id AND m.user_id = ?
                               WHERE gm.sender_id != ? AND gm.deleted_at IS NULL AND gm.id > COALESCE(m.last_read_message_id, 0)
                               GROUP BY g.id");
        $stmt->execute([$current_user_id, $current_user_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[] = ['type' => 'group', 'id' => (int)$row['id'], 'unread' => (int)$row['cnt']];
        }

        echo json_encode($counts);
        exit;
    }

    // بررسی پیام‌های حذف‌شده
    if ($_GET['action'] === 'check_deleted' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $messageIds = $_POST['message_ids'] ?? [];
        $type = $_POST['type'] ?? '';
        $groupId = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;

        if (!is_array($messageIds) || empty($messageIds) || !in_array($type, ['private', 'group'])) {
            echo json_encode(['deleted_ids' => []]);
            exit;
        }

        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn($id) => $id > 0))), 0, 100);
        if (!$ids) { echo json_encode(['deleted_ids' => []]); exit; }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($type === 'private') {
            $sql = "SELECT id FROM private_messages WHERE id IN ($placeholders) AND deleted_at IS NOT NULL
                    AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))";
            array_push($ids, $current_user_id, $current_user_id, $current_user_id, $current_user_id);
        } else {
            $membership = $pdo->prepare("SELECT 1 FROM group_channel_members WHERE group_id = ? AND user_id = ?");
            $membership->execute([$groupId, $current_user_id]);
            if (!$membership->fetchColumn()) { http_response_code(403); echo json_encode(['deleted_ids' => []]); exit; }
            $sql = "SELECT id FROM group_channel_messages WHERE id IN ($placeholders) AND deleted_at IS NOT NULL AND group_id = ?";
            $ids[] = $groupId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $deletedIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        echo json_encode(['deleted_ids' => array_map('intval', $deletedIds)]);
        exit;
    }

    // دریافت ID کاربر از روی username
    if ($_GET['action'] === 'resolve_username') {
        $username = $_GET['username'] ?? '';
        if ($username) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                echo json_encode(['success' => true, 'user_id' => (int)$user['id']]);
            } else {
                echo json_encode(['success' => false, 'error' => 'کاربر یافت نشد']);
            }
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    exit;
}

function getUserInfo($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT id, username, display_name, first_name, last_name, avatar_url, is_online, last_seen FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
$current_user = getUserInfo($pdo, $current_user_id);

$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'home';
$target_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$user_chat_id = isset($_GET['user']) ? (int)$_GET['user'] : null;

if ($page == 'group' && isset($_GET['slug']) && !isset($_GET['id'])) {
    $slug = $_GET['slug'];
    $stmt = $pdo->prepare("SELECT id FROM groups_channels WHERE slug = ?");
    $stmt->execute([$slug]);
    $group_id = $stmt->fetchColumn();
    if ($group_id) {
        $target_id = $group_id;
    } else {
        $page = 'notfound';
    }
}

// -------- ساخت لیست یکپارچه برای صفحه «همه گفتگوها» ----------
$all_conversations = [];

// 1. گفتگوهای خصوصی
$private_chats = $pdo->prepare("
    SELECT u.id, u.display_name, u.first_name, u.last_name, u.avatar_url, u.is_online, u.last_seen,
        (SELECT message FROM private_messages
         WHERE ((sender_id = u.id AND receiver_id = :uid) OR (sender_id = :uid2 AND receiver_id = u.id))
           AND deleted_at IS NULL
         ORDER BY sent_at DESC LIMIT 1) as last_msg,
        (SELECT MAX(sent_at) FROM private_messages
         WHERE ((sender_id = u.id AND receiver_id = :uid3) OR (sender_id = :uid4 AND receiver_id = u.id))
           AND deleted_at IS NULL) as last_time,
        (SELECT COUNT(*) FROM private_messages WHERE sender_id = u.id AND receiver_id = :uid5 AND is_read = 0 AND deleted_at IS NULL) as unread
    FROM users u
    WHERE u.id IN (
        SELECT DISTINCT sender_id FROM private_messages WHERE receiver_id = :uid6 AND deleted_at IS NULL
        UNION
        SELECT DISTINCT receiver_id FROM private_messages WHERE sender_id = :uid7 AND deleted_at IS NULL
    ) AND u.id != :uid8
");
$private_chats->execute([
    ':uid' => $current_user_id, ':uid2' => $current_user_id,
    ':uid3' => $current_user_id, ':uid4' => $current_user_id,
    ':uid5' => $current_user_id, ':uid6' => $current_user_id,
    ':uid7' => $current_user_id, ':uid8' => $current_user_id
]);
while ($chat = $private_chats->fetch(PDO::FETCH_ASSOC)) {
    $name = $chat['display_name'] ?: $chat['first_name'].' '.$chat['last_name'];
    $all_conversations[] = [
        'type' => 'private',
        'id' => $chat['id'],
        'name' => $name,
        'avatar' => mb_substr($name, 0, 1),
        'avatar_img' => $chat['avatar_url'],
        'is_online' => $chat['is_online'],
        'last_msg' => $chat['last_msg'],
        'last_time' => $chat['last_time'],
        'unread' => $chat['unread'],
        'link' => '?page=chat&user=' . $chat['id']
    ];
}

// 2. گروه‌ها و کانال‌ها
$group_convs = $pdo->prepare("
    SELECT g.id, g.name, g.type, g.avatar_url, g.slug,
        (SELECT message FROM group_channel_messages WHERE group_id = g.id AND deleted_at IS NULL ORDER BY sent_at DESC LIMIT 1) as last_msg,
        (SELECT MAX(sent_at) FROM group_channel_messages WHERE group_id = g.id AND deleted_at IS NULL) as last_time,
        (SELECT COUNT(*) FROM group_channel_messages gm
         WHERE gm.group_id = g.id AND gm.deleted_at IS NULL AND gm.id > COALESCE(m.last_read_message_id, 0) AND gm.sender_id != :uid) as unread
    FROM groups_channels g
    JOIN group_channel_members m ON g.id = m.group_id AND m.user_id = :uid2
");
$group_convs->execute([':uid' => $current_user_id, ':uid2' => $current_user_id]);
while ($g = $group_convs->fetch(PDO::FETCH_ASSOC)) {
    $avatar_icon = $g['type'] == 'channel' ? '📢' : '👥';
    $all_conversations[] = [
        'type' => 'group',
        'kind' => $g['type'],
        'id' => $g['id'],
        'name' => $g['name'],
        'avatar' => $avatar_icon,
        'avatar_img' => $g['avatar_url'],
        'is_online' => null,
        'last_msg' => $g['last_msg'],
        'last_time' => $g['last_time'],
        'unread' => $g['unread'],
        'link' => !empty($g['slug']) ? '?page=group&slug='.urlencode($g['slug']) : '?page=group&id='.$g['id']
    ];
}

// مرتب‌سازی بر اساس last_time نزولی
usort($all_conversations, function($a, $b) {
    if ($a['last_time'] === null && $b['last_time'] === null) return 0;
    if ($a['last_time'] === null) return 1;
    if ($b['last_time'] === null) return -1;
    return strtotime($b['last_time']) - strtotime($a['last_time']);
});
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>پیام‌رسان | دنیای اوتاکو</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#090b16">
    <style>
        /* همان استایل‌های قبلی بدون تغییر */
        :root {
            --primary: #ff4d6d;
            --primary-hover: #ff8fa3;
            --surface: rgba(15, 15, 35, 0.75);
            --bg: #050510;
            --border: rgba(255, 77, 109, 0.2);
            --text: #fff;
            --secondary-text: #b0b0c0;
            --msg-out: rgba(255, 77, 109, 0.25);
            --msg-in: rgba(255, 255, 255, 0.08);
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: var(--bg);
            background-image: radial-gradient(circle at 20% 30%, #1a1a3e 0%, transparent 40%),
                                radial-gradient(circle at 80% 70%, #2a0a2a 0%, transparent 50%);
            height: 100vh; display: flex; overflow: hidden;
            padding-bottom: calc(150px + env(safe-area-inset-bottom, 20px));
            color: var(--text);
            text-shadow: 0 0 2px rgba(255, 255, 255, 0.25);
            transition: padding-bottom 0.3s ease;
        }
        body.nav-hidden {
            padding-bottom: calc(20px + env(safe-area-inset-bottom, 20px));
        }
        body.nav-hidden .bottom-nav {
            opacity: 0;
            pointer-events: none;
        }
        img { max-width: 100%; height: auto; object-fit: cover; }
        .particles {
            position: fixed; top:0; left:0; width:100%; height:100%; pointer-events: none; z-index:0;
        }
        .particle {
            position: absolute; border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, rgba(255,77,109,0.7), rgba(255,77,109,0.1));
            box-shadow: 0 0 30px rgba(255,77,109,0.4), 0 0 60px rgba(255,77,109,0.2);
            animation: floatParticle 14s infinite ease-in-out;
            filter: blur(0.5px);
        }
        .particle:nth-child(1) { width:100px; height:100px; left:5%; top:15%; animation-delay:0s; }
        .particle:nth-child(2) { width:70px; height:70px; left:85%; top:75%; animation-delay:-3s; background: radial-gradient(circle at 30% 30%, rgba(106,5,114,0.7), rgba(106,5,114,0.1)); }
        .particle:nth-child(3) { width:130px; height:130px; left:45%; top:50%; animation-delay:-6s; }
        .particle:nth-child(4) { width:60px; height:60px; left:25%; top:85%; animation-delay:-9s; background: radial-gradient(circle at 30% 30%, rgba(255,77,109,0.6), transparent); }
        .particle:nth-child(5) { width:90px; height:90px; left:70%; top:10%; animation-delay:-11s; background: radial-gradient(circle at 30% 30%, rgba(255,179,198,0.5), transparent); }
        .particle:nth-child(6) { width:45px; height:45px; left:60%; top:35%; animation-delay:-1s; }
        @keyframes floatParticle {
            0%,100% { transform: translate(0,0) scale(1) rotate(0deg); opacity:0.8; }
            25% { transform: translate(-20px,-40px) scale(1.1) rotate(5deg); opacity:1; }
            50% { transform: translate(10px,-70px) scale(0.9) rotate(-3deg); opacity:0.6; }
            75% { transform: translate(25px,-20px) scale(1.05) rotate(2deg); opacity:0.9; }
        }
        .sidebar {
            width: 340px; min-width: 280px;
            background: rgba(15, 15, 35, 0.8);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            display: flex; flex-direction: column;
            border-left: 1px solid var(--border);
            z-index: 20; position: relative;
            box-shadow: 0 0 30px rgba(0,0,0,0.5);
        }
        .sidebar-header {
            padding: 12px 16px; background: rgba(0,0,0,0.2);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .sidebar-header .user-info { display: flex; align-items: center; gap: 8px; }
        .sidebar-header .user-info span { font-weight: 500; color: var(--text); }
        .online-dot { width:10px; height:10px; border-radius:50%; background:#4caf50; display:inline-block; }
        .offline-dot { background:#666; }
        .btn-icon {
            background: none; border: none; font-size: 1.3rem; cursor: pointer;
            color: var(--secondary-text); padding: 6px; border-radius: 50%;
            width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
            transition: all 0.3s;
        }
        .btn-icon:hover { background: rgba(255,77,109,0.15); color: var(--primary); }
        .search-box { padding: 8px 12px; position: relative; }
        .search-box input {
            width: 100%; padding: 8px 12px; border-radius: 20px;
            border: 1px solid var(--border); background: rgba(255,255,255,0.05);
            color: var(--text); outline: none; font-size: 0.95rem;
            backdrop-filter: blur(10px);
        }
        .search-box input:focus { border-color: var(--primary); }
        .search-results {
            position: absolute; top: 100%; left: 12px; right: 12px;
            background: rgba(20,20,45,0.95); backdrop-filter: blur(30px);
            border: 1px solid var(--border); border-radius: 12px;
            max-height: 300px; overflow-y: auto; display: none; z-index: 30;
            color: #fff;
        }
        .chat-list { flex: 1; overflow-y: auto; }
        .chat-item {
            padding: 10px 16px; display: flex; align-items: center; gap: 10px;
            cursor: pointer; transition: background 0.15s;
            border-bottom: 1px solid rgba(255,255,255,0.05); color: #ccc;
        }
        .chat-item:hover { background: rgba(255,77,109,0.1); }
        .chat-item.active { background: rgba(255,77,109,0.2); }
        .chat-item .avatar {
            width: 48px; height: 48px; border-radius: 50%;
            background: var(--primary); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; font-weight: bold; flex-shrink: 0; overflow: hidden;
        }
        .chat-item .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .chat-details { flex: 1; overflow: hidden; }
        .chat-name { font-weight: 500; color: var(--text); display: flex; align-items: center; gap: 4px; }
        .chat-preview { font-size: 0.85rem; color: var(--secondary-text); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .unread-badge { background: var(--primary); color: #fff; border-radius: 10px; padding: 2px 6px; font-size: 0.7rem; margin-right: 4px; }
        .main {
            flex: 1; display: flex; flex-direction: column; background: transparent; position: relative; z-index: 2;
            padding-bottom: 10px;
        }
        .main-header {
            padding: 12px 16px; background: rgba(15,15,35,0.7);
            backdrop-filter: blur(20px); border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .main-header .chat-info { display: flex; align-items: center; gap: 10px; }
        .main-header .chat-info .avatar {
            width: 40px; height: 40px; border-radius: 50%; overflow: hidden;
            background: var(--primary); display: flex; align-items: center; justify-content: center;
        }
        .main-header .chat-info .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .main-header h3 { font-size: 1.1rem; font-weight: 500; color: var(--text); }
        .messages-container {
            flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 6px;
        }
        .message {
            max-width: 75%; padding: 6px 10px; border-radius: 12px; position: relative;
            word-wrap: break-word; line-height: 1.4; font-size: 0.95rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.2);
            transition: background 0.2s;
            cursor: pointer;
            color: #fff;
        }
        .message-sent { align-self: flex-end; background: var(--msg-out); border-bottom-right-radius: 2px; }
        .message-received { align-self: flex-start; background: var(--msg-in); border-bottom-left-radius: 2px; }
        .message .sender { font-size: 0.8rem; color: var(--primary); margin-bottom: 2px; font-weight: 500; display: flex; align-items: center; gap: 6px; }
        .sender-avatar {
            width: 28px; height: 28px; border-radius: 50%; object-fit: cover; cursor: pointer;
        }
        .message .time { font-size: 0.6rem; color: var(--secondary-text); margin-top: 2px; text-align: left; }
        .reactions-display {
            font-size: 0.9rem; margin-top: 4px; display: flex; gap: 4px; flex-wrap: wrap;
        }
        .message-content { }
        .message-actions { 
            display: none; 
            margin-top: 6px;
            padding-top: 4px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        .message.show-actions .message-actions { display: block; }
        .file-attachment { margin: 4px 0; background: rgba(0,0,0,0.2); padding: 4px 8px; border-radius: 8px; }
        .file-attachment img {
            max-width: 250px; max-height: 200px; border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
            display: block; margin: 5px 0;
        }
        .reactions { display: flex; align-items: center; gap: 4px; margin-top: 4px; font-size: 0.9rem; }
        .reactions button { background: transparent; border: none; cursor: pointer; padding: 2px 4px; color: #fff; }
        .delete-btn, .pin-btn, .edit-btn, .report-btn, .copy-btn, .reply-btn {
            background: transparent; border: none; cursor: pointer; font-size: 1rem;
            margin-left: 4px; color: var(--secondary-text);
        }
        .reply-block {
            background: rgba(255,255,255,0.05);
            border-right: 3px solid var(--primary);
            padding: 6px 10px;
            border-radius: 8px;
            margin-bottom: 6px;
            font-size: 0.9rem;
            color: var(--secondary-text);
        }
        .reply-block strong { color: var(--primary); }
        .message-input-area {
            position: sticky;
            bottom: 0;
            padding: 10px 12px;
            background: rgba(15,15,35,0.7);
            backdrop-filter: blur(20px);
            border-top: 1px solid var(--border);
            display: flex;
            gap: 6px;
            align-items: flex-end;
            flex-wrap: wrap;
            margin-bottom: 0;
            z-index: 50;
        }
        .message-row {
            display: flex; gap: 6px; align-items: center; width: 100%;
        }
        .message-row input[type="text"] {
            flex: 1; border: 1px solid var(--border); padding: 8px 14px; border-radius: 20px;
            background: rgba(255,255,255,0.05); color: var(--text); outline: none;
            backdrop-filter: blur(5px);
        }
        .send-btn {
            background: var(--primary); border: none; color: #fff; width: 40px; height: 40px;
            border-radius: 50%; cursor: pointer; font-size: 1.2rem; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
        }
        .send-btn:disabled { opacity: 0.5; pointer-events: none; }
        .attach-btn {
            background: rgba(255,255,255,0.1); border: 1px solid var(--border);
            color: var(--text); width: 40px; height: 40px; border-radius: 50%;
            cursor: pointer; font-size: 1.2rem; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            transition: 0.3s;
        }
        .attach-btn:hover { background: var(--primary); border-color: var(--primary); }
        .file-attach-panel {
            display: none; width: 100%; gap: 6px; flex-wrap: wrap; margin-top: 6px;
        }
        .file-attach-panel.active { display: flex; }
        .file-attach-panel input {
            flex: 1; min-width: 120px; border: 1px solid var(--border); padding: 8px 12px;
            border-radius: 12px; background: rgba(255,255,255,0.05); color: var(--text);
            backdrop-filter: blur(5px);
        }
        .notice-bar {
            padding: 12px; text-align: center; color: var(--secondary-text);
            background: rgba(0,0,0,0.3);
        }
        .restriction-banner {
            background: rgba(51, 144, 236, 0.15);
            border: 1px solid #3390ec;
            color: #b8d8ff;
            padding: 12px 16px;
            margin: 0 16px 10px;
            border-radius: 12px;
            font-size: 0.9rem;
            text-shadow: 0 0 2px rgba(0,0,0,0.8);
        }
        .btn-join {
            background: var(--primary); color: #fff; border: none; padding: 8px 24px;
            border-radius: 20px; cursor: pointer;
        }
        .message a { color: #3390ec; text-decoration: underline; }
        .mention { color: #3390ec; cursor: pointer; text-decoration: underline; }
        @media (max-width: 768px) {
            body { padding-bottom: 120px; }
            body.nav-hidden { padding-bottom: 20px; }
            .sidebar { position: fixed; top:0; right:0; bottom:0; width:85%; transform: translateX(100%); transition:0.3s; }
            .sidebar.open { transform: translateX(0); }
            .main { width:100%; }
            .hamburger { display: flex !important; }
            .chat-list { padding-bottom: 80px; }
        }
        .hamburger { display: none; }
        .modal-overlay {
            position: fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7);
            display: flex; align-items: center; justify-content: center; z-index:1000;
        }
        .modal {
            background: #1a1a3e; padding: 20px; border-radius: 16px; width:90%; max-width:400px;
            display: flex; flex-direction: column; gap:10px; border:1px solid var(--border); color:#fff;
        }
        .modal input, .modal textarea {
            width:100%; padding:10px; border-radius:8px; border:1px solid var(--border);
            background:rgba(0,0,0,0.3); color:#fff;
        }
        .modal button {
            background: var(--primary); border: none; color: #fff; padding: 8px 16px;
            border-radius: 8px; cursor: pointer;
        }
        .bottom-nav {
            position: fixed; bottom: 15px; left: 50%; transform: translateX(-50%);
            width: 90%; max-width: 480px;
            background: rgba(15, 15, 35, 0.8); backdrop-filter: blur(30px);
            display: flex; justify-content: space-around; align-items: center;
            padding: 6px 10px; border-radius: 35px;
            box-shadow: 0 20px 35px rgba(0,0,0,0.7), 0 0 0 1px rgba(255,77,109,0.4);
            z-index: 100; border: 1px solid rgba(255,255,255,0.15);
            opacity: 1; transition: opacity 0.3s;
        }
        .bottom-nav .nav-item {
            display: flex; flex-direction: column; align-items: center; text-decoration: none;
            color: #999; font-size: 0.65rem; font-weight: 500; padding: 6px 8px;
            border-radius: 20px; transition: color 0.3s, transform 0.3s;
        }
        .bottom-nav .nav-item .icon { font-size: 1.5rem; margin-bottom: 2px; transition: all 0.3s; }
        .bottom-nav .nav-item.active .icon { color: #ff4d6d; filter: drop-shadow(0 0 10px #ff4d6d); }
        .bottom-nav .nav-item.active span { color: #fff; font-weight: bold; }
        .bottom-nav .nav-item:not(.active):hover .icon { color: #ff8fa3; transform: translateY(-3px); }
    </style>
    <link rel="stylesheet" href="messenger.css?v=2">
</head>
<body class="<?= in_array($page, ['chat', 'group'], true) ? 'in-conversation' : '' ?>">
<div class="particles">
    <div class="particle"></div><div class="particle"></div><div class="particle"></div>
    <div class="particle"></div><div class="particle"></div><div class="particle"></div>
</div>
<div class="sidebar-backdrop" onclick="closeSidebar()" aria-hidden="true"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="user-info">
            <span><?= htmlspecialchars($current_user['display_name'] ?? $current_user['first_name']) ?></span>
            <span class="online-dot <?= $current_user['is_online'] ? '' : 'offline-dot' ?>"></span>
        </div>
        <div style="display:flex; gap:8px;">
            <button onclick="toggleNavVisibility()" class="btn-icon" title="مخفی/نمایش نوار">👁️</button>
            <a href="logout.php" class="btn-icon" title="خروج">←</a>
        </div>
    </div>
    <div class="search-box">
        <input type="text" id="searchInput" placeholder="جستجوی کاربر، گروه یا کانال..." autocomplete="off">
        <div class="search-results" id="searchResults"></div>
    </div>
    <div class="chat-list">
        <a href="?page=home" class="chat-item <?= $page=='home'?'active':'' ?>" onclick="closeSidebar()">
            <div class="avatar" style="background:#c4c9cc;">🏠</div>
            <div class="chat-details"><div class="chat-name">همه گفتگوها</div></div>
        </a>
        <div style="padding:6px 16px; font-size:0.8rem; color:var(--secondary-text);">💬 پیام‌های خصوصی</div>
        <?php
        $chats_sidebar = $pdo->prepare("
            SELECT u.id, u.display_name, u.first_name, u.last_name, u.avatar_url, u.is_online, u.last_seen,
                (SELECT COUNT(*) FROM private_messages WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0 AND deleted_at IS NULL) as unread,
                (SELECT message FROM private_messages WHERE ((sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id)) AND deleted_at IS NULL ORDER BY sent_at DESC LIMIT 1) as last_msg
            FROM users u
            WHERE u.id IN (SELECT DISTINCT sender_id FROM private_messages WHERE receiver_id = ? AND deleted_at IS NULL UNION SELECT DISTINCT receiver_id FROM private_messages WHERE sender_id = ? AND deleted_at IS NULL) AND u.id != ?
            ORDER BY (SELECT MAX(sent_at) FROM private_messages WHERE ((sender_id=u.id AND receiver_id=?) OR (sender_id=? AND receiver_id=u.id)) AND deleted_at IS NULL) DESC
        ");
        $chats_sidebar->execute([$current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id]);
        while ($chat = $chats_sidebar->fetch(PDO::FETCH_ASSOC)):
            $name = $chat['display_name'] ?: $chat['first_name'].' '.$chat['last_name'];
            $unread = $chat['unread'] > 0 ? '<span class="unread-badge" data-chat-type="private" data-chat-id="'.$chat['id'].'">'.$chat['unread'].'</span>' : '<span class="unread-badge" data-chat-type="private" data-chat-id="'.$chat['id'].'" style="display:none;"></span>';
            $online = $chat['is_online'] ? '<span class="online-dot"></span>' : '<span class="online-dot offline-dot"></span>';
            $preview = $chat['last_msg'] ? mb_substr($chat['last_msg'], 0, 30) : '';
            $active = ($page=='chat' && $user_chat_id == $chat['id']) ? 'active' : '';
            echo '<a href="?page=chat&user='.$chat['id'].'" class="chat-item '.$active.'" onclick="closeSidebar()">';
            if (!empty($chat['avatar_url'])) {
                echo '<div class="avatar"><img src="'.htmlspecialchars($chat['avatar_url']).'" alt="'.htmlspecialchars($name).'"></div>';
            } else {
                echo '<div class="avatar">'.mb_substr($name,0,1).'</div>';
            }
            echo '<div class="chat-details"><div class="chat-name">'.htmlspecialchars($name).' '.$online.' '.$unread.'</div><div class="chat-preview">'.htmlspecialchars($preview ?: 'گفتگوی خصوصی').'</div></div>';
            echo '</a>';
        endwhile;
        ?>
        <div style="padding:6px 16px; font-size:0.8rem; color:var(--secondary-text);">👥 گروه‌ها و کانال‌ها</div>
        <?php
        $groups_sidebar = $pdo->prepare("
            SELECT g.*, 
                (SELECT COUNT(*) FROM group_channel_messages gm WHERE gm.group_id = g.id AND gm.deleted_at IS NULL AND gm.id > COALESCE(m.last_read_message_id, 0) AND gm.sender_id != ?) as unread,
                (SELECT message FROM group_channel_messages gm2 WHERE gm2.group_id = g.id AND gm2.deleted_at IS NULL ORDER BY sent_at DESC LIMIT 1) as last_msg
            FROM groups_channels g JOIN group_channel_members m ON g.id = m.group_id AND m.user_id = ?
            ORDER BY (SELECT MAX(sent_at) FROM group_channel_messages WHERE group_id = g.id AND deleted_at IS NULL) DESC
        ");
        $groups_sidebar->execute([$current_user_id, $current_user_id]);
        while ($g = $groups_sidebar->fetch(PDO::FETCH_ASSOC)):
            $icon = $g['type']=='channel' ? '📢' : '👥';
            $groupUrl = !empty($g['slug']) ? "?page=group&slug=".urlencode($g['slug']) : "?page=group&id=".$g['id'];
            $active = ($page=='group' && $target_id == $g['id']) ? 'active' : '';
            $unreadBadge = $g['unread'] > 0 ? '<span class="unread-badge" data-chat-type="group" data-chat-id="'.$g['id'].'">'.$g['unread'].'</span>' : '<span class="unread-badge" data-chat-type="group" data-chat-id="'.$g['id'].'" style="display:none;"></span>';
            $preview = $g['last_msg'] ? mb_substr($g['last_msg'], 0, 30) : '';
            echo '<a href="'.$groupUrl.'" class="chat-item '.$active.'" onclick="closeSidebar()">';
            if (!empty($g['avatar_url'])) {
                echo '<div class="avatar"><img src="'.htmlspecialchars($g['avatar_url']).'" alt="'.htmlspecialchars($g['name']).'"></div>';
            } else {
                echo '<div class="avatar" style="background:#'.substr(md5($g['name']),0,6).';">'.$icon.'</div>';
            }
            echo '<div class="chat-details"><div class="chat-name">'.htmlspecialchars($g['name']).' '.$unreadBadge.'</div><div class="chat-preview">'.htmlspecialchars($preview ?: ($g['type']=='channel'?'کانال':'گروه')).'</div></div>';
            echo '</a>';
        endwhile;
        ?>
        <a href="create_group.php" class="chat-item" onclick="closeSidebar()">
            <div class="avatar" style="background:var(--primary);">➕</div>
            <div class="chat-details"><div class="chat-name">ساخت گروه/کانال جدید</div></div>
        </a>
    </div>
</div>

<div class="main">
<?php if ($page == 'home'): ?>
    <div class="main-header">
        <button class="hamburger btn-icon" onclick="toggleSidebar()">☰</button>
        <h3>همه گفتگوها</h3>
        <div style="display:flex; gap:8px;">
            <button onclick="toggleNavVisibility()" class="btn-icon" title="مخفی/نمایش نوار">👁️</button>
        </div>
    </div>
    <?php if ($restrictionInfo): ?>
        <?php
            $typeText = '';
            switch ($restrictionInfo['type']) {
                case 'message_ban': $typeText = 'ممنوعیت ارسال پیام (به جز گروه‌هایی که مالک آن هستید)'; break;
                case 'mute': $typeText = 'سکوت (عدم امکان ارسال پیام)'; break;
                default: $typeText = $restrictionInfo['type'];
            }
            $remainingDays = floor($restrictionInfo['remaining'] / 86400);
            $remainingHours = floor(($restrictionInfo['remaining'] % 86400) / 3600);
        ?>
        <div class="restriction-banner">
            ⚠️ حساب شما دارای محدودیت است:<br>
            <strong>نوع:</strong> <?= $typeText ?> |
            <strong>دلیل:</strong> <?= htmlspecialchars($restrictionInfo['reason']) ?><br>
            <strong>پایان:</strong> <?= date('Y/m/d H:i', strtotime($restrictionInfo['until'])) ?> |
            <strong>زمان باقی‌مانده:</strong> <?= $remainingDays ?> روز و <?= $remainingHours ?> ساعت
        </div>
    <?php endif; ?>
    <div class="chat-list" style="flex:1; overflow-y:auto; padding:8px 0 100px 0;">
        <?php if (empty($all_conversations)): ?>
            <div style="text-align:center; color:var(--secondary-text); padding:40px;">هنوز گفتگویی نداری 🙃</div>
        <?php else: ?>
            <?php foreach ($all_conversations as $conv): ?>
                <a href="<?= $conv['link'] ?>" class="chat-item">
                    <div class="avatar" style="<?= !empty($conv['avatar_img']) ? '' : 'background:'.($conv['type'] == 'private' ? 'var(--primary)' : '#'.substr(md5($conv['name']),0,6)) ?>">
                        <?php if (!empty($conv['avatar_img'])): ?>
                            <img src="<?= htmlspecialchars($conv['avatar_img']) ?>" alt="<?= htmlspecialchars($conv['name']) ?>">
                        <?php else: ?>
                            <?= htmlspecialchars($conv['avatar']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="chat-details">
                        <div class="chat-name">
                            <?= htmlspecialchars($conv['name']) ?>
                            <?php if ($conv['type'] == 'private' && $conv['is_online']): ?><span class="online-dot"></span><?php elseif ($conv['type'] == 'private'): ?><span class="online-dot offline-dot"></span><?php endif; ?>
                            <span class="unread-badge" data-chat-type="<?= $conv['type'] ?>" data-chat-id="<?= $conv['id'] ?>" style="<?= $conv['unread'] > 0 ? '' : 'display:none;' ?>"><?= $conv['unread'] ?></span>
                        </div>
                        <div class="chat-preview"><?= $conv['last_msg'] ? htmlspecialchars(mb_substr($conv['last_msg'], 0, 35)) : ($conv['type'] == 'group' ? (($conv['kind'] ?? '') === 'channel' ? 'کانال' : 'گروه') : 'گفتگوی خصوصی') ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php elseif ($page == 'chat' && $user_chat_id): ?>
    <?php
    $chat_user = getUserInfo($pdo, $user_chat_id);
    if (!$chat_user): ?>
        <div class="main-header"><button class="hamburger btn-icon" onclick="toggleSidebar()">☰</button><h3>کاربر یافت نشد</h3></div>
    <?php else:
        $blocked = $pdo->prepare("SELECT 1 FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?");
        $blocked->execute([$user_chat_id, $current_user_id]);
        $is_blocked = $blocked->fetchColumn();

        $blocked_by_me = $pdo->prepare("SELECT 1 FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?");
        $blocked_by_me->execute([$current_user_id, $user_chat_id]);
        $i_blocked_him = $blocked_by_me->fetchColumn();

        $can_send_private = true;
        if ($isMessageBanned || $isMuted) {
            $can_send_private = false;
        }
    ?>
        <div class="main-header">
            <button class="hamburger btn-icon" onclick="toggleSidebar()">☰</button>
            <div class="chat-info">
                <div class="avatar">
                    <?php if (!empty($chat_user['avatar_url'])): ?>
                        <img src="<?= htmlspecialchars($chat_user['avatar_url']) ?>" alt="<?= htmlspecialchars($chat_user['display_name'] ?? $chat_user['first_name']) ?>">
                    <?php else: ?>
                        <?= mb_substr($chat_user['display_name'] ?: $chat_user['first_name'], 0, 1) ?>
                    <?php endif; ?>
                </div>
                <h3><?= htmlspecialchars($chat_user['display_name'] ?: $chat_user['first_name'].' '.$chat_user['last_name']) ?>
                    <span class="online-dot <?= $chat_user['is_online'] ? '' : 'offline-dot' ?>"></span>
                </h3>
            </div>
            <div style="display:flex; gap:4px;">
                <button onclick="openMessageSearch()" class="btn-icon" title="جستجو در پیام‌ها" aria-label="جستجو در پیام‌ها">🔍</button>
                <button onclick="toggleNavVisibility()" class="btn-icon" title="مخفی/نمایش نوار">👁️</button>
                <button onclick="blockUser(<?= $user_chat_id ?>, <?= $i_blocked_him ? 'true' : 'false' ?>)" class="btn-icon"><?= $i_blocked_him ? '🔓' : '🚫' ?></button>
            </div>
        </div>
        <div class="messages-container" id="privateMessages"></div>

        <?php if ($is_blocked): ?>
            <div class="notice-bar">⛔ شما توسط این کاربر مسدود شده‌اید.</div>
        <?php elseif ($i_blocked_him): ?>
            <div class="notice-bar">🚫 این کاربر را مسدود کرده‌اید؛ برای ارسال پیام، ابتدا مسدودیت را بردارید.</div>
        <?php elseif (!$can_send_private): ?>
            <div class="notice-bar">⛔ شما اجازه ارسال پیام خصوصی ندارید.</div>
        <?php else: ?>
            <form id="privateForm" class="message-input-area" autocomplete="off">
                <input type="hidden" name="type" value="private">
                <input type="hidden" name="receiver_id" value="<?= $user_chat_id ?>">
                <div class="message-row">
                    <textarea name="message" id="privateInput" class="message-composer" rows="1" placeholder="پیام..." autocomplete="off"></textarea>
                    <button type="button" class="attach-btn" onclick="toggleAttachPanel('privateAttach')" title="افزودن لینک رسانه">🔗</button>
                    <button type="submit" class="send-btn" id="privateSendBtn">➤</button>
                </div>
                <div class="file-attach-panel" id="privateAttach">
                    <input type="url" name="file_url" placeholder="پیوند HTTPS رسانه (دامنه مجاز)">
                    <input type="text" name="file_description" placeholder="توضیح پیوند، اختیاری">
                    <small class="link-only-note">فایل روی سرور پیام‌رسان بارگذاری نمی‌شود؛ فقط لینک ذخیره می‌شود.</small>
                </div>
            </form>
        <?php endif; ?>

        <script>
            const chatUserId = <?= $user_chat_id ?>;
            function isImageUrl(url) { return /\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i.test(url); }
            function renderFileAttachment(fileUrl, fileDesc) {
                let safeUrl;
                try {
                    const parsed = new URL(fileUrl, window.location.href);
                    if (parsed.protocol !== 'https:') return '<div class="file-attachment">پیوند رسانه نامعتبر است.</div>';
                    safeUrl = escapeHtml(parsed.href);
                } catch (error) {
                    return '<div class="file-attachment">پیوند رسانه نامعتبر است.</div>';
                }
                const description = fileDesc ? `<br><small>${escapeHtml(fileDesc)}</small>` : '';
                const label = isImageUrl(safeUrl) ? '🖼️ مشاهده تصویر' : '🔗 بازکردن پیوند رسانه';
                return `<div class="file-attachment"><a href="${safeUrl}" target="_blank" rel="noopener noreferrer">${label}</a>${description}</div>`;
            }
            function toggleActions(div) { div.classList.toggle('show-actions'); }
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            function stripHtml(html) {
                const tmp = document.createElement('div');
                tmp.innerHTML = html;
                return tmp.textContent || tmp.innerText || '';
            }
            function copyMessage(msgId) {
                const msgDiv = document.querySelector(`.message[data-id="${msgId}"]`);
                if (!msgDiv) return;
                const rawText = stripHtml(msgDiv.querySelector('.message-content')?.innerHTML || '');
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(rawText).then(() => alert('پیام کپی شد.'));
                } else {
                    prompt('متن پیام:', rawText);
                }
            }
            function replyMessage(msgId, senderName, originalText) {
                const input = document.getElementById('privateInput');
                if (!input) return;
                const replyTag = `[reply2:${encodeURIComponent(JSON.stringify({id: msgId, name: senderName, text: originalText}))}] `;
                const draft = input.value.replace(/^\[reply2:[^\]]+\]\s*/, '');
                input.value = replyTag + draft;
                input.focus();
            }
            function formatMessage(rawText) {
                const forwarded = rawText.match(/^\[forward:v1:([A-Za-z0-9+/=]+)\]\s*/);
                if (forwarded) {
                    try {
                        const bytes = Uint8Array.from(atob(forwarded[1]), character => character.charCodeAt(0));
                        const attribution = JSON.parse(new TextDecoder().decode(bytes));
                        const rest = rawText.substring(forwarded[0].length);
                        const conversation = attribution.conversation ? `<span class="forwarded-conversation">· ${escapeHtml(attribution.conversation)}</span>` : '';
                        const content = rest ? formatMessage(rest) : '';
                        return `<div class="forwarded-block">↪️ فورواردشده از <strong>${escapeHtml(attribution.name || 'کاربر')}</strong>${conversation}</div>${content}`;
                    } catch (error) {}
                }
                const modernReply = rawText.match(/^\[reply2:([^\]]+)\]\s*/);
                if (modernReply) {
                    try {
                        const payload = JSON.parse(decodeURIComponent(modernReply[1]));
                        const rest = rawText.substring(modernReply[0].length);
                        let html = `<div class="reply-block"><strong>${escapeHtml(payload.name || '')}</strong><br>${escapeHtml(payload.text || '')}</div>${escapeHtml(rest)}`;
                        html = html.replace(/\b(https?:\/\/[^\s<>]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
                        html = html.replace(/@([a-zA-Z0-9_]+)/g, '<span class="mention" data-username="$1" onclick="resolveAndOpenChat(\'$1\')">@$1</span>');
                        return html;
                    } catch (error) {}
                }

                                    const replyRegex = /^\[reply:(\d+):([^:]+):([^\]]+)\]\s*/;
                const match = rawText.match(replyRegex);
                if (match) {
                    const repliedId = match[1];
                    const repliedName = match[2];
                    const repliedText = match[3];
                    const rest = rawText.substring(match[0].length);
                    let html = `<div class="reply-block"><strong>${escapeHtml(repliedName)}</strong><br>${escapeHtml(repliedText)}</div>${escapeHtml(rest)}`;
                    html = html.replace(/\b(https?:\/\/[^\s<>]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
                    html = html.replace(/@([a-zA-Z0-9_]+)/g, '<span class="mention" data-username="$1" onclick="resolveAndOpenChat(\'$1\')">@$1</span>');
                    return html;
                }
                let text = escapeHtml(rawText);
                text = text.replace(/\b(https?:\/\/[^\s<>]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
                text = text.replace(/@([a-zA-Z0-9_]+)/g, '<span class="mention" data-username="$1" onclick="resolveAndOpenChat(\'$1\')">@$1</span>');
                return text;
            }
            async function resolveAndOpenChat(username) {
                try {
                    const res = await fetch(`index.php?action=resolve_username&username=${encodeURIComponent(username)}`);
                    const data = await res.json();
                    if (data.success) window.location.href = `?page=chat&user=${data.user_id}`;
                    else alert(data.error || 'کاربر یافت نشد');
                } catch (e) { alert('خطا در ارتباط با سرور'); }
            }

            const privateContainer = document.getElementById('privateMessages');
            let oldestPrivateMsgId = null;
            let newestPrivateMsgId = null;
            let isLoadingOlderPrivate = false;
            let noMoreOlderPrivate = false;

            function createPrivateMessageElement(msg) {
                const isMine = msg.sender_id == <?= $current_user_id ?>;
                const div = document.createElement('div');
                div.className = `message ${isMine ? 'message-sent' : 'message-received'}`;
                div.setAttribute('data-id', msg.id);
                div.addEventListener('click', function(e) {
                    if (e.target.closest('button') || e.target.closest('.mention')) return;
                    div.classList.toggle('show-actions');
                });
                let contentHtml = '';
                if (!isMine) contentHtml += `<div class="sender">${escapeHtml(msg.sender_name)}</div>`;
                if (msg.file_url) contentHtml += renderFileAttachment(msg.file_url, msg.file_description);
                contentHtml += formatMessage(msg.message || '');
                if (msg.all_emojis && msg.all_emojis.length) contentHtml += `<div class="reactions-display">${msg.all_emojis.map(escapeHtml).join(' ')}</div>`;
                contentHtml += `<div class="time">${escapeHtml(msg.sent_at || '')}</div>`;
                div.innerHTML = `<div class="message-content">${contentHtml}</div>`;
                let actionsHtml = '<div class="message-actions">';
                actionsHtml += '<div class="reactions">';
                if (msg.user_emoji) actionsHtml += `<span class="user-reaction">${escapeHtml(msg.user_emoji)}</span>`;
                ['👍','❤️','😂','😢','😡'].forEach(emoji => actionsHtml += `<button onclick="reactPrivate(${msg.id}, '${emoji}')">${emoji}</button>`);
                actionsHtml += '</div>';
                actionsHtml += `<button class="copy-btn" onclick="copyMessage(${msg.id})" title="کپی پیام">📋</button>`;
                actionsHtml += `<button class="reply-btn" data-reply-id="${msg.id}" data-reply-name="${encodeURIComponent(msg.sender_name || '')}" data-reply-text="${encodeURIComponent(msg.message || '')}" title="پاسخ" aria-label="پاسخ به پیام">↩️</button>`;
                actionsHtml += `<button class="forward-btn" onclick="openForwardModal(${msg.id}, 'private')" title="فوروارد پیام" aria-label="فوروارد پیام">↗️</button>`;
                if (isMine) {
                    actionsHtml += `<button class="edit-btn" onclick="editMessage(${msg.id}, 'private')">✏️</button>`;
                    actionsHtml += `<button class="delete-btn" onclick="deleteMsg(${msg.id}, 'private')">🗑️</button>`;
                }
                actionsHtml += `<button class="report-btn" onclick="reportMessage(${msg.id}, 'message')">⚠️</button>`;
                actionsHtml += '</div>';
                div.innerHTML += actionsHtml;
                return div;
            }

            function appendPrivateMessages(messages, prepend = false) {
                const fragment = document.createDocumentFragment();
                messages.forEach(msg => {
                    if (privateContainer.querySelector(`.message[data-id="${msg.id}"]`)) return;
                    fragment.appendChild(createPrivateMessageElement(msg));
                });
                if (prepend) privateContainer.insertBefore(fragment, privateContainer.firstChild);
                else privateContainer.appendChild(fragment);
            }

            function updatePrivateCounters(messages) {
                messages.forEach(msg => {
                    if (msg.id > newestPrivateMsgId) newestPrivateMsgId = msg.id;
                    if (oldestPrivateMsgId === null || msg.id < oldestPrivateMsgId) oldestPrivateMsgId = msg.id;
                });
            }

            async function loadInitialPrivate() {
                const res = await fetch(`load_private.php?user=${chatUserId}`);
                const messages = await res.json();
                if (messages.length === 0) {
                    privateContainer.innerHTML = '<div style="color:var(--secondary-text); text-align:center; padding:40px;">پیامی نیست</div>';
                    fetch('mark_private_read.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'user_id='+chatUserId });
                    return;
                }
                privateContainer.innerHTML = '';
                newestPrivateMsgId = messages[messages.length - 1].id;
                oldestPrivateMsgId = messages[0].id;
                noMoreOlderPrivate = messages.length < 50;
                appendPrivateMessages(messages, false);
                privateContainer.scrollTop = privateContainer.scrollHeight;
                fetch('mark_private_read.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'user_id='+chatUserId });
            }

            async function loadNewerPrivate() {
                if (!newestPrivateMsgId) return;
                const res = await fetch(`load_private.php?user=${chatUserId}&after=${newestPrivateMsgId}`);
                const messages = await res.json();
                if (messages.length > 0) {
                    const isNearBottom = privateContainer.scrollTop + privateContainer.clientHeight >= privateContainer.scrollHeight - 100;
                    appendPrivateMessages(messages, false);
                    updatePrivateCounters(messages);
                    if (isNearBottom) {
                        privateContainer.scrollTop = privateContainer.scrollHeight;
                        fetch('mark_private_read.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'user_id='+chatUserId });
                    }
                }
                checkDeletedPrivateMessages();
            }

            async function checkDeletedPrivateMessages() {
                const messageElements = privateContainer.querySelectorAll('.message[data-id]');
                if (messageElements.length === 0) return;
                const ids = Array.from(messageElements).map(el => parseInt(el.getAttribute('data-id')));
                const formData = new FormData();
                formData.append('type', 'private');
                ids.forEach(id => formData.append('message_ids[]', id));
                try {
                    const res = await fetch('index.php?action=check_deleted', { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.deleted_ids && data.deleted_ids.length > 0) {
                        data.deleted_ids.forEach(id => {
                            const el = privateContainer.querySelector(`.message[data-id="${id}"]`);
                            if (el) el.remove();
                        });
                    }
                } catch (e) {}
            }

            async function loadOlderPrivate() {
                if (!oldestPrivateMsgId || isLoadingOlderPrivate || noMoreOlderPrivate) return false;
                isLoadingOlderPrivate = true;
                try {
                    const res = await fetch(`load_private.php?user=${chatUserId}&before=${oldestPrivateMsgId}`);
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    const messages = await res.json();
                    if (messages.length === 0) {
                        noMoreOlderPrivate = true;
                        return false;
                    }
                    const previousHeight = privateContainer.scrollHeight;
                    appendPrivateMessages(messages, true);
                    updatePrivateCounters(messages);
                    if (messages.length < 50) noMoreOlderPrivate = true;
                    privateContainer.scrollTop += privateContainer.scrollHeight - previousHeight;
                    return true;
                } catch (error) {
                    console.error('Could not load older private messages', error);
                    return false;
                } finally {
                    isLoadingOlderPrivate = false;
                }
            }

            window.jumpToMessage = async function(messageId) {
                let message = privateContainer.querySelector(`.message[data-id="${messageId}"]`);
                let pages = 0;
                while (!message && !noMoreOlderPrivate && pages < 30) {
                    if (!await loadOlderPrivate()) break;
                    pages++;
                    message = privateContainer.querySelector(`.message[data-id="${messageId}"]`);
                }
                if (!message) { alert('پیام در دسترس نیست یا دیگر در این گفتگو وجود ندارد.'); return; }
                message.scrollIntoView({behavior:'smooth', block:'center'});
                message.classList.add('message-highlight');
                setTimeout(() => message.classList.remove('message-highlight'), 1800);
            };

            privateContainer.addEventListener('scroll', () => {
                if (privateContainer.scrollTop <= 50) loadOlderPrivate();
            });

            setInterval(loadNewerPrivate, 5000);
            loadInitialPrivate();

            async function reactPrivate(messageId, emoji) {
                const currentReaction = document.querySelector(`.message[data-id="${messageId}"] .user-reaction`)?.innerText;
                const action = (currentReaction === emoji) ? 'remove' : 'add';
                try {
                    const response = await fetch('react_message.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`message_id=${messageId}&emoji=${encodeURIComponent(emoji)}&action=${action}&type=private` });
                    const result = await response.json();
                    if (!result.success) { alert(result.error || 'ثبت واکنش ناموفق بود.'); return; }
                    await loadInitialPrivate();
                } catch (error) { alert('ثبت واکنش انجام نشد؛ اتصال را بررسی کنید.'); }
            }
            const privateForm = document.getElementById('privateForm');
            if (privateForm) privateForm.addEventListener('submit', async e => {
                e.preventDefault();
                const sendBtn = document.getElementById('privateSendBtn');
                if (!privateForm.querySelector('[name="message"]').value.trim() && !privateForm.querySelector('[name="file_url"]').value.trim()) return;
                sendBtn.disabled = true;
                try {
                    const res = await fetch('send_message.php', { method:'POST', body: new FormData(privateForm) });
                    const result = await res.json();
                    if (result.success) {
                        document.getElementById('privateInput').value = '';
                        document.getElementById('privateInput').style.height = '46px';
                        privateForm.querySelector('[name="file_url"]').value = '';
                        privateForm.querySelector('[name="file_description"]').value = '';
                        document.getElementById('privateAttach').classList.remove('active');
                        await loadInitialPrivate();
                    } else alert(result.error || 'ارسال پیام ناموفق بود.');
                } catch (error) {
                    alert('ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.');
                } finally {
                    sendBtn.disabled = false;
                }
            });
        </script>
    <?php endif; ?>

<?php elseif ($page == 'group' && $target_id): ?>
    <?php
    $group = $pdo->prepare("SELECT * FROM groups_channels WHERE id = ?");
    $group->execute([$target_id]);
    $group = $group->fetch();
    if (!$group): ?>
        <div class="main-header"><h3>یافت نشد</h3></div>
    <?php else:
        $member = $pdo->prepare("SELECT * FROM group_channel_members WHERE group_id=? AND user_id=?");
        $member->execute([$target_id, $current_user_id]);
        $member = $member->fetch();
        $is_member = $member ? true : false;
        $role = $member['role'] ?? 'guest';
        $is_admin_base = ($role == 'owner' || $role == 'admin' || $group['created_by'] == $current_user_id);
        $is_owner = ($group['created_by'] == $current_user_id);
        $group_permissions = [];
        if ($member && $role === 'admin') {
            $permissionStmt = $pdo->prepare("SELECT can_edit_messages, can_delete_messages, can_pin_message, can_send_message FROM admin_permissions WHERE member_id = ?");
            $permissionStmt->execute([$member['id']]);
            $group_permissions = $permissionStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $is_group_owner = $is_owner || $role === 'owner';
        $can_edit_group_messages = $is_group_owner || !empty($group_permissions['can_edit_messages']);
        $can_delete_group_messages = $is_group_owner || !empty($group_permissions['can_delete_messages']);
        $can_pin_group_messages = $is_group_owner || !empty($group_permissions['can_pin_message']);

        if ($isMuted) {
            $can_send = false;
        } elseif ($isMessageBanned) {
            $can_send = $is_owner;
        } elseif ($is_owner || $role === 'owner') {
            $can_send = true;
        } elseif ($role === 'admin') {
            $can_send = !empty($group_permissions['can_send_message']);
        } else {
            $can_send = ($group['type'] === 'group' && $is_member && !empty($member['can_send_message']));
        }

        $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM group_channel_members WHERE group_id = ?");
        $cnt_stmt->execute([$target_id]);
        $member_count = $cnt_stmt->fetchColumn();
    ?>
        <div class="main-header">
            <button class="hamburger btn-icon" onclick="toggleSidebar()">☰</button>
            <div class="chat-info">
                <?php if (!empty($group['avatar_url'])): ?>
                    <div class="avatar"><img src="<?= htmlspecialchars($group['avatar_url']) ?>" alt="آواتار"></div>
                <?php else: ?>
                    <div class="avatar" style="background:#<?= substr(md5($group['name']),0,6) ?>;"><?= $group['type']=='channel'?'📢':'👥' ?></div>
                <?php endif; ?>
                <h3><?= htmlspecialchars($group['name']) ?> <span style="font-size:0.8rem; color:var(--secondary-text);">(<?= $member_count ?> عضو)</span></h3>
            </div>
            <div style="display:flex; gap:4px;">
                <button onclick="openMessageSearch()" class="btn-icon" title="جستجو در پیام‌ها" aria-label="جستجو در پیام‌ها">🔍</button>
                <button onclick="toggleNavVisibility()" class="btn-icon" title="مخفی/نمایش نوار">👁️</button>
                <?php if ($is_admin_base): ?>
                    <a href="group_settings.php?id=<?= $target_id ?>" class="btn-icon">⚙️</a>
                <?php endif; ?>
                <?php if ($is_member && $role != 'owner'): ?>
                    <button onclick="leaveGroup(<?= $target_id ?>)" class="btn-icon" title="خروج">🚪</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($is_member || $is_admin_base): ?>
            <div class="messages-container" id="groupMessages"></div>
            <?php if (!$can_send): ?>
                <div class="notice-bar">🔇 شما اجازه ارسال پیام ندارید.</div>
            <?php else: ?>
                <form id="groupForm" class="message-input-area" autocomplete="off">
                    <input type="hidden" name="type" value="group">
                    <input type="hidden" name="group_id" value="<?= $target_id ?>">
                    <div class="message-row">
                        <textarea name="message" id="groupInput" class="message-composer" rows="1" placeholder="پیام..." autocomplete="off"></textarea>
                        <button type="button" class="attach-btn" onclick="toggleAttachPanel('groupAttach')" title="افزودن لینک رسانه">🔗</button>
                        <button type="submit" class="send-btn" id="groupSendBtn">➤</button>
                    </div>
                    <div class="file-attach-panel" id="groupAttach">
                        <input type="url" name="file_url" placeholder="پیوند HTTPS رسانه (دامنه مجاز)">
                        <input type="text" name="file_description" placeholder="توضیح پیوند، اختیاری">
                        <small class="link-only-note">فایل روی سرور پیام‌رسان بارگذاری نمی‌شود؛ فقط لینک ذخیره می‌شود.</small>
                    </div>
                </form>
            <?php endif; ?>

            <script>
                const groupId = <?= $target_id ?>;
                function isImageUrl(url) { return /\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i.test(url); }
                function renderFileAttachment(fileUrl, fileDesc) {
                    let safeUrl;
                    try {
                        const parsed = new URL(fileUrl, window.location.href);
                        if (parsed.protocol !== 'https:') return '<div class="file-attachment">پیوند رسانه نامعتبر است.</div>';
                        safeUrl = escapeHtml(parsed.href);
                    } catch (error) {
                        return '<div class="file-attachment">پیوند رسانه نامعتبر است.</div>';
                    }
                    const description = fileDesc ? `<br><small>${escapeHtml(fileDesc)}</small>` : '';
                    const label = isImageUrl(safeUrl) ? '🖼️ مشاهده تصویر' : '🔗 بازکردن پیوند رسانه';
                    return `<div class="file-attachment"><a href="${safeUrl}" target="_blank" rel="noopener noreferrer">${label}</a>${description}</div>`;
                }
                function toggleActions(div) { div.classList.toggle('show-actions'); }
                function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
                function stripHtml(html) {
                    const tmp = document.createElement('div');
                    tmp.innerHTML = html;
                    return tmp.textContent || tmp.innerText || '';
                }
                function copyMessage(msgId) {
                    const msgDiv = document.querySelector(`.message[data-id="${msgId}"]`);
                    if (!msgDiv) return;
                    const rawText = stripHtml(msgDiv.querySelector('.message-content')?.innerHTML || '');
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(rawText).then(() => alert('پیام کپی شد.'));
                    } else {
                        prompt('متن پیام:', rawText);
                    }
                }
                function replyMessage(msgId, senderName, originalText) {
                    const input = document.getElementById('groupInput');
                    if (!input) return;
                    const replyTag = `[reply2:${encodeURIComponent(JSON.stringify({id: msgId, name: senderName, text: originalText}))}] `;
                    const draft = input.value.replace(/^\[reply2:[^\]]+\]\s*/, '');
                    input.value = replyTag + draft;
                    input.focus();
                }
                function formatMessage(rawText) {
                        const forwarded = rawText.match(/^\[forward:v1:([A-Za-z0-9+/=]+)\]\s*/);
                        if (forwarded) {
                            try {
                                const bytes = Uint8Array.from(atob(forwarded[1]), character => character.charCodeAt(0));
                                const attribution = JSON.parse(new TextDecoder().decode(bytes));
                                const rest = rawText.substring(forwarded[0].length);
                                const conversation = attribution.conversation ? `<span class="forwarded-conversation">· ${escapeHtml(attribution.conversation)}</span>` : '';
                                const content = rest ? formatMessage(rest) : '';
                                return `<div class="forwarded-block">↪️ فورواردشده از <strong>${escapeHtml(attribution.name || 'کاربر')}</strong>${conversation}</div>${content}`;
                            } catch (error) {}
                        }
                        const modernReply = rawText.match(/^\[reply2:([^\]]+)\]\s*/);
                    if (modernReply) {
                        try {
                            const payload = JSON.parse(decodeURIComponent(modernReply[1]));
                            const rest = rawText.substring(modernReply[0].length);
                            let html = `<div class="reply-block"><strong>${escapeHtml(payload.name || '')}</strong><br>${escapeHtml(payload.text || '')}</div>${escapeHtml(rest)}`;
                            html = html.replace(/\b(https?:\/\/[^\s<>]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
                            html = html.replace(/@([a-zA-Z0-9_]+)/g, '<span class="mention" data-username="$1" onclick="resolveAndOpenChat(\'$1\')">@$1</span>');
                            return html;
                        } catch (error) {}
                    }

                                    const replyRegex = /^\[reply:(\d+):([^:]+):([^\]]+)\]\s*/;
                    const match = rawText.match(replyRegex);
                    if (match) {
                        const repliedId = match[1];
                        const repliedName = match[2];
                        const repliedText = match[3];
                        const rest = rawText.substring(match[0].length);
                        let html = `<div class="reply-block"><strong>${escapeHtml(repliedName)}</strong><br>${escapeHtml(repliedText)}</div>${escapeHtml(rest)}`;
                        html = html.replace(/\b(https?:\/\/[^\s<>]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
                        html = html.replace(/@([a-zA-Z0-9_]+)/g, '<span class="mention" data-username="$1" onclick="resolveAndOpenChat(\'$1\')">@$1</span>');
                        return html;
                    }
                    let text = escapeHtml(rawText);
                    text = text.replace(/\b(https?:\/\/[^\s<>]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
                    text = text.replace(/@([a-zA-Z0-9_]+)/g, '<span class="mention" data-username="$1" onclick="resolveAndOpenChat(\'$1\')">@$1</span>');
                    return text;
                }
                async function resolveAndOpenChat(username) {
                    try {
                        const res = await fetch(`index.php?action=resolve_username&username=${encodeURIComponent(username)}`);
                        const data = await res.json();
                        if (data.success) window.location.href = `?page=chat&user=${data.user_id}`;
                        else alert(data.error || 'کاربر یافت نشد');
                    } catch (e) { alert('خطا در ارتباط با سرور'); }
                }

                const groupContainer = document.getElementById('groupMessages');
                let oldestGroupMsgId = null;
                let newestGroupMsgId = null;
                let isLoadingOlderGroup = false;
                let noMoreOlderGroup = false;

                function createGroupMessageElement(msg) {
                    const isMine = msg.sender_id == <?= $current_user_id ?>;
                    const canEdit = isMine || <?= $can_edit_group_messages ? 'true' : 'false' ?>;
                    const div = document.createElement('div');
                    div.className = `message ${isMine ? 'message-sent' : 'message-received'}`;
                    div.setAttribute('data-id', msg.id);
                    div.addEventListener('click', function(e) {
                        if (e.target.closest('button') || e.target.closest('.mention') || e.target.closest('.sender-avatar')) return;
                        div.classList.toggle('show-actions');
                    });
                    let contentHtml = '';
                    if (!isMine) {
                        // آواتار فرستنده
                        const avatarHtml = msg.avatar_url 
                            ? `<img src="${escapeHtml(msg.avatar_url)}" class="sender-avatar" onclick="event.stopPropagation(); window.location.href='?page=chat&user=${msg.sender_id}'" title="مشاهده پیوی">`
                            : `<span class="sender-avatar" style="display:inline-flex; align-items:center; justify-content:center; background:var(--primary); color:#fff; border-radius:50%; width:28px; height:28px; font-size:0.8rem;" onclick="event.stopPropagation(); window.location.href='?page=chat&user=${msg.sender_id}'">${escapeHtml(msg.sender_name.charAt(0))}</span>`;
                        contentHtml += `<div class="sender">${avatarHtml} ${escapeHtml(msg.sender_name)}</div>`;
                    }
                    if (msg.file_url) contentHtml += renderFileAttachment(msg.file_url, msg.file_description);
                    contentHtml += formatMessage(msg.message || '');
                    if (msg.all_emojis && msg.all_emojis.length) contentHtml += `<div class="reactions-display">${msg.all_emojis.map(escapeHtml).join(' ')}</div>`;
                    contentHtml += `<div class="time">${escapeHtml(msg.sent_at || '')}</div>`;
                    if (msg.is_pinned) contentHtml += ' <span>📌</span>';
                    div.innerHTML = `<div class="message-content">${contentHtml}</div>`;
                    let actionsHtml = '<div class="message-actions">';
                    actionsHtml += '<div class="reactions">';
                    if (msg.user_emoji) actionsHtml += `<span class="user-reaction">${escapeHtml(msg.user_emoji)}</span>`;
                    ['👍','❤️','😂','😢','😡'].forEach(emoji => actionsHtml += `<button onclick="react(${msg.id}, '${emoji}')">${emoji}</button>`);
                    actionsHtml += '</div>';
                    actionsHtml += `<button class="copy-btn" onclick="copyMessage(${msg.id})" title="کپی پیام">📋</button>`;
                    actionsHtml += `<button class="reply-btn" data-reply-id="${msg.id}" data-reply-name="${encodeURIComponent(msg.sender_name || '')}" data-reply-text="${encodeURIComponent(msg.message || '')}" title="پاسخ" aria-label="پاسخ به پیام">↩️</button>`;
                    actionsHtml += `<button class="forward-btn" onclick="openForwardModal(${msg.id}, 'group')" title="فوروارد پیام" aria-label="فوروارد پیام">↗️</button>`;
                    if (canEdit) actionsHtml += `<button class="edit-btn" onclick="editMessage(${msg.id}, 'group')">✏️</button>`;
                    if (isMine || <?= $can_delete_group_messages ? 'true' : 'false' ?>) actionsHtml += `<button class="delete-btn" onclick="deleteMsg(${msg.id}, 'group')">🗑️</button>`;
                    if (<?= $can_pin_group_messages ? 'true' : 'false' ?>) actionsHtml += `<button class="pin-btn" onclick="togglePin(${msg.id}, ${msg.is_pinned})">📌</button>`;
                    actionsHtml += `<button class="report-btn" onclick="reportMessage(${msg.id}, 'message')">⚠️</button>`;
                    actionsHtml += '</div>';
                    div.innerHTML += actionsHtml;
                    return div;
                }

                function appendGroupMessages(messages, prepend = false) {
                    const fragment = document.createDocumentFragment();
                    messages.forEach(msg => {
                        if (groupContainer.querySelector(`.message[data-id="${msg.id}"]`)) return;
                        fragment.appendChild(createGroupMessageElement(msg));
                    });
                    if (prepend) groupContainer.insertBefore(fragment, groupContainer.firstChild);
                    else groupContainer.appendChild(fragment);
                }

                function updateGroupCounters(messages) {
                    messages.forEach(msg => {
                        if (msg.id > newestGroupMsgId) newestGroupMsgId = msg.id;
                        if (oldestGroupMsgId === null || msg.id < oldestGroupMsgId) oldestGroupMsgId = msg.id;
                    });
                }

                async function checkDeletedGroupMessages() {
                    const messageElements = groupContainer.querySelectorAll('.message[data-id]');
                    if (messageElements.length === 0) return;
                    const ids = Array.from(messageElements).map(el => parseInt(el.getAttribute('data-id')));
                    const formData = new FormData();
                    formData.append('type', 'group');
                    formData.append('group_id', groupId);
                    ids.forEach(id => formData.append('message_ids[]', id));
                    try {
                        const res = await fetch('index.php?action=check_deleted', { method: 'POST', body: formData });
                        const data = await res.json();
                        if (data.deleted_ids && data.deleted_ids.length > 0) {
                            data.deleted_ids.forEach(id => {
                                const el = groupContainer.querySelector(`.message[data-id="${id}"]`);
                                if (el) el.remove();
                            });
                        }
                    } catch (e) {}
                }

                async function loadInitialGroup() {
                    const res = await fetch(`load_group.php?id=${groupId}`);
                    const messages = await res.json();
                    if (messages.length === 0) {
                        groupContainer.innerHTML = '<div style="color:var(--secondary-text); text-align:center; padding:20px;">📭 پیامی نیست.</div>';
                        fetch('mark_group_read.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`group_id=${groupId}` });
                        return;
                    }
                    groupContainer.innerHTML = '';
                    newestGroupMsgId = messages[messages.length - 1].id;
                    oldestGroupMsgId = messages[0].id;
                    noMoreOlderGroup = messages.length < 50;
                    appendGroupMessages(messages, false);
                    groupContainer.scrollTop = groupContainer.scrollHeight;
                    fetch('mark_group_read.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`group_id=${groupId}` });
                }

                async function loadNewerGroup() {
                    if (!newestGroupMsgId) return;
                    const res = await fetch(`load_group.php?id=${groupId}&after=${newestGroupMsgId}`);
                    const messages = await res.json();
                    if (messages.length > 0) {
                        const isNearBottom = groupContainer.scrollTop + groupContainer.clientHeight >= groupContainer.scrollHeight - 100;
                        appendGroupMessages(messages, false);
                        updateGroupCounters(messages);
                        if (isNearBottom) {
                            groupContainer.scrollTop = groupContainer.scrollHeight;
                            fetch('mark_group_read.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`group_id=${groupId}` });
                        }
                    }
                    checkDeletedGroupMessages();
                }

                async function loadOlderGroup() {
                    if (!oldestGroupMsgId || isLoadingOlderGroup || noMoreOlderGroup) return false;
                    isLoadingOlderGroup = true;
                    try {
                        const res = await fetch(`load_group.php?id=${groupId}&before=${oldestGroupMsgId}`);
                        if (!res.ok) throw new Error(`HTTP ${res.status}`);
                        const messages = await res.json();
                        if (messages.length === 0) {
                            noMoreOlderGroup = true;
                            return false;
                        }
                        const previousHeight = groupContainer.scrollHeight;
                        appendGroupMessages(messages, true);
                        updateGroupCounters(messages);
                        if (messages.length < 50) noMoreOlderGroup = true;
                        groupContainer.scrollTop += groupContainer.scrollHeight - previousHeight;
                        return true;
                    } catch (error) {
                        console.error('Could not load older group messages', error);
                        return false;
                    } finally {
                        isLoadingOlderGroup = false;
                    }
                }

                window.jumpToMessage = async function(messageId) {
                    let message = groupContainer.querySelector(`.message[data-id="${messageId}"]`);
                    let pages = 0;
                    while (!message && !noMoreOlderGroup && pages < 30) {
                        if (!await loadOlderGroup()) break;
                        pages++;
                        message = groupContainer.querySelector(`.message[data-id="${messageId}"]`);
                    }
                    if (!message) { alert('پیام در دسترس نیست یا دیگر در این گفتگو وجود ندارد.'); return; }
                    message.scrollIntoView({behavior:'smooth', block:'center'});
                    message.classList.add('message-highlight');
                    setTimeout(() => message.classList.remove('message-highlight'), 1800);
                };

                groupContainer.addEventListener('scroll', () => {
                    if (groupContainer.scrollTop <= 50) loadOlderGroup();
                });

                setInterval(loadNewerGroup, 5000);
                loadInitialGroup();

                async function react(messageId, emoji) {
                    const currentReaction = document.querySelector(`.message[data-id="${messageId}"] .user-reaction`)?.innerText;
                    const action = (currentReaction === emoji) ? 'remove' : 'add';
                    try {
                        const response = await fetch('react_message.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`message_id=${messageId}&emoji=${encodeURIComponent(emoji)}&action=${action}&type=group` });
                        const result = await response.json();
                        if (!result.success) { alert(result.error || 'ثبت واکنش ناموفق بود.'); return; }
                        await loadInitialGroup();
                    } catch (error) { alert('ثبت واکنش انجام نشد؛ اتصال را بررسی کنید.'); }
                }
                async function togglePin(messageId, isPinned) {
                    const action = isPinned ? 'unpin' : 'pin';
                    await fetch('pin_message.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`message_id=${messageId}&action=${action}` });
                    loadInitialGroup();
                }
                async function leaveGroup(gid) {
                    if (!confirm('آیا مطمئن هستید که می‌خواهید خارج شوید؟')) return;
                    const res = await fetch('leave_group.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`group_id=${gid}` });
                    const r = await res.json();
                    if (r.success) window.location.href = 'index.php?page=home';
                    else alert(r.error);
                }
                const groupForm = document.getElementById('groupForm');
                if (groupForm) groupForm.addEventListener('submit', async e => {
                    e.preventDefault();
                    const sendBtn = document.getElementById('groupSendBtn');
                    if (!groupForm.querySelector('[name="message"]').value.trim() && !groupForm.querySelector('[name="file_url"]').value.trim()) return;
                    sendBtn.disabled = true;
                    try {
                        const res = await fetch('send_message.php', { method:'POST', body: new FormData(groupForm) });
                        const result = await res.json();
                        if (result.success) {
                            document.getElementById('groupInput').value = '';
                        document.getElementById('groupInput').style.height = '46px';
                            groupForm.querySelector('[name="file_url"]').value = '';
                            groupForm.querySelector('[name="file_description"]').value = '';
                            document.getElementById('groupAttach').classList.remove('active');
                            await loadInitialGroup();
                        } else alert(result.error || 'ارسال پیام ناموفق بود.');
                    } catch (error) {
                        alert('ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.');
                    } finally {
                        sendBtn.disabled = false;
                    }
                });
            </script>
        <?php else: ?>
            <?php if ($group['privacy'] == 'public'): ?>
                <div style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;">
                    <p style="color:var(--secondary-text);">شما عضو این <?= $group['type']=='channel'?'کانال':'گروه' ?> نیستید.</p>
                    <button id="joinButton" class="btn-join">عضویت</button>
                </div>
                <script>
                    document.getElementById('joinButton').addEventListener('click', async () => {
                        const res = await fetch('join_group.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`group_id=<?= $target_id ?>` });
                        const result = await res.json();
                        if (result.success) location.reload();
                        else alert(result.error || 'خطا');
                    });
                </script>
            <?php else: ?>
                <div class="notice-bar">این گروه خصوصی است و شما عضو نیستید.</div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

<?php elseif ($page == 'notfound'): ?>
    <div class="main-header"><h3>یافت نشد</h3></div>
    <div style="flex:1; display:flex; align-items:center; justify-content:center; color:var(--secondary-text);">صفحه یا گروه مورد نظر یافت نشد.</div>
<?php else: ?>
    <div class="main-header"><h3>صفحه نامعتبر</h3></div>
<?php endif; ?>
</div>

<!-- جستجوی پیام‌ها در گفتگوی جاری -->
<div id="messageSearchModal" class="modal-overlay" style="display:none;" onclick="if(event.target===this) closeMessageSearch()">
    <div class="modal message-search-modal" role="dialog" aria-modal="true" aria-labelledby="messageSearchTitle">
        <div class="search-modal-heading">
            <div><h3 id="messageSearchTitle">جستجو در گفتگو</h3><p>عبارت موردنظر را در پیام‌ها پیدا کنید.</p></div>
            <button type="button" class="btn-icon" onclick="closeMessageSearch()" aria-label="بستن">×</button>
        </div>
        <input type="search" id="messageSearchInput" placeholder="حداقل دو نویسه بنویسید…" autocomplete="off" aria-label="جستجوی پیام">
        <div id="messageSearchStatus" class="search-status" role="status" aria-live="polite">برای شروع جستجو تایپ کنید.</div>
        <div id="messageSearchResults" class="message-search-results"></div>
    </div>
</div>

<!-- انتخاب مقصد برای فوروارد -->
<div id="forwardModal" class="modal-overlay" style="display:none;" onclick="if(event.target===this) closeForwardModal()">
    <div class="modal forward-modal" role="dialog" aria-modal="true" aria-labelledby="forwardTitle">
        <div class="search-modal-heading">
            <div><h3 id="forwardTitle">فوروارد پیام</h3><p>گفتگوی مقصد را انتخاب کنید. فایل‌ها بارگذاری نمی‌شوند؛ فقط لینک رسانه منتقل می‌شود.</p></div>
            <button type="button" class="btn-icon" onclick="closeForwardModal()" aria-label="بستن">×</button>
        </div>
        <input type="search" id="forwardSearchInput" placeholder="جستجوی کاربر برای فوروارد…" autocomplete="off" aria-label="جستجوی مقصد">
        <div id="forwardStatus" class="search-status" role="status" aria-live="polite">گفتگوهای اخیر</div>
        <div id="forwardTargets" class="message-search-results"></div>
    </div>
</div>

<!-- مودال ویرایش -->
<div id="editModal" class="modal-overlay" style="display:none;">
    <div class="modal">
        <h3>ویرایش پیام</h3>
        <input type="hidden" id="editMsgId">
        <input type="hidden" id="editMsgType">
        <textarea id="editText" rows="3" placeholder="متن پیام"></textarea>
        <input type="url" id="editFileUrl" placeholder="لینک فایل جدید (خالی = حذف)">
        <input type="text" id="editFileDesc" placeholder="توضیحات فایل">
        <div style="display:flex; gap:8px; justify-content:flex-end;">
            <button onclick="closeEditModal()" style="background:gray;">انصراف</button>
            <button onclick="submitEdit()">ذخیره</button>
        </div>
    </div>
</div>

<!-- نوار پایین -->
<nav class="bottom-nav" id="bottomNav">
    <a href="home.php" class="nav-item" aria-label="خانه"><span class="icon">🏠</span><span>خانه</span></a>
    <a href="index.php" class="nav-item active" aria-label="پیام‌رسان"><span class="icon">💬</span><span>پیام‌رسان</span></a>
    <a href="<?= isset($_SESSION['user_id']) ? 'account.php' : 'login.php' ?>" class="nav-item" aria-label="حساب"><span class="icon">👤</span><span>حساب</span></a>
    <a href="explore.php" class="nav-item" aria-label="اکسپلور"><span class="icon">🔍</span><span>اکسپلور</span></a>
    <a href="otaku.php" class="nav-item" aria-label="اوتاکو"><span class="icon">🎬</span><span>اوتاکو</span></a>
</nav>

<script>
    const currentUserId = <?= $current_user_id ?>;
    const forwardDestinations = <?= json_encode(array_map(static function ($conversation) {
        return ['type' => $conversation['type'], 'id' => (int)$conversation['id'], 'name' => $conversation['name'], 'kind' => $conversation['kind'] ?? ''];
    }, $all_conversations), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    let forwardSource = null;
    let forwardSearchTimer;
    let forwardSearchController;
    function closeForwardModal() {
        document.getElementById('forwardModal').style.display = 'none';
        if (forwardSearchController) forwardSearchController.abort();
    }
    function addForwardTarget(container, target, type, id, name) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'message-search-result';
        const title = document.createElement('span');
        title.className = 'message-search-result-text';
        title.textContent = `${type === 'group' ? (target.kind === 'channel' ? '📢 ' : '👥 ') : '💬 '}${name}`;
        const note = document.createElement('small');
        note.textContent = type === 'group' ? 'گروه یا کانال' : 'گفتگوی خصوصی';
        button.append(title, note);
        button.addEventListener('click', () => forwardTo(type, id, button));
        container.appendChild(button);
    }
    function openForwardModal(messageId, sourceType) {
        forwardSource = {id: Number(messageId), type: sourceType};
        const modal = document.getElementById('forwardModal');
        const targets = document.getElementById('forwardTargets');
        targets.replaceChildren();
        document.getElementById('forwardSearchInput').value = '';
        document.getElementById('forwardStatus').textContent = 'گفتگوهای اخیر';
        const seen = new Set();
        forwardDestinations.forEach(target => {
            const key = `${target.type}:${target.id}`;
            if (seen.has(key)) return;
            seen.add(key);
            addForwardTarget(targets, target, target.type, target.id, target.name);
        });
        if (!targets.children.length) document.getElementById('forwardStatus').textContent = 'گفتگوی فعالی ندارید؛ نام کاربر را جستجو کنید.';
        modal.style.display = 'flex';
        document.getElementById('forwardSearchInput').focus();
    }
    async function forwardTo(type, id, button) {
        if (!forwardSource) return;
        button.disabled = true;
        try {
            const body = new URLSearchParams({source_type: forwardSource.type, source_id: forwardSource.id, destination_type: type, destination_id: id});
            const response = await fetch('forward_message.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body});
            const result = await response.json();
            if (!response.ok || !result.success) { alert(result.error || 'فوروارد پیام ناموفق بود.'); button.disabled = false; return; }
            closeForwardModal();
            alert('پیام فوروارد شد.');
        } catch (error) {
            button.disabled = false;
            alert('ارتباط با سرور برقرار نشد.');
        }
    }
    document.getElementById('forwardSearchInput').addEventListener('input', () => {
        clearTimeout(forwardSearchTimer);
        forwardSearchTimer = setTimeout(async () => {
            const query = document.getElementById('forwardSearchInput').value.trim();
            const status = document.getElementById('forwardStatus');
            const targets = document.getElementById('forwardTargets');
            if (query.length < 2) { targets.replaceChildren(); status.textContent = 'برای جستجو دست‌کم دو نویسه وارد کنید.'; return; }
            if (forwardSearchController) forwardSearchController.abort();
            forwardSearchController = new AbortController();
            status.textContent = 'در حال جستجو…';
            targets.replaceChildren();
            try {
                const response = await fetch(`search_all.php?q=${encodeURIComponent(query)}`, {signal:forwardSearchController.signal});
                const items = await response.json();
                const unique = new Set();
                items.filter(item => item.type === 'user' || item.type === 'private_chat').forEach(item => {
                    const key = `private:${item.id}`;
                    if (unique.has(key)) return;
                    unique.add(key);
                    addForwardTarget(targets, {kind:''}, 'private', item.id, item.name);
                });
                status.textContent = targets.children.length ? 'برای انتخاب، مقصد را بزنید.' : 'کاربری پیدا نشد.';
            } catch (error) { if (error.name !== 'AbortError') status.textContent = 'جستجوی مقصد ناموفق بود.'; }
        }, 250);
    });
    const messageSearchContext = {
        type: <?= json_encode($page === 'chat' ? 'private' : 'group') ?>,
        id: <?= (int)($page === 'chat' ? $user_chat_id : $target_id) ?>
    };
    let messageSearchTimer;
    let messageSearchController;
    function openMessageSearch() {
        const modal = document.getElementById('messageSearchModal');
        if (!modal) return;
        modal.style.display = 'flex';
        document.getElementById('messageSearchInput').focus();
    }
    function closeMessageSearch() {
        document.getElementById('messageSearchModal').style.display = 'none';
        if (messageSearchController) messageSearchController.abort();
    }
    async function runMessageSearch() {
        const input = document.getElementById('messageSearchInput');
        const status = document.getElementById('messageSearchStatus');
        const results = document.getElementById('messageSearchResults');
        const query = input.value.trim();
        if (query.length < 2) {
            status.textContent = 'برای جستجو دست‌کم دو نویسه وارد کنید.';
            results.replaceChildren();
            return;
        }
        if (messageSearchController) messageSearchController.abort();
        messageSearchController = new AbortController();
        status.textContent = 'در حال جستجو…';
        results.replaceChildren();
        try {
            const params = new URLSearchParams({type: messageSearchContext.type, id: messageSearchContext.id, q: query});
            const response = await fetch(`search_messages.php?${params}`, {signal: messageSearchController.signal});
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error('search failed');
            if (!data.items.length) {
                status.textContent = 'پیامی با این عبارت پیدا نشد.';
                return;
            }
            status.textContent = `${data.items.length} نتیجه پیدا شد؛ برای رفتن به پیام انتخاب کنید.`;
            data.items.forEach(item => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'message-search-result';
                const text = document.createElement('span');
                text.className = 'message-search-result-text';
                text.textContent = item.message || item.file_description || 'پیام رسانه‌ای';
                const meta = document.createElement('small');
                const sentAt = String(item.sent_at || '');
                const date = new Date(sentAt.replace(' ', 'T'));
                meta.textContent = `${item.sender_name || 'کاربر'} · ${!sentAt || Number.isNaN(date.getTime()) ? sentAt : date.toLocaleString('fa-IR')}`;
                button.append(text, meta);
                button.addEventListener('click', async () => {
                    closeMessageSearch();
                    if (window.jumpToMessage) await window.jumpToMessage(item.id);
                });
                results.appendChild(button);
            });
        } catch (error) {
            if (error.name !== 'AbortError') status.textContent = 'جستجو ناموفق بود؛ اتصال را بررسی کنید.';
        }
    }
    document.getElementById('messageSearchInput').addEventListener('input', () => {
        clearTimeout(messageSearchTimer);
        messageSearchTimer = setTimeout(runMessageSearch, 250);
    });
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const isOpen = sidebar.classList.toggle('open');
        document.body.classList.toggle('sidebar-open', isOpen);
    }
    function closeSidebar() {
        if (window.innerWidth <= 768) {
            document.getElementById('sidebar').classList.remove('open');
            document.body.classList.remove('sidebar-open');
        }
    }
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') { closeSidebar(); closeMessageSearch(); closeForwardModal(); }
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'f' && document.body.classList.contains('in-conversation')) {
            event.preventDefault();
            openMessageSearch();
        }
    });
    document.addEventListener('click', event => {
        const button = event.target.closest('.reply-btn');
        if (!button) return;
        event.preventDefault();
        event.stopPropagation();
        if (typeof replyMessage === 'function') {
            try { replyMessage(button.dataset.replyId, decodeURIComponent(button.dataset.replyName || ''), decodeURIComponent(button.dataset.replyText || '')); }
            catch (error) { replyMessage(button.dataset.replyId, '', ''); }
        }
    });
    document.querySelectorAll('.message-composer').forEach(composer => {
        const fitComposer = () => { composer.style.height = 'auto'; composer.style.height = `${Math.min(composer.scrollHeight, 150)}px`; };
        composer.addEventListener('input', fitComposer);
        composer.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                composer.form.requestSubmit();
            }
        });
        fitComposer();
    });
    setInterval(() => { fetch('heartbeat.php'); }, 30000);

    async function updateUnreadCounts() {
        try {
            const res = await fetch('index.php?action=unread_counts');
            const data = await res.json();
            document.querySelectorAll('.unread-badge[data-chat-type][data-chat-id]').forEach(badge => {
                const type = badge.getAttribute('data-chat-type');
                const id = badge.getAttribute('data-chat-id');
                const match = data.find(item => item.type === type && item.id == id);
                if (match && match.unread > 0) {
                    badge.textContent = match.unread;
                    badge.style.display = '';
                } else {
                    badge.textContent = '';
                    badge.style.display = 'none';
                }
            });
        } catch (e) {}
    }
    setInterval(updateUnreadCounts, 10000);
    updateUnreadCounts();

    document.getElementById('searchInput').addEventListener('input', function() {
        const q = this.value.trim();
        const resultsDiv = document.getElementById('searchResults');
        if (q.length < 2) { resultsDiv.style.display = 'none'; return; }
        fetch('search_all.php?q=' + encodeURIComponent(q))
            .then(res => res.json())
            .then(items => {
                resultsDiv.innerHTML = '';
                if (!items.length) { resultsDiv.style.display = 'none'; return; }
                items.forEach(item => {
                    const div = document.createElement('div');
                    div.style.padding = '8px 12px'; div.style.cursor = 'pointer';
                    div.style.borderBottom = '1px solid rgba(255,255,255,0.1)';
                    let label = '';
                    if (item.type === 'user') label = item.name + (item.username ? ' (@'+item.username+')' : '');
                    else if (item.type === 'private_chat') label = item.name + ' (پی‌وی)';
                    else {
                        label = item.name + (item.type==='channel'?' (کانال)':' (گروه)');
                        if (item.member_count !== undefined) label += ` - ${item.member_count} عضو`;
                    }
                    div.textContent = label;
                    div.onclick = () => {
                        if (item.type === 'user' || item.type === 'private_chat') window.location.href = `?page=chat&user=${item.id}`;
                        else window.location.href = `?page=group&id=${item.id}`;
                    };
                    resultsDiv.appendChild(div);
                });
                resultsDiv.style.display = 'block';
            });
    });
    document.addEventListener('click', e => { if (!e.target.closest('.search-box')) document.getElementById('searchResults').style.display = 'none'; });

    function toggleAttachPanel(id) {
        document.getElementById(id).classList.toggle('active');
    }

    function applyNavState(hidden) {
        if (hidden) document.body.classList.add('nav-hidden');
        else document.body.classList.remove('nav-hidden');
    }
    (function() {
        const stored = localStorage.getItem('navHidden');
        if (stored === 'true') applyNavState(true);
    })();
    function toggleNavVisibility() {
        const isHidden = document.body.classList.toggle('nav-hidden');
        localStorage.setItem('navHidden', isHidden);
    }

    async function blockUser(targetId, isBlocked) {
        const action = isBlocked ? 'unblock' : 'block';
        const promptText = isBlocked ? 'مسدودیت این کاربر برداشته شود؟' : 'این کاربر مسدود شود؟';
        if (!confirm(promptText)) return;
        try {
            const response = await fetch('block_user.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: `target_id=${targetId}&action=${action}`
            });
            const result = await response.json();
            if (!result.success) { alert(result.error || 'عملیات ناموفق بود.'); return; }
            location.reload();
        } catch (error) { alert('ارتباط با سرور برقرار نشد.'); }
    }
    function reportMessage(targetId, targetType) {
        const reason = prompt('دلیل گزارش:');
        if (reason) fetch('report.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`target_type=${targetType}&target_id=${targetId}&reason=${encodeURIComponent(reason)}` }).then(r=>r.json()).then(res=>{ if(res.success) alert('گزارش ثبت شد.'); });
    }
    function editMessage(msgId, type) {
        document.getElementById('editMsgId').value = msgId;
        document.getElementById('editMsgType').value = type;
        document.getElementById('editModal').style.display = 'flex';
        document.getElementById('editText').value = '';
        document.getElementById('editFileUrl').value = '';
        document.getElementById('editFileDesc').value = '';
    }
    function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }
    async function submitEdit() {
        const id = document.getElementById('editMsgId').value;
        const type = document.getElementById('editMsgType').value;
        const text = document.getElementById('editText').value.trim();
        const fileUrl = document.getElementById('editFileUrl').value.trim();
        const fileDesc = document.getElementById('editFileDesc').value.trim();
        if (!text && !fileUrl) { alert('پیام یا فایل باید پر شود.'); return; }
        const formData = new FormData();
        formData.append('message_id', id);
        formData.append('message', text);
        formData.append('file_url', fileUrl);
        formData.append('file_description', fileDesc);
        const url = type === 'group' ? 'edit_message.php' : 'edit_private_message.php';
        const res = await fetch(url, { method:'POST', body: formData });
        const result = await res.json();
        if (result.success) {
            closeEditModal();
            if (type === 'group' && typeof loadInitialGroup === 'function') loadInitialGroup();
            else if (type === 'private' && typeof loadInitialPrivate === 'function') loadInitialPrivate();
        } else alert(result.error || 'خطا');
    }
    async function deleteMsg(messageId, type) {
        if (!confirm('آیا مطمئن هستید؟')) return;
        const res = await fetch('delete_message.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`message_id=${messageId}&type=${type}` });
        const result = await res.json();
        if (result.success) {
            const msgDiv = document.querySelector(`.message[data-id="${messageId}"]`);
            if (msgDiv) msgDiv.remove();
        } else {
            alert(result.error || 'خطا');
        }
    }
</script>
</body>
</html>