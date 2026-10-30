<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$current_user_id = $_SESSION['user_id'];
$group_id = (int)($_GET['id'] ?? 0);
if (!$group_id) die("گروه نامعتبر");

$group = $pdo->prepare("SELECT * FROM groups_channels WHERE id=?");
$group->execute([$group_id]);
$group = $group->fetch();
if (!$group) die("گروه وجود ندارد");

$member = $pdo->prepare("SELECT * FROM group_channel_members WHERE group_id=? AND user_id=?");
$member->execute([$group_id, $current_user_id]);
$member = $member->fetch();
if (!$member || !in_array($member['role'], ['owner', 'admin'])) {
    die("شما دسترسی ندارید.");
}
$is_owner = ($member['role'] == 'owner');

$current_admin_perms = [];
if (!$is_owner) {
    $stmt = $pdo->prepare("SELECT * FROM admin_permissions WHERE member_id = ?");
    $stmt->execute([$member['id']]);
    $current_admin_perms = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$error_msg = '';

// ----- درخواست‌های AJAX برای لیست اعضا -----
if (isset($_GET['ajax']) && $_GET['ajax'] === 'members') {
    $search = $_GET['search'] ?? '';
    $offset = (int)($_GET['offset'] ?? 0);
    $limit = 30;

    $sql = "SELECT m.*, u.username, u.display_name, u.first_name, u.last_name 
            FROM group_channel_members m 
            JOIN users u ON m.user_id = u.id 
            WHERE m.group_id = ?";
    $params = [$group_id];

    if (!empty($search)) {
        $sql .= " AND (u.username LIKE ? OR u.display_name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        $like = "%$search%";
        array_push($params, $like, $like, $like, $like);
    }
    $sql .= " ORDER BY m.role = 'owner' DESC, m.role = 'admin' DESC, m.id ASC";
    $sql .= " LIMIT $limit OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // دریافت مجوزهای مدیران برای ارسال به کلاینت
    $adminPerms = [];
    foreach ($members as $mem) {
        if ($mem['role'] === 'admin') {
            $stmt2 = $pdo->prepare("SELECT * FROM admin_permissions WHERE member_id = ?");
            $stmt2->execute([$mem['id']]);
            $adminPerms[$mem['id']] = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'members' => $members,
        'admin_perms' => $adminPerms,
        'has_more' => count($members) === $limit
    ]);
    exit;
}

// ----- پردازش فرم‌ها (POST) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_member_perm' && ($is_owner || ($current_admin_perms['can_manage_permissions'] ?? 0))) {
        $target_member_id = (int)$_POST['member_id'];
        $perm = $_POST['perm'];
        $value = (int)$_POST['value'];
        $allowed = ['can_send_message', 'can_add_user', 'can_view_admins', 'can_view_users'];
        if (in_array($perm, $allowed)) {
            $pdo->prepare("UPDATE group_channel_members SET $perm = ? WHERE id = ? AND group_id = ?")->execute([$value, $target_member_id, $group_id]);
        }
    }
    elseif ($action === 'remove_member' && ($is_owner || ($current_admin_perms['can_ban_users'] ?? 0))) {
        $target_member_id = (int)$_POST['member_id'];
        $pdo->prepare("DELETE FROM group_channel_members WHERE id=? AND group_id=? AND role != 'owner'")->execute([$target_member_id, $group_id]);
    }
    elseif ($action === 'ban_member' && ($is_owner || ($current_admin_perms['can_ban_users'] ?? 0))) {
        $target_user_id = (int)$_POST['user_id'];
        $check = $pdo->prepare("SELECT role FROM group_channel_members WHERE group_id = ? AND user_id = ?");
        $check->execute([$group_id, $target_user_id]);
        $target_role = $check->fetchColumn();
        if ($target_role && $target_role !== 'owner') {
            $pdo->prepare("DELETE FROM group_channel_members WHERE group_id = ? AND user_id = ?")->execute([$group_id, $target_user_id]);
            $pdo->prepare("INSERT IGNORE INTO group_bans (group_id, user_id, banned_by) VALUES (?, ?, ?)")->execute([$group_id, $target_user_id, $current_user_id]);
        }
    }
    elseif ($action === 'unban_member' && ($is_owner || ($current_admin_perms['can_ban_users'] ?? 0))) {
        $target_user_id = (int)$_POST['user_id'];
        $pdo->prepare("DELETE FROM group_bans WHERE group_id = ? AND user_id = ?")->execute([$group_id, $target_user_id]);
    }
    elseif ($action === 'promote_admin' && ($is_owner || ($current_admin_perms['can_add_admin'] ?? 0))) {
        $target_member_id = (int)$_POST['member_id'];
        $pdo->prepare("UPDATE group_channel_members SET role='admin' WHERE id=? AND group_id=?")->execute([$target_member_id, $group_id]);

        if ($is_owner) {
            $perms_to_set = [
                'can_change_info' => 1, 'can_invite_link' => 1, 'can_pin_message' => 1,
                'can_add_admin' => 1, 'can_delete_messages' => 1, 'can_ban_users' => 1,
                'can_manage_permissions' => 1, 'can_send_message' => 1, 'can_edit_messages' => 1
            ];
        } else {
            $perms_to_set = $current_admin_perms;
        }
        $pdo->prepare("INSERT IGNORE INTO admin_permissions (member_id, can_change_info, can_invite_link, can_pin_message, can_add_admin, can_delete_messages, can_ban_users, can_manage_permissions, can_send_message, can_edit_messages)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $target_member_id,
                $perms_to_set['can_change_info'] ?? 0, $perms_to_set['can_invite_link'] ?? 0,
                $perms_to_set['can_pin_message'] ?? 0, $perms_to_set['can_add_admin'] ?? 0,
                $perms_to_set['can_delete_messages'] ?? 0, $perms_to_set['can_ban_users'] ?? 0,
                $perms_to_set['can_manage_permissions'] ?? 0, $perms_to_set['can_send_message'] ?? 1,
                $perms_to_set['can_edit_messages'] ?? 0
            ]);
    }
    elseif ($action === 'demote_admin' && $is_owner) {
        $target_member_id = (int)$_POST['member_id'];
        $pdo->prepare("UPDATE group_channel_members SET role='member' WHERE id=?")->execute([$target_member_id]);
        $pdo->prepare("DELETE FROM admin_permissions WHERE member_id=?")->execute([$target_member_id]);
    }
    elseif ($action === 'update_admin_perm' && ($is_owner || ($current_admin_perms['can_add_admin'] ?? 0))) {
        $target_member_id = (int)$_POST['member_id'];
        $perm = $_POST['perm'];
        $value = (int)$_POST['value'];
        $all_perms = ['can_change_info','can_invite_link','can_pin_message','can_add_admin',
                      'can_delete_messages','can_ban_users','can_manage_permissions',
                      'can_send_message','can_edit_messages','can_view_admins','can_view_members'];
        if (in_array($perm, $all_perms)) {
            $pdo->prepare("INSERT IGNORE INTO admin_permissions (member_id) VALUES (?)")->execute([$target_member_id]);
            $pdo->prepare("UPDATE admin_permissions SET $perm = ? WHERE member_id = ?")->execute([$value, $target_member_id]);
        }
    }
    elseif ($action === 'update_group_info' && ($is_owner || ($current_admin_perms['can_change_info'] ?? 0))) {
        $name = trim($_POST['name']);
        $desc = trim($_POST['description'] ?? '');
        $avatar_url = trim($_POST['avatar_url'] ?? '');
        $slug = trim($_POST['slug'] ?? '');

        if (!empty($avatar_url)) {
            $parsed = parse_url($avatar_url);
            if (!isset($parsed['host'])) {
                $error_msg = 'لینک عکس نامعتبر است.';
            } else {
                $domain = str_replace('www.', '', $parsed['host']);
                $stmtDomain = $pdo->prepare("SELECT 1 FROM allowed_domains WHERE domain = ?");
                $stmtDomain->execute([$domain]);
                if (!$stmtDomain->fetchColumn()) {
                    $error_msg = 'دامنه عکس مجاز نیست.';
                }
            }
        }

        if (empty($error_msg) && $slug !== '' && $slug !== ($group['slug'] ?? '')) {
            $stmt = $pdo->prepare("SELECT 1 FROM groups_channels WHERE slug = ? AND id != ?");
            $stmt->execute([$slug, $group_id]);
            if ($stmt->fetchColumn()) {
                $error_msg = 'این لینک یکتا قبلاً استفاده شده است.';
            } else {
                $stmt = $pdo->prepare("SELECT 1 FROM users WHERE username = ?");
                $stmt->execute([$slug]);
                if ($stmt->fetchColumn()) {
                    $error_msg = 'این لینک یکتا توسط یک کاربر ثبت شده است.';
                }
            }
        }

        if (empty($error_msg)) {
            $pdo->prepare("UPDATE groups_channels SET name=?, description=?, avatar_url=?, slug=? WHERE id=?")
                ->execute([$name, $desc, $avatar_url ?: null, $slug ?: null, $group_id]);

            $group = $pdo->prepare("SELECT * FROM groups_channels WHERE id=?");
            $group->execute([$group_id]);
            $group = $group->fetch();
            header("Location: group_settings.php?id=$group_id");
            exit;
        }
    }
    elseif ($action === 'create_invite_link' && ($is_owner || ($current_admin_perms['can_invite_link'] ?? 0))) {
        $max_uses = (int)$_POST['max_uses'];
        $expire_min = (int)$_POST['expire_minutes'];
        $hash = bin2hex(random_bytes(16));
        $expires = $expire_min > 0 ? date('Y-m-d H:i:s', time() + $expire_min*60) : null;
        $pdo->prepare("INSERT INTO group_invite_links (group_id, hash, created_by, max_uses, expires_at) VALUES (?,?,?,?,?)")->execute([$group_id, $hash, $current_user_id, $max_uses, $expires]);
        header("Location: group_settings.php?id=$group_id");
        exit;
    }
    elseif ($action === 'deactivate_link' && ($is_owner || ($current_admin_perms['can_invite_link'] ?? 0))) {
        $link_id = (int)$_POST['link_id'];
        $pdo->prepare("UPDATE group_invite_links SET is_active=0 WHERE id=? AND group_id=?")->execute([$link_id, $group_id]);
        header("Location: group_settings.php?id=$group_id");
        exit;
    }
    elseif ($action === 'delete_group' && $is_owner) {
        $pdo->prepare("DELETE FROM groups_channels WHERE id = ?")->execute([$group_id]);
        header("Location: index.php?page=home");
        exit;
    }

    if (empty($error_msg) && $action !== 'update_group_info') {
        header("Location: group_settings.php?id=$group_id");
        exit;
    }
}

// ----- داده‌های اولیه (برای مدیران و لینک‌ها) -----
$allMembersForAdmin = $pdo->prepare("SELECT m.*, u.username, u.display_name, u.first_name, u.last_name FROM group_channel_members m JOIN users u ON m.user_id = u.id WHERE m.group_id = ? AND m.role IN ('owner', 'admin')");
$allMembersForAdmin->execute([$group_id]);
$adminMembers = $allMembersForAdmin->fetchAll();

$admin_perms_saved = [];
foreach ($adminMembers as $mem) {
    if ($mem['role'] == 'admin') {
        $stmt = $pdo->prepare("SELECT * FROM admin_permissions WHERE member_id = ?");
        $stmt->execute([$mem['id']]);
        $admin_perms_saved[$mem['id']] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

$invite_links = $pdo->prepare("SELECT * FROM group_invite_links WHERE group_id=? ORDER BY created_at DESC");
$invite_links->execute([$group_id]);
$invite_links = $invite_links->fetchAll();

$bans = $pdo->prepare("SELECT b.*, u.username, u.display_name, u.first_name, u.last_name 
                      FROM group_bans b 
                      JOIN users u ON b.user_id = u.id 
                      WHERE b.group_id = ?");
$bans->execute([$group_id]);
$banned_users = $bans->fetchAll();

// تعداد کل اعضا برای نمایش در عنوان کشویی
$totalMembers = $pdo->prepare("SELECT COUNT(*) FROM group_channel_members WHERE group_id = ?");
$totalMembers->execute([$group_id]);
$totalMembersCount = $totalMembers->fetchColumn();

// محاسبه base URL برای لینک دعوت
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تنظیمات <?= htmlspecialchars($group['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root {
            --primary: #ff4d6d;
            --primary-hover: #ff8fa3;
            --surface: rgba(15, 15, 35, 0.75);
            --bg: #050510;
            --border: rgba(255, 77, 109, 0.2);
            --text: #fff;
            --secondary-text: #b0b0c0;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            background-image: 
                radial-gradient(circle at 20% 30%, #1a1a3e 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, #2a0a2a 0%, transparent 50%);
            min-height: 100vh;
            color: var(--text);
            padding: 20px 16px;
            position: relative;
            overflow-x: hidden;
        }
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

        .container { position: relative; z-index: 2; max-width: 650px; margin: 0 auto; }
        .page-header {
            display: flex; align-items: center; justify-content: space-between;
            background: rgba(15, 15, 35, 0.8); backdrop-filter: blur(25px);
            border: 1px solid var(--border); border-radius: 20px;
            padding: 12px 20px; margin-bottom: 20px;
        }
        .page-header h2 { font-size: 1.4rem; font-weight: 500; }
        .btn-back {
            background: rgba(255,255,255,0.1); border: 1px solid var(--border);
            color: var(--text); padding: 6px 14px; border-radius: 20px;
            text-decoration: none; font-size: 0.9rem; transition: 0.3s;
        }
        .btn-back:hover { background: var(--primary); border-color: var(--primary); }

        .card {
            background: rgba(15, 15, 35, 0.7); backdrop-filter: blur(25px);
            border: 1px solid var(--border); border-radius: 20px;
            padding: 20px; margin-bottom: 20px;
        }
        .card h3 { font-size: 1.2rem; margin-bottom: 15px; border-bottom: 1px solid var(--border); padding-bottom: 8px; }
        input, select, textarea {
            width: 100%; padding: 10px 14px; margin: 5px 0 10px;
            background: rgba(255,255,255,0.08); border: 1px solid var(--border);
            border-radius: 12px; color: var(--text); outline: none; font-family: inherit;
        }
        input:focus, textarea:focus { border-color: var(--primary); }
        .btn {
            background: var(--primary); border: none; padding: 8px 18px;
            border-radius: 20px; color: #fff; cursor: pointer; font-size: 0.9rem;
            transition: 0.3s; margin: 2px;
        }
        .btn:hover { background: var(--primary-hover); }
        .btn-danger { background: #b23b3b; }
        .btn-danger:hover { background: #d44; }

        .list-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05);
            flex-wrap: wrap; gap: 8px;
        }
        .perm-row {
            display: flex; gap: 10px; margin: 5px 0; flex-wrap: wrap; align-items: center;
        }
        .perm-toggle {
            display: flex; align-items: center; gap: 4px; font-size: 0.8rem;
            background: rgba(255,255,255,0.05); padding: 4px 10px; border-radius: 20px;
            cursor: pointer;
        }
        .perm-toggle input { width: auto; margin:0; }
        .error { color: #ff6b81; margin-bottom: 10px; }
        small { color: var(--secondary-text); }
        details summary { cursor: pointer; color: var(--primary); margin: 5px 0; }
        details .perm-row { padding-right: 15px; }

        /* استایل کشویی اعضا */
        .collapsible-header {
            display: flex; justify-content: space-between; align-items: center;
            cursor: pointer; padding: 15px 0;
        }
        .collapsible-header h3 { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .collapsible-header .toggle-icon {
            font-size: 1.4rem; transition: transform 0.3s;
        }
        .collapsible-header.open .toggle-icon { transform: rotate(180deg); }
        .members-content { display: none; }
        .members-content.open { display: block; }

        .load-more-btn { text-align: center; margin-top: 10px; }
        @media (max-width: 500px) {
            .list-item { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
<div class="particles">
    <div class="particle"></div><div class="particle"></div><div class="particle"></div>
    <div class="particle"></div><div class="particle"></div><div class="particle"></div>
</div>

<div class="container">
    <div class="page-header">
        <h2>⚙️ تنظیمات <?= htmlspecialchars($group['name']) ?></h2>
        <a href="index.php?page=group&id=<?= $group_id ?>" class="btn-back">🔙 بازگشت به گروه</a>
    </div>

    <?php if ($error_msg): ?>
        <div class="error"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <?php if ($is_owner || ($current_admin_perms['can_change_info'] ?? 0)): ?>
    <div class="card">
        <h3>ویرایش اطلاعات</h3>
        <form method="post">
            <input type="hidden" name="action" value="update_group_info">
            <input name="name" value="<?= htmlspecialchars($group['name']) ?>" placeholder="نام گروه/کانال" required>
            <textarea name="description" rows="3" placeholder="توضیحات"><?= htmlspecialchars($group['description'] ?? '') ?></textarea>
            <input type="url" name="avatar_url" value="<?= htmlspecialchars($group['avatar_url'] ?? '') ?>" placeholder="لینک عکس پروفایل">
            <small style="color: var(--secondary-text);">فقط دامنه‌های مجاز (مانند uploadkon.ir)</small>
            <input type="text" name="slug" value="<?= htmlspecialchars($group['slug'] ?? '') ?>" placeholder="لینک یکتا (مثلاً my-anime-group)">
            <small>فقط حروف، اعداد و خط تیره. با نام‌کاربری و اسلاگ‌های دیگر تداخل نداشته باشد.</small>
            <br><button class="btn" style="margin-top:10px;">💾 ذخیره</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ─── لیست مدیران (فقط برای مجازها) ─── -->
    <?php if ($is_owner || ($current_admin_perms['can_view_admins'] ?? 1)): ?>
    <div class="card">
        <h3>👑 مدیران</h3>
        <?php foreach ($adminMembers as $mem): 
            $is_owner_mem = ($mem['role'] == 'owner');
            $is_admin_mem = ($mem['role'] == 'admin');
            $name = htmlspecialchars($mem['display_name'] ?: $mem['first_name'].' '.$mem['last_name']);
        ?>
            <div class="list-item">
                <span><?= $name ?> - <?= $mem['role'] ?></span>
                <div style="display:flex; gap:4px; flex-wrap:wrap;">
                    <?php if (!$is_owner_mem && $is_owner): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="demote_admin">
                            <input type="hidden" name="member_id" value="<?= $mem['id'] ?>">
                            <button class="btn btn-danger">⬇️ تنزل</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($is_admin_mem): 
                $ap = $admin_perms_saved[$mem['id']] ?? [];
            ?>
            <details>
                <summary>مجوزهای مدیر (کلیک برای باز شدن)</summary>
                <div class="perm-row" style="margin-top:5px;">
                <?php
                $admin_perm_list = [
                    'can_change_info'=>'تغییر اطلاعات', 'can_invite_link'=>'دعوت از لینک',
                    'can_pin_message'=>'سنجاق پیام', 'can_add_admin'=>'افزودن مدیر',
                    'can_delete_messages'=>'حذف پیام', 'can_ban_users'=>'اخراج کاربر',
                    'can_manage_permissions'=>'مدیریت دسترسی اعضا', 'can_send_message'=>'ارسال پیام',
                    'can_edit_messages'=>'ویرایش پیام'
                ];
                foreach ($admin_perm_list as $pkey => $plabel):
                    $checked = $ap[$pkey] ?? 0;
                    $disabled = (!$is_owner && !($current_admin_perms['can_add_admin'] ?? 0)) ? 'disabled' : '';
                ?>
                <label class="perm-toggle">
                    <input type="checkbox" <?= $checked ? 'checked' : '' ?> <?= $disabled ?>
                           onchange="updateAdminPerm(<?= $mem['id'] ?>, '<?= $pkey ?>', this.checked)">
                    <?= $plabel ?>
                </label>
                <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ─── اعضا (کشویی) ─── -->
    <div class="card">
        <div class="collapsible-header" id="membersToggle" onclick="toggleMembers()">
            <h3>👥 اعضا (<?= $totalMembersCount ?>)</h3>
            <span class="toggle-icon">▼</span>
        </div>
        <div class="members-content" id="membersContent">
            <input type="text" id="memberSearch" placeholder="جستجوی عضو..." oninput="loadMembers(true)" style="margin-bottom: 15px;">
            <div id="membersList"></div>
            <div class="load-more-btn" id="loadMoreContainer" style="display:none;">
                <button class="btn" onclick="loadMembers(false)">بارگذاری بیشتر</button>
            </div>
        </div>
    </div>

    <!-- ─── کاربران مسدود ─── -->
    <div class="card">
        <h3>🚫 کاربران مسدود (بن‌شده)</h3>
        <?php if (empty($banned_users)): ?>
            <p style="color:var(--secondary-text)">هیچ کاربری بن نشده است.</p>
        <?php else: ?>
            <?php foreach ($banned_users as $ban): 
                $bname = htmlspecialchars($ban['display_name'] ?: ($ban['first_name'] . ' ' . $ban['last_name']));
            ?>
                <div class="list-item">
                    <span><?= $bname ?> (<?= htmlspecialchars($ban['username']) ?>)</span>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="unban_member">
                        <input type="hidden" name="user_id" value="<?= $ban['user_id'] ?>">
                        <button class="btn">✅ رفع بن</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ─── لینک‌های دعوت ─── -->
    <?php if ($is_owner || ($current_admin_perms['can_invite_link'] ?? 0)): ?>
    <div class="card">
        <h3>🔗 لینک‌های دعوت</h3>
        <?php foreach ($invite_links as $link): 
            $inviteUrl = $baseUrl . '/invite.php?hash=' . $link['hash'];
        ?>
            <div class="list-item">
                <span>
                    <?= substr($link['hash'],0,12) ?>... 
                    (<?= $link['current_uses'] ?>/<?= $link['max_uses']?:'∞' ?>)
                    <?= $link['is_active'] ? '🟢' : '🔴' ?>
                </span>
                <div style="display:flex; align-items:center; gap:8px;">
                    <?php if ($link['is_active']): ?>
                        <button class="btn" onclick="copyToClipboard('<?= htmlspecialchars($inviteUrl) ?>')">📋 کپی</button>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="deactivate_link">
                            <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
                            <button class="btn btn-danger">غیرفعال کردن</button>
                        </form>
                    <?php endif; ?>
                    <small><?= $link['expires_at'] ? 'انقضا: '.date('Y/m/d H:i', strtotime($link['expires_at'])) : 'بدون انقضا' ?></small>
                </div>
            </div>
        <?php endforeach; ?>
        <form method="post" style="margin-top:10px;">
            <input type="hidden" name="action" value="create_invite_link">
            <div style="display:flex; gap:10px; align-items:center;">
                <input name="max_uses" placeholder="حداکثر استفاده (0=نامحدود)" value="0" style="width:50%;">
                <input name="expire_minutes" placeholder="انقضا به دقیقه (0=بدون انقضا)" value="0" style="width:50%;">
            </div>
            <button class="btn" style="margin-top:10px;">➕ ایجاد لینک جدید</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($is_owner): ?>
    <div class="card" style="border-color:#b23b3b;">
        <h3>🗑️ منطقه خطر</h3>
        <form method="post" onsubmit="return confirm('کل گروه و تمام پیام‌ها و اعضا حذف خواهند شد. ادامه می‌دهید؟')">
            <input type="hidden" name="action" value="delete_group">
            <button class="btn btn-danger">حذف کامل گروه</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
// متغیرهای گلوبال برای اعضا
let memberOffset = 0;
let memberHasMore = true;
let isLoadingMembers = false;
let currentSearch = '';
let membersLoaded = false;

// Toggle بخش اعضا
function toggleMembers() {
    const content = document.getElementById('membersContent');
    const toggle = document.getElementById('membersToggle');
    content.classList.toggle('open');
    toggle.classList.toggle('open');
    
    // اگر اولین بار است که باز می‌شود، اعضا را لود کن
    if (!membersLoaded && content.classList.contains('open')) {
        loadMembers(true);
        membersLoaded = true;
    }
}

async function loadMembers(reset = false) {
    if (isLoadingMembers) return;
    if (reset) {
        memberOffset = 0;
        memberHasMore = true;
        currentSearch = document.getElementById('memberSearch').value.trim();
        document.getElementById('membersList').innerHTML = '';
    }

    isLoadingMembers = true;
    const res = await fetch(`group_settings.php?id=<?= $group_id ?>&ajax=members&offset=${memberOffset}&search=${encodeURIComponent(currentSearch)}`);
    const data = await res.json();

    if (data.members && data.members.length > 0) {
        data.members.forEach(mem => renderMemberItem(mem, data.admin_perms));
        memberOffset += data.members.length;
        memberHasMore = data.has_more;
    } else {
        memberHasMore = false;
    }

    document.getElementById('loadMoreContainer').style.display = memberHasMore ? 'block' : 'none';
    isLoadingMembers = false;
}

function renderMemberItem(mem, adminPerms) {
    const isOwner = mem.role === 'owner';
    const isAdmin = mem.role === 'admin';
    const name = mem.display_name || (mem.first_name + ' ' + mem.last_name);

    const div = document.createElement('div');
    div.className = 'list-item';
    div.id = 'member-' + mem.id;

    let actionsHtml = '';
    if (!isOwner) {
        if (isAdmin && <?= $is_owner ? 'true' : 'false' ?>) {
            actionsHtml += `
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="demote_admin">
                    <input type="hidden" name="member_id" value="${mem.id}">
                    <button class="btn btn-danger">⬇️ تنزل</button>
                </form>
            `;
        } else if (!isAdmin) {
            if (<?= $is_owner || ($current_admin_perms['can_ban_users'] ?? 0) ? 'true' : 'false' ?>) {
                actionsHtml += `
                    <button class="btn btn-danger" onclick="banMember(${mem.user_id})">⛔ بن</button>
                    <button class="btn btn-danger" onclick="removeMember(${mem.id})">🚫 اخراج</button>
                `;
            }
            if (<?= $is_owner || ($current_admin_perms['can_add_admin'] ?? 0) ? 'true' : 'false' ?>) {
                actionsHtml += `
                    <button class="btn" onclick="promoteAdmin(${mem.id})">⭐ ارتقا</button>
                `;
            }
        }
    }

    div.innerHTML = `
        <span>${name} - ${mem.role}</span>
        <div style="display:flex; gap:4px; flex-wrap:wrap;">${actionsHtml}</div>
    `;

    // چک‌باکس‌های دسترسی برای اعضای معمولی
    if (!isOwner && !isAdmin && (<?= $is_owner || ($current_admin_perms['can_manage_permissions'] ?? 0) ? 'true' : 'false' ?>)) {
        const permRow = document.createElement('div');
        permRow.className = 'perm-row';
        const perms = { can_send_message: 'ارسال پیام', can_add_user: 'افزودن کاربر', can_view_admins: 'مشاهده مدیران', can_view_users: 'مشاهده اعضا' };
        for (const [key, label] of Object.entries(perms)) {
            const checked = mem[key] == 1 ? 'checked' : '';
            permRow.innerHTML += `
                <label class="perm-toggle">
                    <input type="checkbox" ${checked} onchange="updateMemberPerm(${mem.id}, '${key}', this.checked)">
                    ${label}
                </label>
            `;
        }
        div.appendChild(permRow);
    }

    // نمایش مجوزهای مدیران
    if (isAdmin && adminPerms[mem.id]) {
        const details = document.createElement('details');
        const summary = document.createElement('summary');
        summary.textContent = 'مجوزهای مدیر';
        details.appendChild(summary);

        const permDiv = document.createElement('div');
        permDiv.className = 'perm-row';
        const adminPermList = [
            'can_change_info', 'can_invite_link', 'can_pin_message', 'can_add_admin',
            'can_delete_messages', 'can_ban_users', 'can_manage_permissions',
            'can_send_message', 'can_edit_messages'
        ];
        const labels = {
            can_change_info: 'تغییر اطلاعات', can_invite_link: 'دعوت از لینک',
            can_pin_message: 'سنجاق پیام', can_add_admin: 'افزودن مدیر',
            can_delete_messages: 'حذف پیام', can_ban_users: 'اخراج کاربر',
            can_manage_permissions: 'مدیریت دسترسی', can_send_message: 'ارسال پیام',
            can_edit_messages: 'ویرایش پیام'
        };
        adminPermList.forEach(perm => {
            const checked = adminPerms[mem.id][perm] == 1 ? 'checked' : '';
            const disabled = (!<?= $is_owner ? 'true' : 'false' ?> && !<?= $current_admin_perms['can_add_admin'] ?? 0 ?>) ? 'disabled' : '';
            permDiv.innerHTML += `
                <label class="perm-toggle">
                    <input type="checkbox" ${checked} ${disabled} onchange="updateAdminPerm(${mem.id}, '${perm}', this.checked)">
                    ${labels[perm]}
                </label>
            `;
        });
        details.appendChild(permDiv);
        div.appendChild(details);
    }

    document.getElementById('membersList').appendChild(div);
}

// توابع عملیاتی
function updateMemberPerm(memberId, perm, value) {
    fetch('group_settings.php?id=<?= $group_id ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=update_member_perm&member_id=${memberId}&perm=${perm}&value=${value?1:0}`
    });
}

function updateAdminPerm(memberId, perm, value) {
    fetch('group_settings.php?id=<?= $group_id ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=update_admin_perm&member_id=${memberId}&perm=${perm}&value=${value?1:0}`
    });
}

function removeMember(memberId) {
    if (!confirm('عضو از گروه اخراج شود؟')) return;
    fetch('group_settings.php?id=<?= $group_id ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=remove_member&member_id=${memberId}`
    }).then(() => loadMembers(true));
}

function banMember(userId) {
    if (!confirm('کاربر بن دائمی شود؟')) return;
    fetch('group_settings.php?id=<?= $group_id ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=ban_member&user_id=${userId}`
    }).then(() => loadMembers(true));
}

function promoteAdmin(memberId) {
    fetch('group_settings.php?id=<?= $group_id ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=promote_admin&member_id=${memberId}`
    }).then(() => loadMembers(true));
}

function copyToClipboard(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => alert('لینک در کلیپ‌بورد کپی شد.'));
    } else {
        prompt('لینک دعوت:', text);
    }
}
</script>
</body>
</html>