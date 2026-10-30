<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    http_response_code(403); exit;
}
$user_id = $_SESSION['user_id'];

// ---------- دریافت وضعیت محدودیت کاربر ----------
$stmt = $pdo->prepare("SELECT restricted_until, restriction_type FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$u = $stmt->fetch();

if ($u && $u['restricted_until'] && strtotime($u['restricted_until']) > time()) {
    $restriction_type = $u['restriction_type'];

    // مسدود کامل
    if ($restriction_type === 'ban') {
        echo json_encode(['success' => false, 'error' => 'حساب شما مسدود شده است.']);
        exit;
    }

    // سکوت (هیچ جا حق ارسال ندارد)
    if ($restriction_type === 'mute') {
        echo json_encode(['success' => false, 'error' => 'شما در حالت سکوت هستید و اجازه ارسال پیام ندارید.']);
        exit;
    }

    // ممنوعیت پیام: در خصوصی کاملاً ممنوع، در گروه/کانال فقط مالک می‌تواند
    if ($restriction_type === 'message_ban') {
        $type = $_POST['type'] ?? '';
        if ($type === 'private') {
            echo json_encode(['success' => false, 'error' => 'شما اجازه ارسال پیام خصوصی ندارید.']);
            exit;
        }
        // برای گروه/کانال، بررسی بعداً انجام می‌شود (فقط مالک)
    }
}

$type = $_POST['type'] ?? '';
$message = trim($_POST['message'] ?? '');
$file_url = trim($_POST['file_url'] ?? '');
$file_description = trim($_POST['file_description'] ?? '');
$messageLength = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
$descriptionLength = function_exists('mb_strlen') ? mb_strlen($file_description, 'UTF-8') : strlen($file_description);
if ($messageLength > 10000 || $descriptionLength > 500) {
    echo json_encode(['success' => false, 'error' => 'طول پیام یا توضیح فایل بیش از حد مجاز است.']);
    exit;
}

// اگر فایل ارسال شده، دامنه رو بررسی کن
if ($file_url !== '') {
    $parsed = filter_var($file_url, FILTER_VALIDATE_URL) ? parse_url($file_url) : false;
    if (!$parsed || strtolower($parsed['scheme'] ?? '') !== 'https'
        || empty($parsed['host']) || isset($parsed['user']) || isset($parsed['pass'])) {
        echo json_encode(['success'=>false, 'error'=>'لینک فایل باید یک نشانی HTTPS معتبر باشد.']);
        exit;
    }
    $domain = strtolower(rtrim($parsed['host'], '.'));
    if (str_starts_with($domain, 'www.')) {
        $domain = substr($domain, 4);
    }
    $stmtDomain = $pdo->prepare("SELECT 1 FROM allowed_domains WHERE domain = ?");
    $stmtDomain->execute([$domain]);
    if (!$stmtDomain->fetchColumn()) {
        echo json_encode(['success'=>false, 'error'=>'دامنه فایل مجاز نیست.']);
        exit;
    }
}

// اگر نه پیام داریم نه فایل، خطا
if ($message === '' && $file_url === '') {
    echo json_encode(['success'=>false, 'error'=>'پیام یا فایل نمی‌تواند خالی باشد.']);
    exit;
}

if ($type === 'private') {
    $receiver = (int)$_POST['receiver_id'];
    // بررسی بلاک
    $blocked = $pdo->prepare("SELECT 1 FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?");
    $blocked->execute([$receiver, $user_id]);
    if ($blocked->fetchColumn()) {
        echo json_encode(['success'=>false, 'error'=>'شما توسط این کاربر مسدود شده‌اید.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO private_messages (sender_id, receiver_id, message, file_url, file_description) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $receiver, $message, $file_url ?: null, $file_description ?: null]);
    $msg_id = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, 'private_message', ?, ?)")
        ->execute([$receiver, $msg_id, '📩 پیام جدید از ' . ($_SESSION['username'] ?? 'کاربر')]);
    echo json_encode(['success'=>true]);
    exit;

} elseif ($type === 'group') {
    $group_id = (int)$_POST['group_id'];

    // وجود گروه
    $group = $pdo->prepare("SELECT created_by, type, name FROM groups_channels WHERE id = ?");
    $group->execute([$group_id]);
    $g = $group->fetch();
    if (!$g) {
        echo json_encode(['success'=>false, 'error'=>'گروه وجود ندارد.']);
        exit;
    }

    // اعمال محدودیت message_ban: فقط مالک می‌تواند
    if ($u && $u['restricted_until'] && strtotime($u['restricted_until']) > time() && $u['restriction_type'] === 'message_ban') {
        if ($g['created_by'] != $user_id) {
            echo json_encode(['success'=>false, 'error'=>'شما فقط در گروه/کانالی که مالک آن هستید می‌توانید پیام دهید.']);
            exit;
        }
    }

    // مالک همیشه می‌تواند (در صورت عدم وجود محدودیت)
    if ($g['created_by'] == $user_id) {
        $stmt = $pdo->prepare("INSERT INTO group_channel_messages (group_id, sender_id, message, file_url, file_description) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$group_id, $user_id, $message, $file_url ?: null, $file_description ?: null]);
        $msg_id = $pdo->lastInsertId();

        $members = $pdo->prepare("SELECT user_id FROM group_channel_members WHERE group_id = ? AND user_id != ?");
        $members->execute([$group_id, $user_id]);
        while ($member = $members->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare("INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, 'group_message', ?, ?)")
                ->execute([$member['user_id'], $msg_id, '💬 پیام جدید در ' . $g['name']]);
        }
        echo json_encode(['success'=>true]);
        exit;
    }

    // بررسی عضویت
    $member = $pdo->prepare("SELECT m.*, g.type FROM group_channel_members m JOIN groups_channels g ON m.group_id = g.id WHERE m.group_id = ? AND m.user_id = ?");
    $member->execute([$group_id, $user_id]);
    $m = $member->fetch();
    if (!$m) {
        echo json_encode(['success'=>false, 'error'=>'شما عضو نیستید.']);
        exit;
    }

    // نقش مالک یا مدیر (بدون محدودیت)
    if (in_array($m['role'], ['owner', 'admin'])) {
        $stmt = $pdo->prepare("INSERT INTO group_channel_messages (group_id, sender_id, message, file_url, file_description) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$group_id, $user_id, $message, $file_url ?: null, $file_description ?: null]);
        $msg_id = $pdo->lastInsertId();

        $members = $pdo->prepare("SELECT user_id FROM group_channel_members WHERE group_id = ? AND user_id != ?");
        $members->execute([$group_id, $user_id]);
        while ($member = $members->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare("INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, 'group_message', ?, ?)")
                ->execute([$member['user_id'], $msg_id, '💬 پیام جدید در ' . $g['name']]);
        }
        echo json_encode(['success'=>true]);
        exit;
    }

    // عضو عادی
    if ($m['type'] == 'channel') {
        echo json_encode(['success'=>false, 'error'=>'در کانال فقط مدیران می‌توانند پیام دهند.']);
        exit;
    }
    if (!$m['can_send_message']) {
        echo json_encode(['success'=>false, 'error'=>'اجازه ارسال پیام ندارید.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO group_channel_messages (group_id, sender_id, message, file_url, file_description) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$group_id, $user_id, $message, $file_url ?: null, $file_description ?: null]);
    $msg_id = $pdo->lastInsertId();

    $members = $pdo->prepare("SELECT user_id FROM group_channel_members WHERE group_id = ? AND user_id != ?");
    $members->execute([$group_id, $user_id]);
    while ($member = $members->fetch(PDO::FETCH_ASSOC)) {
        $pdo->prepare("INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, 'group_message', ?, ?)")
            ->execute([$member['user_id'], $msg_id, '💬 پیام جدید در ' . $g['name']]);
    }
    echo json_encode(['success'=>true]);
    exit;
}
echo json_encode(['success'=>false, 'error'=>'نوع پیام نامعتبر.']);