<?php
require_once 'config.php';

// ---------------------- احراز هویت ادمین ----------------------
if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] != 1) {
    die("⛔ دسترسی غیرمجاز. فقط ادمین اصلی می‌تواند وارد این پنل شود.");
}
$admin_id = $_SESSION['user_id'];

// ---------------------- پردازش درخواست‌ها ----------------------
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect = false;
    $tab = $_POST['tab'] ?? 'dashboard';

    if ($action === 'add_domain') {
        $domain = trim($_POST['domain'] ?? '');
        if ($domain) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO allowed_domains (domain, added_by) VALUES (?, ?)");
            $stmt->execute([$domain, $admin_id]);
            $message = "دامنه «{$domain}» اضافه شد.";
        } else {
            $error = "نام دامنه اجباری است.";
        }
    }
    elseif ($action === 'delete_domain') {
        $domain_id = (int)$_POST['domain_id'];
        $pdo->prepare("DELETE FROM allowed_domains WHERE id = ?")->execute([$domain_id]);
        $message = "دامنه حذف شد.";
        $redirect = true;
    }
    elseif ($action === 'update_report') {
        $report_id = (int)$_POST['report_id'];
        $new_status = $_POST['status'] ?? 'pending';
        if (in_array($new_status, ['pending', 'reviewed', 'resolved'])) {
            $pdo->prepare("UPDATE user_reports SET status = ? WHERE id = ?")->execute([$new_status, $report_id]);
            $message = "وضعیت گزارش به «{$new_status}» تغییر یافت.";
        }
        $redirect = true;
    }
    elseif ($action === 'restrict_user') {
        $target_user_id = (int)$_POST['user_id'];
        $restriction_type = $_POST['restriction_type'] ?? 'ban';
        $restriction_reason = trim($_POST['reason'] ?? '');
        $expire_date = $_POST['expire_date'] ?? '';
        $expire_time = $_POST['expire_time'] ?? '23:59';

        if ($target_user_id && $restriction_reason) {
            $userExists = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $userExists->execute([$target_user_id]);
            if (!$userExists->fetch()) {
                $error = "کاربر با شناسه {$target_user_id} یافت نشد.";
            } else {
                if (empty($expire_date)) {
                    $expire = '2099-12-31 23:59:59';
                } else {
                    $expire = $expire_date . ' ' . $expire_time . ':00';
                }
                try {
                    $stmt = $pdo->prepare("UPDATE users SET restricted_until = ?, restriction_type = ?, restriction_reason = ? WHERE id = ?");
                    $stmt->execute([$expire, $restriction_type, $restriction_reason, $target_user_id]);
                    if ($stmt->rowCount() > 0) {
                        $message = "کاربر {$target_user_id} با موفقیت مسدود شد.";
                    } else {
                        $error = "خطا: کاربر آپدیت نشد. ممکن است قبلاً محدود بوده است.";
                    }
                } catch (PDOException $e) {
                    $error = "خطای پایگاه داده: " . $e->getMessage();
                }
                $redirect = true;
            }
        } else {
            $error = "شناسه کاربر و دلیل الزامی هستند.";
        }
    }
    elseif ($action === 'unrestrict_user') {
        $target_user_id = (int)$_POST['user_id'];
        $pdo->prepare("UPDATE users SET restricted_until = NULL, restriction_type = NULL, restriction_reason = NULL WHERE id = ?")
            ->execute([$target_user_id]);
        $message = "مسدودیت کاربر {$target_user_id} برداشته شد.";
        $redirect = true;
    }
    elseif ($action === 'delete_user') {
        $target_user_id = (int)$_POST['user_id'];
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$target_user_id]);
        $message = "کاربر {$target_user_id} و تمام داده‌های وابسته حذف شد.";
        $redirect = true;
    }
    elseif ($action === 'delete_resolved_reports') {
        $stmt = $pdo->exec("DELETE FROM user_reports WHERE status = 'resolved'");
        $message = "گزارش‌های حل‌شده حذف شدند. (تعداد: {$stmt})";
        $redirect = true;
    }
    elseif ($action === 'delete_post') {
        $post_id = (int)$_POST['post_id'];
        $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$post_id]);
        $message = "پست حذف شد.";
        $redirect = true;
    }
    elseif ($action === 'delete_message') {
        $msg_id = (int)$_POST['message_id'];
        $msg_type = $_POST['msg_type'] ?? 'group';
        if ($msg_type === 'private') {
            $pdo->prepare("DELETE FROM private_messages WHERE id = ?")->execute([$msg_id]);
        } else {
            $pdo->prepare("DELETE FROM group_channel_messages WHERE id = ?")->execute([$msg_id]);
        }
        $message = "پیام حذف شد.";
        $redirect = true;
    }
    elseif ($action === 'delete_group') {
        $group_id = (int)$_POST['group_id'];
        $pdo->prepare("DELETE FROM groups_channels WHERE id = ?")->execute([$group_id]);
        $message = "گروه/کانال حذف شد.";
        $redirect = true;
    }
    elseif ($action === 'delete_inactive_groups') {
        $days = (int)$_POST['inactive_days'];
        if ($days > 0) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $stmt = $pdo->prepare("DELETE FROM groups_channels WHERE id IN (
                SELECT g.id FROM groups_channels g
                LEFT JOIN group_channel_messages gm ON g.id = gm.group_id
                GROUP BY g.id
                HAVING MAX(gm.sent_at) < ? OR MAX(gm.sent_at) IS NULL
            )");
            $stmt->execute([$cutoff]);
            $deleted = $stmt->rowCount();
            $message = "{$deleted} گروه/کانال غیرفعال حذف شدند.";
        } else {
            $error = "تعداد روز باید بیشتر از صفر باشد.";
        }
        $redirect = true;
    }
    elseif ($action === 'delete_messages_user') {
        $uid = (int)$_POST['user_id'];
        $pdo->prepare("DELETE FROM private_messages WHERE sender_id = ? OR receiver_id = ?")->execute([$uid, $uid]);
        $pdo->prepare("DELETE FROM group_channel_messages WHERE sender_id = ?")->execute([$uid]);
        $message = "تمام پیام‌های کاربر {$uid} حذف شد.";
        $redirect = true;
    }
    elseif ($action === 'delete_messages_group') {
        $gid = (int)$_POST['group_id'];
        $pdo->prepare("DELETE FROM group_channel_messages WHERE group_id = ?")->execute([$gid]);
        $message = "تمام پیام‌های گروه/کانال {$gid} حذف شد.";
        $redirect = true;
    }
    elseif ($action === 'delete_all_private') {
        $pdo->exec("DELETE FROM private_messages");
        $message = "تمام پیام‌های خصوصی حذف شدند.";
        $redirect = true;
    }
    elseif ($action === 'delete_all_group') {
        $pdo->exec("DELETE FROM group_channel_messages");
        $message = "تمام پیام‌های گروهی حذف شدند.";
        $redirect = true;
    }
    elseif ($action === 'delete_messages_before') {
        $date = $_POST['before_date'] ?? '';
        if ($date) {
            $pdo->prepare("DELETE FROM private_messages WHERE sent_at < ?")->execute([$date]);
            $pdo->prepare("DELETE FROM group_channel_messages WHERE sent_at < ?")->execute([$date]);
            $message = "پیام‌های قبل از {$date} حذف شدند.";
            $redirect = true;
        } else {
            $error = "تاریخ مشخص نشده است.";
        }
    }

    if ($redirect) {
        header("Location: admin.php?tab=" . urlencode($tab));
        exit;
    }
}

// ---------------------- آمار کلی ----------------------
$total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_reports = $pdo->query("SELECT COUNT(*) FROM user_reports")->fetchColumn();
$total_domains = $pdo->query("SELECT COUNT(*) FROM allowed_domains")->fetchColumn();
$total_groups = $pdo->query("SELECT COUNT(*) FROM groups_channels")->fetchColumn();

// ---------------------- تب‌ها ----------------------
$tab = $_GET['tab'] ?? 'dashboard';

// ---------------------- صفحه‌بندی و جستجو ----------------------
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

// --- کاربران ---
$userSearch = $_GET['user_search'] ?? '';
$userWhere = '';
$userParams = [];
if ($userSearch !== '') {
    $like = "%$userSearch%";
    $userWhere = " WHERE (username LIKE ? OR email LIKE ? OR display_name LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
    $userParams = [$like, $like, $like, $like, $like];
}
$totalUsersFiltered = $pdo->prepare("SELECT COUNT(*) FROM users" . $userWhere);
$totalUsersFiltered->execute($userParams);
$totalUsersFiltered = $totalUsersFiltered->fetchColumn();
$users = $pdo->prepare("SELECT id, username, display_name, first_name, last_name, email, restricted_until, restriction_type, restriction_reason FROM users" . $userWhere . " ORDER BY id ASC LIMIT $perPage OFFSET $offset");
$users->execute($userParams);
$users = $users->fetchAll();

// --- گزارش‌ها (بدون تغییر) ---
$reportSearch = $_GET['report_search'] ?? '';
$reportWhere = '';
$reportParams = [];
if ($reportSearch !== '') {
    $like = "%$reportSearch%";
    $reportWhere = " WHERE (r.reason LIKE ? OR u_reporter.username LIKE ? OR u_reported.username LIKE ? OR r.target_type LIKE ?)";
    $reportParams = [$like, $like, $like, $like];
}
$totalReportsFiltered = $pdo->prepare("SELECT COUNT(*) FROM user_reports r LEFT JOIN users u_reporter ON r.reporter_id = u_reporter.id LEFT JOIN users u_reported ON r.reported_user_id = u_reported.id" . $reportWhere);
$totalReportsFiltered->execute($reportParams);
$totalReportsFiltered = $totalReportsFiltered->fetchColumn();
$reports = $pdo->prepare("
    SELECT r.*, 
           u_reporter.username as reporter_name,
           u_reported.username as reported_name
    FROM user_reports r
    LEFT JOIN users u_reporter ON r.reporter_id = u_reporter.id
    LEFT JOIN users u_reported ON r.reported_user_id = u_reported.id
    $reportWhere
    ORDER BY r.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$reports->execute($reportParams);
$reports = $reports->fetchAll();

// استخراج محتوای گزارش‌ها و اطلاعات متخلف (بدون تغییر)
foreach ($reports as &$rep) {
    $rep['content'] = '';
    $rep['offender_display'] = '';

    switch ($rep['target_type']) {
        case 'user':
            $stmt = $pdo->prepare("SELECT username, display_name, id FROM users WHERE id = ?");
            $stmt->execute([$rep['target_id']]);
            $row = $stmt->fetch();
            if ($row) {
                $rep['offender_display'] = ($row['display_name'] ?: $row['username']) . " (ID: {$row['id']})";
                $rep['content'] = "کاربر " . ($row['display_name'] ?: $row['username']);
            } else {
                $rep['offender_display'] = "کاربر حذف شده (ID: {$rep['target_id']})";
                $rep['content'] = 'کاربر حذف شده';
            }
            break;
        case 'post':
            $stmt = $pdo->prepare("SELECT p.title, p.subtitle, p.media_url, u.username, u.display_name, u.id as uid FROM posts p LEFT JOIN users u ON p.user_id = u.id WHERE p.id = ?");
            $stmt->execute([$rep['target_id']]);
            $row = $stmt->fetch();
            if ($row) {
                $rep['content'] = "عنوان: {$row['title']}\nزیرعنوان: {$row['subtitle']}\nلینک: {$row['media_url']}";
                $rep['offender_display'] = $row['uid'] ? ($row['display_name'] ?: $row['username']) . " (ID: {$row['uid']})" : "نویسنده حذف شده";
            }
            break;
        case 'message':
            $stmt = $pdo->prepare("SELECT message, file_url, sender_id FROM group_channel_messages WHERE id = ?");
            $stmt->execute([$rep['target_id']]);
            $row = $stmt->fetch();
            if ($row) {
                $rep['content'] = $row['message'] . ($row['file_url'] ? "\nفایل: {$row['file_url']}" : '');
                $rep['msg_type'] = 'group';
                $sender = $pdo->prepare("SELECT username, display_name FROM users WHERE id = ?");
                $sender->execute([$row['sender_id']]);
                $s = $sender->fetch();
                $rep['offender_display'] = $s ? ($s['display_name'] ?: $s['username']) . " (ID: {$row['sender_id']})" : "کاربر حذف شده (ID: {$row['sender_id']})";
            } else {
                $stmt = $pdo->prepare("SELECT message, file_url, sender_id FROM private_messages WHERE id = ?");
                $stmt->execute([$rep['target_id']]);
                $row = $stmt->fetch();
                if ($row) {
                    $rep['content'] = $row['message'] . ($row['file_url'] ? "\nفایل: {$row['file_url']}" : '');
                    $rep['msg_type'] = 'private';
                    $sender = $pdo->prepare("SELECT username, display_name FROM users WHERE id = ?");
                    $sender->execute([$row['sender_id']]);
                    $s = $sender->fetch();
                    $rep['offender_display'] = $s ? ($s['display_name'] ?: $s['username']) . " (ID: {$row['sender_id']})" : "کاربر حذف شده (ID: {$row['sender_id']})";
                }
            }
            break;
        case 'group':
            $stmt = $pdo->prepare("SELECT name, description, created_by FROM groups_channels WHERE id = ?");
            $stmt->execute([$rep['target_id']]);
            $row = $stmt->fetch();
            if ($row) {
                $rep['content'] = "نام: {$row['name']}\nتوضیحات: {$row['description']}";
                $owner = $pdo->prepare("SELECT username, display_name FROM users WHERE id = ?");
                $owner->execute([$row['created_by']]);
                $o = $owner->fetch();
                $rep['offender_display'] = $o ? ($o['display_name'] ?: $o['username']) . " (مالک, ID: {$row['created_by']})" : "مالک حذف شده (ID: {$row['created_by']})";
            }
            break;
        case 'comment':
            $stmt = $pdo->prepare("SELECT comment, user_id FROM post_comments WHERE id = ?");
            $stmt->execute([$rep['target_id']]);
            $row = $stmt->fetch();
            if ($row) {
                $rep['content'] = $row['comment'];
                $user = $pdo->prepare("SELECT username, display_name FROM users WHERE id = ?");
                $user->execute([$row['user_id']]);
                $u = $user->fetch();
                $rep['offender_display'] = $u ? ($u['display_name'] ?: $u['username']) . " (ID: {$row['user_id']})" : "کاربر حذف شده (ID: {$row['user_id']})";
            }
            break;
        default:
            if ($rep['reported_user_id']) {
                $rep['offender_display'] = $rep['reported_name']
                    ? htmlspecialchars($rep['reported_name']) . " (ID: {$rep['reported_user_id']})"
                    : "کاربر حذف شده (ID: {$rep['reported_user_id']})";
            } else {
                $rep['offender_display'] = 'نامشخص';
            }
    }
}
unset($rep);

// دامنه‌ها (بدون تغییر)
$domains = $pdo->query("SELECT * FROM allowed_domains ORDER BY created_at DESC")->fetchAll();

// --- گروه‌ها (جستجو) (بدون تغییر) ---
$groupSearch = $_GET['group_search'] ?? '';
$groupResults = [];
if ($tab == 'groups' && !empty($groupSearch)) {
    $like = "%$groupSearch%";
    $stmt = $pdo->prepare("SELECT g.id, g.name, g.type, g.privacy, (SELECT COUNT(*) FROM group_channel_members WHERE group_id = g.id) as member_count FROM groups_channels g WHERE g.name LIKE ? OR g.id = ? ORDER BY g.id DESC LIMIT 20");
    $stmt->execute([$like, (int)$groupSearch]);
    $groupResults = $stmt->fetchAll();
}

// --- بازرسی کاربر (بدون تغییر) ---
$inspectUser = null;
$inspectGroups = [];
$inspectPrivateChats = [];
if ($tab == 'inspect' && !empty($_GET['inspect_user_id'])) {
    $inspectUserId = (int)$_GET['inspect_user_id'];
    $stmt = $pdo->prepare("SELECT id, username, display_name FROM users WHERE id = ?");
    $stmt->execute([$inspectUserId]);
    $inspectUser = $stmt->fetch();

    if ($inspectUser) {
        $stmt = $pdo->prepare("SELECT g.id, g.name, g.type, g.slug, m.role FROM group_channel_members m JOIN groups_channels g ON m.group_id = g.id WHERE m.user_id = ?");
        $stmt->execute([$inspectUserId]);
        $inspectGroups = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.display_name
            FROM users u
            WHERE u.id IN (
                SELECT DISTINCT sender_id FROM private_messages WHERE receiver_id = ?
                UNION
                SELECT DISTINCT receiver_id FROM private_messages WHERE sender_id = ?
            ) AND u.id != ?
        ");
        $stmt->execute([$inspectUserId, $inspectUserId, $inspectUserId]);
        $inspectPrivateChats = $stmt->fetchAll();
    }
}

// --- مشاهده مکالمه خصوصی (بدون تغییر) ---
$convMessages = [];
if ($tab == 'conversation' && !empty($_GET['user1']) && !empty($_GET['user2'])) {
    $u1 = (int)$_GET['user1'];
    $u2 = (int)$_GET['user2'];
    $stmt = $pdo->prepare("SELECT pm.*, u.username as sender_name FROM private_messages pm JOIN users u ON pm.sender_id = u.id WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) ORDER BY sent_at DESC LIMIT 50");
    $stmt->execute([$u1, $u2, $u2, $u1]);
    $convMessages = $stmt->fetchAll();
}

// تابع صفحه‌بندی (بدون تغییر)
function paginate($total, $current, $tab, $searchParam = '', $searchKey = 'search') {
    $totalPages = ceil($total / 15);
    if ($totalPages <= 1) return '';
    $html = '<div style="margin-top:10px; display:flex; gap:5px; justify-content:center;">';
    $base = "?tab=$tab&p=";
    if ($searchParam !== '') {
        $base .= "&{$searchKey}=" . urlencode($searchParam);
    }
    for ($i = 1; $i <= $totalPages; $i++) {
        $html .= '<a href="'.$base.$i.'" style="padding:4px 10px; background:'.($i==$current?'var(--primary)':'rgba(255,255,255,0.1)').'; border-radius:10px; color:#fff; text-decoration:none;">'.$i.'</a>';
    }
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>پنل ادمین | اوتاکو</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* همان استایل‌های قبلی بدون تغییر */
        :root {
            --primary: #ff4d6d;
            --primary-hover: #ff8fa3;
            --surface: rgba(15, 15, 35, 0.8);
            --bg: #050510;
            --border: rgba(255, 77, 109, 0.2);
            --text: #fff;
            --secondary-text: #b0b0c0;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            background-image: radial-gradient(circle at 20% 30%, #1a1a3e 0%, transparent 40%),
                                radial-gradient(circle at 80% 70%, #2a0a2a 0%, transparent 50%);
            color: var(--text);
            padding: 20px;
            min-height: 100vh;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { text-align: center; margin-bottom: 20px; color: var(--primary); }
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border);
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            transition: 0.3s;
            color: var(--text);
            text-decoration: none;
        }
        .tab.active, .tab:hover { background: var(--primary); border-color: var(--primary); }
        .card {
            background: var(--surface);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .card h2 { margin-bottom: 15px; font-size: 1.3rem; border-bottom: 1px solid var(--border); padding-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; text-align: right; border-bottom: 1px solid rgba(255,255,255,0.1); }
        th { color: var(--primary); }
        .btn {
            background: var(--primary);
            border: none; padding: 6px 14px; border-radius: 20px; color: #fff;
            cursor: pointer; font-size: 0.85rem; transition: 0.3s; margin: 2px;
            text-decoration: none; display: inline-block;
        }
        .btn:hover { background: var(--primary-hover); }
        .btn-danger { background: #b23b3b; }
        .btn-danger:hover { background: #d44; }
        .badge {
            display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem;
        }
        .badge-pending { background: #ffa500; color: #000; }
        .badge-reviewed { background: #3390ec; }
        .badge-resolved { background: #4caf50; }
        input, select, textarea {
            background: rgba(255,255,255,0.1); border: 1px solid var(--border);
            padding: 8px 12px; border-radius: 10px; color: #fff; margin: 4px 0;
            font-family: inherit; width: auto;
        }
        input[type="text"], input[type="date"], input[type="time"] { width: 100%; max-width: 250px; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; margin-bottom: 4px; font-size: 0.9rem; color: var(--secondary-text); }
        .message { background: rgba(76, 175, 80, 0.2); border: 1px solid #4caf50; padding: 10px; border-radius: 10px; margin-bottom: 15px; }
        .error { background: rgba(255, 77, 109, 0.2); border: 1px solid var(--primary); padding: 10px; border-radius: 10px; margin-bottom: 15px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 15px; text-align: center; }
        .stat-card h3 { font-size: 2rem; margin: 5px 0; }
        .search-form { display: flex; gap: 10px; margin-bottom: 15px; align-items: center; flex-wrap: wrap; }
        .search-form input { flex: 1; }
        .msg-content { background: rgba(0,0,0,0.3); padding: 8px; border-radius: 8px; font-size: 0.9rem; max-width: 300px; white-space: pre-wrap; margin: 4px 0; }
    </style>
</head>
<body>
<div class="container">
    <h1>🛡️ پنل مدیریت اوتاکو</h1>

    <?php if ($message): ?><div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="tabs">
        <a href="?tab=dashboard" class="tab <?= $tab=='dashboard'?'active':'' ?>">📊 داشبورد</a>
        <a href="?tab=users" class="tab <?= $tab=='users'?'active':'' ?>">👥 کاربران</a>
        <a href="?tab=reports" class="tab <?= $tab=='reports'?'active':'' ?>">🚩 گزارش‌ها</a>
        <a href="?tab=groups" class="tab <?= $tab=='groups'?'active':'' ?>">👥 گروه‌ها</a>
        <a href="?tab=domains" class="tab <?= $tab=='domains'?'active':'' ?>">🌐 دامنه‌ها</a>
        <a href="?tab=messages" class="tab <?= $tab=='messages'?'active':'' ?>">💬 پیام‌ها</a>
        <a href="?tab=inspect" class="tab <?= $tab=='inspect'?'active':'' ?>">🔍 بازرسی</a>
        <a href="?tab=restrict" class="tab <?= $tab=='restrict'?'active':'' ?>">⛔ مسدودسازی</a>
    </div>

    <?php if ($tab == 'dashboard'): ?>
    <!-- داشبورد بدون تغییر -->
    <div class="stats">
        <div class="stat-card"><h3><?= $total_users ?></h3>👤 کاربر</div>
        <div class="stat-card"><h3><?= $total_reports ?></h3>🚩 گزارش</div>
        <div class="stat-card"><h3><?= $total_domains ?></h3>🌐 دامنه</div>
        <div class="stat-card"><h3><?= $total_groups ?></h3>👥 گروه/کانال</div>
    </div>

    <?php elseif ($tab == 'users'): ?>
    <div class="card">
        <h2>کاربران</h2>
        <form class="search-form" method="get">
            <input type="hidden" name="tab" value="users">
            <input type="text" name="user_search" value="<?= htmlspecialchars($userSearch) ?>" placeholder="جستجوی نام کاربری، ایمیل یا نام...">
            <button class="btn" type="submit">جستجو</button>
            <?php if ($userSearch): ?><a href="?tab=users" class="btn">پاک کردن</a><?php endif; ?>
        </form>
        <table>
            <tr><th>ID</th><th>نام کاربری</th><th>نام</th><th>ایمیل</th><th>وضعیت</th><th>عملیات</th></tr>
            <?php foreach ($users as $u): 
                $statusHtml = '<span style="color:#4caf50">فعال</span>';
                if ($u['restricted_until'] && strtotime($u['restricted_until']) > time()) {
                    $typeMap = [
                        'ban' => 'مسدود کامل',
                        'message_ban' => 'ممنوعیت پیام',
                        'post_ban' => 'ممنوعیت پست',
                        'mute' => 'سکوت'
                    ];
                    $typeText = $typeMap[$u['restriction_type']] ?? $u['restriction_type'];
                    $reason = $u['restriction_reason'] ? ' – دلیل: ' . htmlspecialchars($u['restriction_reason']) : '';
                    $expire = 'تا ' . date('Y/m/d H:i', strtotime($u['restricted_until']));
                    $statusHtml = "<span style='color:#ff4d6d'>{$typeText}{$reason} ({$expire})</span>";
                } elseif ($u['restricted_until']) {
                    $statusHtml = '<span style="color:#aaa">محدودیت منقضی شده</span>';
                }
            ?>
            <tr>
                <td><?= $u['id'] ?></td>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><?= htmlspecialchars($u['display_name'] ?: $u['first_name'] . ' ' . $u['last_name']) ?></td>
                <td><?= htmlspecialchars($u['email'] ?? '-') ?></td>
                <td><?= $statusHtml ?></td>
                <td>
                    <a href="?tab=restrict&user_id=<?= $u['id'] ?>" class="btn">⛔</a>
                    <a href="edit_user.php?id=<?= $u['id'] ?>" class="btn">✏️ ویرایش</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('کاربر و تمام داده‌هایش برای همیشه حذف شوند؟')">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="tab" value="users">
                        <button class="btn btn-danger">🗑️</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?= paginate($totalUsersFiltered, $page, 'users', $userSearch, 'user_search') ?>
    </div>

    <?php elseif ($tab == 'reports'): ?>
    <div class="card">
        <h2>گزارش‌های تخلف</h2>
        <form class="search-form" method="get">
            <input type="hidden" name="tab" value="reports">
            <input type="text" name="report_search" value="<?= htmlspecialchars($reportSearch) ?>" placeholder="جستجو...">
            <button class="btn" type="submit">جستجو</button>
            <?php if ($reportSearch): ?><a href="?tab=reports" class="btn">پاک کردن</a><?php endif; ?>
        </form>
        <table>
            <tr><th>شناسه</th><th>گزارش‌دهنده</th><th>متخلف</th><th>نوع</th><th>دلیل</th><th>محتوا</th><th>وضعیت</th><th>عملیات</th></tr>
            <?php foreach ($reports as $r): 
                $statusClass = 'badge-' . $r['status'];
            ?>
            <tr>
                <td><?= $r['id'] ?></td>
                <td><?= htmlspecialchars($r['reporter_name'] ?? '?') ?></td>
                <td><?= $r['offender_display'] ?></td>
                <td><?= $r['target_type'] ?> (<?= $r['target_id'] ?>)</td>
                <td><?= htmlspecialchars(mb_substr($r['reason'], 0, 30)) ?></td>
                <td><div class="msg-content"><?= nl2br(htmlspecialchars($r['content'] ?? 'بدون محتوا')) ?></div></td>
                <td><span class="badge <?= $statusClass ?>"><?= $r['status'] ?></span></td>
                <td style="white-space:nowrap;">
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="update_report">
                        <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="tab" value="reports">
                        <select name="status" onchange="this.form.submit()">
                            <option value="pending" <?= $r['status']=='pending'?'selected':'' ?>>در انتظار</option>
                            <option value="reviewed" <?= $r['status']=='reviewed'?'selected':'' ?>>بررسی شده</option>
                            <option value="resolved" <?= $r['status']=='resolved'?'selected':'' ?>>حل شده</option>
                        </select>
                    </form>
                    <?php if ($r['reported_user_id']): ?>
                        <a href="?tab=restrict&user_id=<?= $r['reported_user_id'] ?>" class="btn">⛔</a>
                    <?php endif; ?>
                    <?php if ($r['target_type'] == 'post'): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="delete_post">
                            <input type="hidden" name="post_id" value="<?= $r['target_id'] ?>">
                            <input type="hidden" name="tab" value="reports">
                            <button class="btn btn-danger" onclick="return confirm('پست حذف شود؟')">🗑️</button>
                        </form>
                    <?php elseif ($r['target_type'] == 'message'): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="delete_message">
                            <input type="hidden" name="message_id" value="<?= $r['target_id'] ?>">
                            <input type="hidden" name="msg_type" value="<?= $r['msg_type'] ?? 'group' ?>">
                            <input type="hidden" name="tab" value="reports">
                            <button class="btn btn-danger" onclick="return confirm('پیام حذف شود؟')">🗑️</button>
                        </form>
                    <?php elseif ($r['target_type'] == 'group'): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="delete_group">
                            <input type="hidden" name="group_id" value="<?= $r['target_id'] ?>">
                            <input type="hidden" name="tab" value="reports">
                            <button class="btn btn-danger" onclick="return confirm('کل گروه حذف شود؟')">🗑️</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?= paginate($totalReportsFiltered, $page, 'reports', $reportSearch, 'report_search') ?>

        <!-- دکمه حذف گزارش‌های حل‌شده -->
        <form method="post" style="margin-top:15px;" onsubmit="return confirm('آیا از حذف تمام گزارش‌های با وضعیت «حل شده» اطمینان دارید؟')">
            <input type="hidden" name="action" value="delete_resolved_reports">
            <input type="hidden" name="tab" value="reports">
            <button class="btn btn-danger">🗑️ حذف گزارش‌های حل‌شده</button>
        </form>
    </div>

    <!-- سایر تب‌ها بدون تغییر -->
    <?php elseif ($tab == 'groups'): ?>
    <div class="card">
        <h2>جستجوی گروه/کانال</h2>
        <form method="get" class="search-form">
            <input type="hidden" name="tab" value="groups">
            <input type="text" name="group_search" placeholder="نام یا شناسه گروه" value="<?= htmlspecialchars($groupSearch) ?>">
            <button class="btn" type="submit">جستجو</button>
        </form>
        <?php if (!empty($groupSearch)): ?>
            <table>
                <tr><th>ID</th><th>نام</th><th>نوع</th><th>اعضا</th><th>لینک</th></tr>
                <?php foreach ($groupResults as $g): ?>
                <tr>
                    <td><?= $g['id'] ?></td>
                    <td><?= htmlspecialchars($g['name']) ?></td>
                    <td><?= $g['type'] == 'channel' ? 'کانال' : 'گروه' ?></td>
                    <td><?= $g['member_count'] ?></td>
                    <td><a href="index.php?page=group&id=<?= $g['id'] ?>" target="_blank" class="btn">مشاهده</a></td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>حذف گروه‌های غیرفعال</h2>
        <form method="post">
            <input type="hidden" name="action" value="delete_inactive_groups">
            <input type="hidden" name="tab" value="groups">
            <div class="form-group">
                <label>حذف گروه‌هایی که آخرین پیامشان قدیمی‌تر از این تعداد روز است:</label>
                <input type="number" name="inactive_days" placeholder="مثلاً 30" required min="1">
            </div>
            <button class="btn btn-danger" onclick="return confirm('گروه‌های غیرفعال حذف شوند؟')">حذف گروه‌های غیرفعال</button>
        </form>
    </div>

    <?php elseif ($tab == 'domains'): ?>
    <div class="card">
        <h2>دامنه‌های مجاز</h2>
        <form method="post" style="display:flex; gap:10px; align-items:center; margin-bottom:15px;">
            <input type="hidden" name="action" value="add_domain">
            <input type="hidden" name="tab" value="domains">
            <input type="text" name="domain" placeholder="مثال: uploadkon.ir" required style="flex:1;">
            <button class="btn">افزودن</button>
        </form>
        <table>
            <tr><th>دامنه</th><th>تاریخ ثبت</th><th>عملیات</th></tr>
            <?php foreach ($domains as $d): ?>
            <tr>
                <td><?= htmlspecialchars($d['domain']) ?></td>
                <td><?= $d['created_at'] ?></td>
                <td>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="delete_domain">
                        <input type="hidden" name="domain_id" value="<?= $d['id'] ?>">
                        <input type="hidden" name="tab" value="domains">
                        <button class="btn btn-danger" onclick="return confirm('حذف شود؟')">حذف</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php elseif ($tab == 'messages'): ?>
    <div class="card">
        <h2>مدیریت پیام‌ها</h2>
        <form method="post" style="margin-bottom:15px;">
            <input type="hidden" name="action" value="delete_messages_user">
            <input type="hidden" name="tab" value="messages">
            <input type="number" name="user_id" placeholder="شناسه کاربر" required>
            <button class="btn btn-danger">حذف همه پیام‌های این کاربر</button>
        </form>
        <form method="post" style="margin-bottom:15px;">
            <input type="hidden" name="action" value="delete_messages_group">
            <input type="hidden" name="tab" value="messages">
            <input type="number" name="group_id" placeholder="شناسه گروه/کانال" required>
            <button class="btn btn-danger">حذف همه پیام‌های این گروه/کانال</button>
        </form>
        <form method="post" style="margin-bottom:15px;">
            <input type="hidden" name="action" value="delete_all_private">
            <input type="hidden" name="tab" value="messages">
            <button class="btn btn-danger" onclick="return confirm('تمام پیام‌های خصوصی حذف شوند؟')">حذف تمام پیام‌های خصوصی</button>
        </form>
        <form method="post" style="margin-bottom:15px;">
            <input type="hidden" name="action" value="delete_all_group">
            <input type="hidden" name="tab" value="messages">
            <button class="btn btn-danger" onclick="return confirm('تمام پیام‌های گروهی حذف شوند؟')">حذف تمام پیام‌های گروهی</button>
        </form>
        <form method="post">
            <input type="hidden" name="action" value="delete_messages_before">
            <input type="hidden" name="tab" value="messages">
            <label>حذف پیام‌های قبل از:</label>
            <input type="date" name="before_date" required>
            <button class="btn btn-danger">حذف</button>
        </form>
    </div>
    <div class="card">
        <h2>حذف گروه/کانال</h2>
        <form method="post">
            <input type="hidden" name="action" value="delete_group">
            <input type="hidden" name="tab" value="messages">
            <input type="number" name="group_id" placeholder="شناسه گروه" required>
            <button class="btn btn-danger" onclick="return confirm('این گروه و تمام اطلاعات آن حذف شود؟')">حذف گروه</button>
        </form>
    </div>
    <?php elseif ($tab == 'inspect'): ?>
    <div class="card">
        <h2>بازرسی کاربر</h2>
        <form method="get">
            <input type="hidden" name="tab" value="inspect">
            <input type="number" name="inspect_user_id" placeholder="شناسه کاربر" required value="<?= htmlspecialchars($_GET['inspect_user_id'] ?? '') ?>">
            <button class="btn">بررسی</button>
        </form>
        <?php if ($inspectUser): ?>
            <h3 style="margin-top:15px;">اطلاعات <?= htmlspecialchars($inspectUser['display_name'] ?: $inspectUser['username']) ?></h3>
            <p>شناسه: <?= $inspectUser['id'] ?> | نام کاربری: <?= htmlspecialchars($inspectUser['username']) ?></p>
            <h4>عضویت در گروه‌ها/کانال‌ها</h4>
            <?php if ($inspectGroups): ?>
                <table><tr><th>گروه</th><th>نقش</th><th>لینک</th></tr>
                <?php foreach ($inspectGroups as $g): ?>
                    <tr>
                        <td><?= htmlspecialchars($g['name']) ?> (<?= $g['type'] ?>)</td>
                        <td><?= $g['role'] ?></td>
                        <td><a href="index.php?page=group&id=<?= $g['id'] ?>" target="_blank" class="btn">مشاهده</a></td>
                    </tr>
                <?php endforeach; ?></table>
            <?php else: ?><p>در هیچ گروهی عضو نیست.</p><?php endif; ?>

            <h4 style="margin-top:15px;">گفتگوهای خصوصی</h4>
            <?php if ($inspectPrivateChats): ?>
                <table><tr><th>کاربر</th><th>عملیات</th></tr>
                <?php foreach ($inspectPrivateChats as $chat): ?>
                    <tr>
                        <td><?= htmlspecialchars($chat['display_name'] ?: $chat['username']) ?> (<?= $chat['id'] ?>)</td>
                        <td><a href="?tab=conversation&user1=<?= $inspectUser['id'] ?>&user2=<?= $chat['id'] ?>" class="btn">مشاهده مکالمه</a></td>
                    </tr>
                <?php endforeach; ?></table>
            <?php else: ?><p>گفتگوی خصوصی ندارد.</p><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php elseif ($tab == 'conversation'): ?>
    <div class="card">
        <h2>مکالمه خصوصی (<?= (int)$_GET['user1'] ?> ↔ <?= (int)$_GET['user2'] ?>)</h2>
        <a href="?tab=inspect&inspect_user_id=<?= (int)$_GET['user1'] ?>" class="btn">بازگشت</a>
        <?php if ($convMessages): ?>
            <?php foreach ($convMessages as $msg): ?>
                <div style="margin:5px 0; padding:5px; background:rgba(255,255,255,0.05); border-radius:8px;">
                    <strong><?= htmlspecialchars($msg['sender_name']) ?>:</strong>
                    <?= htmlspecialchars($msg['message']) ?>
                    <?php if ($msg['file_url']): ?><br><a href="<?= htmlspecialchars($msg['file_url']) ?>" target="_blank">📎 فایل</a><?php endif; ?>
                    <small style="color:var(--secondary-text); display:block;"><?= $msg['sent_at'] ?></small>
                </div>
            <?php endforeach; ?>
        <?php else: ?><p>پیامی یافت نشد.</p><?php endif; ?>
    </div>
    <?php elseif ($tab == 'restrict'): ?>
    <div class="card">
        <h2>مسدود کردن کاربر</h2>
        <form method="post">
            <input type="hidden" name="action" value="restrict_user">
            <input type="hidden" name="tab" value="restrict">
            <div class="form-group">
                <label>شناسه کاربر</label>
                <input type="number" name="user_id" value="<?= htmlspecialchars($_GET['user_id'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>نوع محدودیت</label>
                <select name="restriction_type">
                    <option value="ban">مسدود کامل</option>
                    <option value="message_ban">ممنوعیت پیام</option>
                    <option value="post_ban">ممنوعیت پست</option>
                    <option value="mute">سکوت</option>
                </select>
            </div>
            <div class="form-group">
                <label>دلیل</label>
                <input type="text" name="reason" placeholder="علت" required>
            </div>
            <div class="form-group">
                <label>تاریخ پایان (خالی = دائمی)</label>
                <input type="date" name="expire_date"> <input type="time" name="expire_time" value="23:59">
            </div>
            <button class="btn btn-danger">اعمال مسدودیت</button>
        </form>
        <hr style="margin:15px 0; border-color:var(--border);">
        <h3>رفع مسدودیت</h3>
        <form method="post">
            <input type="hidden" name="action" value="unrestrict_user">
            <input type="hidden" name="tab" value="restrict">
            <input type="number" name="user_id" placeholder="شناسه کاربر" required>
            <button class="btn">رفع مسدودیت</button>
        </form>
    </div>
    <?php endif; ?>
</div>
</body>
</html>