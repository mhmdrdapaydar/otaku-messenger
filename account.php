<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$current_user_id = $_SESSION['user_id'];

// ---------- دریافت اطلاعات کاربر ----------
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$current_user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$message = '';
$error = '';

// ---------- پردازش فرم‌ها ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- به‌روزرسانی پروفایل ----
    if ($action === 'update_profile') {
        $display_name = trim($_POST['display_name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $avatar_url = trim($_POST['avatar_url'] ?? '');

        // بررسی دامنه آواتار
        if (!empty($avatar_url)) {
            $parsed = parse_url($avatar_url);
            if (!isset($parsed['host'])) {
                $error = 'لینک عکس نامعتبر است.';
            } else {
                $domain = str_replace('www.', '', $parsed['host']);
                $stmt = $pdo->prepare("SELECT 1 FROM allowed_domains WHERE domain = ?");
                $stmt->execute([$domain]);
                if (!$stmt->fetchColumn()) {
                    $error = 'دامنه عکس مجاز نیست.';
                }
            }
        }

        if (empty($error)) {
            $stmt = $pdo->prepare("UPDATE users SET display_name = ?, bio = ?, avatar_url = ? WHERE id = ?");
            $stmt->execute([$display_name, $bio, $avatar_url ?: null, $current_user_id]);
            $message = 'پروفایل با موفقیت به‌روزرسانی شد.';
            // بازخوانی اطلاعات
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$current_user_id]);
            $user = $stmt->fetch();
        }
    }

    // ---- ایجاد پست جدید ----
    elseif ($action === 'create_post') {
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $image_url = trim($_POST['image_url'] ?? '');
        $qualities = $_POST['quality'] ?? [];
        $qlinks   = $_POST['qlink']   ?? [];

        if (empty($title)) {
            $error = 'عنوان پست اجباری است.';
        } elseif (empty($image_url)) {
            $error = 'لینک عکس اصلی اجباری است.';
        } else {
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $image_ext = strtolower(pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (!in_array($image_ext, $allowed_extensions)) {
                $error = 'فرمت عکس مجاز نیست. فقط jpg, jpeg, png, gif, webp.';
            } else {
                $parsed = parse_url($image_url);
                if (!isset($parsed['host'])) {
                    $error = 'لینک عکس نامعتبر است.';
                } else {
                    $domain = str_replace('www.', '', $parsed['host']);
                    $stmt = $pdo->prepare("SELECT 1 FROM allowed_domains WHERE domain = ?");
                    $stmt->execute([$domain]);
                    if (!$stmt->fetchColumn()) {
                        $error = 'دامنه عکس مجاز نیست.';
                    }
                }
            }
        }

        if (empty($error)) {
            $media_parts = [];
            $media_parts[] = 'img=' . $image_url;

            for ($i = 0; $i < count($qualities); $i++) {
                $q_label = trim($qualities[$i]);
                $q_link  = trim($qlinks[$i]);
                if ($q_label !== '' && $q_link !== '') {
                    $parsed_q = parse_url($q_link);
                    if (!isset($parsed_q['host'])) {
                        $error = 'لینک کیفیت نامعتبر است.';
                        break;
                    }
                    $q_domain = str_replace('www.', '', $parsed_q['host']);
                    $stmt = $pdo->prepare("SELECT 1 FROM allowed_domains WHERE domain = ?");
                    $stmt->execute([$q_domain]);
                    if (!$stmt->fetchColumn()) {
                        $error = 'دامنه کیفیت مجاز نیست.';
                        break;
                    }
                    $media_parts[] = $q_label . '=' . $q_link;
                }
            }

            if (empty($error)) {
                $media_string = implode(',', $media_parts);
                if (strlen($media_string) > 500) {
                    $error = 'مجموع طول لینک‌ها از ۵۰۰ کاراکتر بیشتر است. لطفاً کیفیت‌های کمتری اضافه کنید یا از لینک‌های کوتاه‌تر استفاده کنید.';
                } else {
                    $share_hash = bin2hex(random_bytes(10));
                    $stmt = $pdo->prepare("INSERT INTO posts (user_id, title, subtitle, media_url, is_auto_generated, share_hash) VALUES (?, ?, ?, ?, 0, ?)");
                    $stmt->execute([$current_user_id, $title, $subtitle, $media_string, $share_hash]);
                    $message = 'پست با موفقیت ایجاد شد.';
                }
            }
        }
    }

    // ---- حذف پست ----
    elseif ($action === 'delete_post') {
        $post_id = (int)$_POST['post_id'];
        $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ? AND user_id = ?");
        $stmt->execute([$post_id, $current_user_id]);
        if ($stmt->rowCount() > 0) {
            $message = 'پست حذف شد.';
        } else {
            $error = 'پست یافت نشد یا متعلق به شما نیست.';
        }
    }
}

// ---------- صفحه‌بندی و جستجوی پست‌ها ----------
$postSearch = $_GET['post_search'] ?? '';
$currentPage = max(1, (int)($_GET['p'] ?? 1));
$perPage = 10;
$offset = ($currentPage - 1) * $perPage;

$where = "WHERE user_id = ?";
$params = [$current_user_id];
if ($postSearch !== '') {
    $where .= " AND title LIKE ?";
    $params[] = "%$postSearch%";
}

// تعداد کل پست‌ها
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts $where");
$countStmt->execute($params);
$totalPosts = $countStmt->fetchColumn();
$totalPages = ceil($totalPosts / $perPage);

// دریافت پست‌های صفحه فعلی
$postsStmt = $pdo->prepare("SELECT * FROM posts $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$postsStmt->execute($params);
$user_posts = $postsStmt->fetchAll();

// ----- تعیین شماره صفحات برای نمایش (حداکثر ۵ عدد) -----
$pagesToShow = [];
if ($totalPages <= 5) {
    $pagesToShow = range(1, $totalPages);
} else {
    // حداقل ۵ صفحه: اولین، آخرین، و سه صفحه حول صفحه جاری
    $pagesToShow[] = 1;
    $start = max(2, $currentPage - 1);
    $end = min($totalPages - 1, $currentPage + 1);
    
    // تنظیم برای نمایش دقیقاً ۵ شماره
    if ($start == 2 && $end == $totalPages - 1) {
        $pagesToShow = array_merge($pagesToShow, range($start, $end));
        $pagesToShow[] = $totalPages;
    } elseif ($start == 2) {
        // نزدیک به ابتدا
        $pagesToShow = array_merge($pagesToShow, range(2, 4));
        $pagesToShow[] = $totalPages;
    } elseif ($end == $totalPages - 1) {
        // نزدیک به انتها
        $pagesToShow = array_merge($pagesToShow, range($totalPages - 3, $totalPages - 1));
        $pagesToShow[] = $totalPages;
        $pagesToShow = array_unique($pagesToShow);
        sort($pagesToShow);
    } else {
        // حالت عادی: سه صفحه وسط
        $pagesToShow = array_merge($pagesToShow, range($currentPage - 1, $currentPage + 1));
        $pagesToShow[] = $totalPages;
    }
    $pagesToShow = array_unique($pagesToShow);
    sort($pagesToShow);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>حساب کاربری | اوتاکو</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root {
            --primary: #ff4d6d;
            --surface: rgba(15,15,35,0.75);
            --bg: #050510;
            --border: rgba(255,77,109,0.2);
            --text: #fff;
            --secondary: #b0b0c0;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: var(--bg);
            background-image: radial-gradient(circle at 20% 30%, #1a1a3e 0%, transparent 40%),
                                radial-gradient(circle at 80% 70%, #2a0a2a 0%, transparent 50%);
            color: var(--text);
            padding: 20px 16px 100px;
            min-height: 100vh;
            text-shadow: 0 0 2px rgba(255,255,255,0.2);
        }
        .container { max-width: 700px; margin: 0 auto; }
        h1 { text-align: center; color: var(--primary); margin-bottom: 25px; }
        .card {
            background: var(--surface);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .card h2 { margin-bottom: 15px; border-bottom: 1px solid var(--border); padding-bottom: 8px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: var(--secondary); font-size: 0.9rem; }
        input, textarea, button, select {
            width: 100%;
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.08);
            color: var(--text);
            font-family: inherit;
            margin-top: 4px;
            transition: 0.3s;
        }
        input:focus, textarea:focus { border-color: var(--primary); outline: none; }
        button {
            background: var(--primary);
            border: none;
            color: #fff;
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
        }
        button:hover { background: #ff8fa3; }
        .btn-danger { background: #b23b3b; }
        .btn-danger:hover { background: #d44; }
        .msg { background: rgba(76,175,80,0.2); border: 1px solid #4caf50; padding: 10px; border-radius: 10px; margin-bottom: 15px; }
        .err { background: rgba(255,77,109,0.2); border: 1px solid var(--primary); padding: 10px; border-radius: 10px; margin-bottom: 15px; }

        /* پروفایل */
        .profile-card {
            display: flex; align-items: center; gap: 15px;
            background: var(--surface);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .profile-avatar {
            width: 60px; height: 60px; border-radius: 50%;
            background: var(--primary); display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; font-weight: bold; overflow: hidden; flex-shrink: 0;
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-info { flex: 1; }
        .profile-info h3 { margin-bottom: 5px; }
        .profile-info p { color: var(--secondary); font-size: 0.9rem; }
        .profile-edit-btn { background: none; border: 1px solid var(--primary); color: var(--primary); padding: 6px 15px; border-radius: 20px; cursor: pointer; }

        /* کیفیت‌ها */
        .quality-row { display: flex; gap: 10px; margin-bottom: 8px; align-items: center; }
        .quality-row input { flex: 1; }
        .quality-row button { width: auto; padding: 6px 12px; font-size: 0.8rem; }

        /* پست‌ها */
        .post-item {
            background: rgba(255,255,255,0.05); padding: 12px; border-radius: 12px;
            margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;
        }
        .post-info { flex: 1; }
        .post-title { font-weight: bold; margin-bottom: 5px; }
        .post-meta { font-size: 0.8rem; color: var(--secondary); }
        .small-btn { padding: 4px 10px; font-size: 0.75rem; border-radius: 8px; width: auto; }

        /* صفحه‌بندی */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 15px; flex-wrap: wrap; }
        .pagination a, .pagination span {
            padding: 6px 12px; border-radius: 10px; background: rgba(255,255,255,0.1);
            color: #fff; text-decoration: none; font-size: 0.9rem; min-width: 35px; text-align: center;
        }
        .pagination a:hover { background: var(--primary); }
        .pagination .current { background: var(--primary); }
        .pagination .disabled { opacity: 0.4; pointer-events: none; }
        .pagination .ellipsis { background: transparent; border: none; padding: 6px 4px; }

        /* جستجوی صفحه */
        .page-jump { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 12px; }
        .page-jump input { width: 60px; padding: 5px 8px; text-align: center; border-radius: 8px; border: 1px solid var(--border); background: rgba(255,255,255,0.08); color: var(--text); }
        .page-jump button { width: auto; padding: 5px 10px; margin: 0; border-radius: 8px; background: var(--primary); border: none; color: white; cursor: pointer; }

        /* نوار پایین */
        .bottom-nav {
            position: fixed; bottom: 15px; left: 50%; transform: translateX(-50%);
            width: 90%; max-width: 480px;
            background: rgba(15, 15, 35, 0.8); backdrop-filter: blur(30px);
            display: flex; justify-content: space-around; align-items: center;
            padding: 6px 10px; border-radius: 35px;
            box-shadow: 0 20px 35px rgba(0,0,0,0.7), 0 0 0 1px rgba(255,77,109,0.4);
            z-index: 100; border: 1px solid rgba(255,255,255,0.15);
        }
        .bottom-nav .nav-item {
            display: flex; flex-direction: column; align-items: center; text-decoration: none;
            color: #999; font-size: 0.65rem; font-weight: 500; padding: 6px 8px;
            border-radius: 20px; transition: color 0.3s, transform 0.3s;
        }
        .bottom-nav .nav-item .icon { font-size: 1.5rem; margin-bottom: 2px; }
        .bottom-nav .nav-item.active .icon { color: #ff4d6d; filter: drop-shadow(0 0 10px #ff4d6d); }
        .bottom-nav .nav-item.active span { color: #fff; font-weight: bold; }
        .bottom-nav .nav-item:not(.active):hover .icon { color: #ff8fa3; }

        @media (max-width: 500px) {
            .quality-row { flex-direction: column; }
            .quality-row input { width: 100%; }
            .profile-card { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>👤 حساب کاربری</h1>

    <?php if ($message): ?><div class="msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- ──────────── کارت پروفایل ساده ──────────── -->
    <div class="profile-card">
        <div class="profile-avatar">
            <?php if (!empty($user['avatar_url'])): ?>
                <img src="<?= htmlspecialchars($user['avatar_url']) ?>" alt="آواتار">
            <?php else: ?>
                <?= mb_substr($user['display_name'] ?: $user['first_name'], 0, 1) ?>
            <?php endif; ?>
        </div>
        <div class="profile-info">
            <h3><?= htmlspecialchars($user['display_name'] ?: $user['first_name'] . ' ' . $user['last_name']) ?></h3>
            <p>@<?= htmlspecialchars($user['username']) ?></p>
            <?php if (!empty($user['bio'])): ?>
                <p><?= htmlspecialchars($user['bio']) ?></p>
            <?php endif; ?>
        </div>
        <button class="profile-edit-btn" onclick="document.getElementById('editProfileForm').style.display='block'">✏️ ویرایش</button>
    </div>

    <!-- ──────────── فرم ویرایش پروفایل (مخفی) ──────────── -->
    <div id="editProfileForm" class="card" style="display: none;">
        <h2>ویرایش پروفایل</h2>
        <form method="post">
            <input type="hidden" name="action" value="update_profile">
            <div class="form-group">
                <label>نام نمایشی</label>
                <input type="text" name="display_name" value="<?= htmlspecialchars($user['display_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>بیوگرافی</label>
                <textarea name="bio" rows="3"><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>لینک عکس پروفایل (فقط از دامنه‌های مجاز)</label>
                <input type="url" name="avatar_url" value="<?= htmlspecialchars($user['avatar_url'] ?? '') ?>">
            </div>
            <button type="submit">💾 ذخیره تغییرات</button>
        </form>
    </div>

    <!-- ──────────── ایجاد پست جدید ──────────── -->
    <div class="card">
        <h2>📢 ایجاد پست جدید</h2>
        <form method="post" id="postForm">
            <input type="hidden" name="action" value="create_post">
            <div class="form-group">
                <label>عنوان *</label>
                <input type="text" name="title" required>
            </div>
            <div class="form-group">
                <label>زیرعنوان (اختیاری)</label>
                <input type="text" name="subtitle">
            </div>
            <div class="form-group">
                <label>لینک عکس اصلی (jpg, png, gif, webp) *</label>
                <input type="url" name="image_url" required>
            </div>

            <div class="form-group">
                <label>کیفیت‌ها و لینک‌ها</label>
                <div id="qualityContainer">
                    <div class="quality-row">
                        <input type="text" name="quality[]" placeholder="مثلاً 240p">
                        <input type="url" name="qlink[]" placeholder="لینک دانلود/استریم">
                        <button type="button" class="btn-danger" onclick="removeQuality(this)" style="display:none;">حذف</button>
                    </div>
                </div>
                <button type="button" onclick="addQuality()" style="width:auto; margin-top:8px;">➕ افزودن کیفیت جدید</button>
                <small style="color:var(--secondary); display:block; margin-top:4px;">توجه: مجموع طول لینک‌ها نباید از ۵۰۰ کاراکتر بیشتر شود.</small>
            </div>

            <button type="submit">🚀 انتشار پست</button>
        </form>
    </div>

    <!-- ──────────── پست‌های من ──────────── -->
    <div class="card">
        <h2>📋 پست‌های من</h2>
        <!-- فرم جستجو -->
        <form method="get" style="display: flex; gap: 10px; margin-bottom: 15px;">
            <input type="text" name="post_search" value="<?= htmlspecialchars($postSearch) ?>" placeholder="جستجوی عنوان..." style="flex:1;">
            <button type="submit" style="width:auto;">🔍</button>
        </form>

        <?php if (empty($user_posts)): ?>
            <p style="color:var(--secondary);">پستی یافت نشد.</p>
        <?php else: ?>
            <?php foreach ($user_posts as $post): ?>
                <div class="post-item">
                    <div class="post-info">
                        <div class="post-title"><?= htmlspecialchars($post['title']) ?></div>
                        <div class="post-meta"><?= $post['created_at'] ?> | بازدید: <?= $post['view_count'] ?></div>
                    </div>
                    <form method="post" onsubmit="return confirm('پست حذف شود؟')">
                        <input type="hidden" name="action" value="delete_post">
                        <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                        <button type="submit" class="small-btn btn-danger">🗑️</button>
                    </form>
                </div>
            <?php endforeach; ?>

            <!-- صفحه‌بندی هوشمند -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($currentPage > 1): ?>
                    <a href="?p=1&post_search=<?= urlencode($postSearch) ?>">« اولین</a>
                    <a href="?p=<?= $currentPage - 1 ?>&post_search=<?= urlencode($postSearch) ?>">‹ قبلی</a>
                <?php else: ?>
                    <span class="disabled">« اولین</span>
                    <span class="disabled">‹ قبلی</span>
                <?php endif; ?>

                <?php
                $lastDisplayed = 0;
                foreach ($pagesToShow as $page) {
                    if ($lastDisplayed > 0 && $page > $lastDisplayed + 1) {
                        echo '<span class="ellipsis">…</span>';
                    }
                    if ($page == $currentPage) {
                        echo '<span class="current">' . $page . '</span>';
                    } else {
                        echo '<a href="?p=' . $page . '&post_search=' . urlencode($postSearch) . '">' . $page . '</a>';
                    }
                    $lastDisplayed = $page;
                }
                ?>

                <?php if ($currentPage < $totalPages): ?>
                    <a href="?p=<?= $currentPage + 1 ?>&post_search=<?= urlencode($postSearch) ?>">بعدی ›</a>
                    <a href="?p=<?= $totalPages ?>&post_search=<?= urlencode($postSearch) ?>">آخرین »</a>
                <?php else: ?>
                    <span class="disabled">بعدی ›</span>
                    <span class="disabled">آخرین »</span>
                <?php endif; ?>
            </div>

            <!-- جستجوی سریع شماره صفحه -->
            <form class="page-jump" onsubmit="jumpToPage(event)">
                <span style="font-size:0.85rem; color:var(--secondary);">پرش به صفحه:</span>
                <input type="number" id="pageNumber" min="1" max="<?= $totalPages ?>" placeholder="<?= $currentPage ?>">
                <button type="submit">برو</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- نوار پایین -->
<nav class="bottom-nav">
    <a href="home.php" class="nav-item" aria-label="خانه"><span class="icon">🏠</span><span>خانه</span></a>
    <a href="index.php" class="nav-item" aria-label="پیام‌رسان"><span class="icon">💬</span><span>پیام‌رسان</span></a>
    <a href="account.php" class="nav-item active" aria-label="حساب"><span class="icon">👤</span><span>حساب</span></a>
    <a href="explore.php" class="nav-item" aria-label="اکسپلور"><span class="icon">🔍</span><span>اکسپلور</span></a>
    <a href="otaku.php" class="nav-item" aria-label="اوتاکو"><span class="icon">🎬</span><span>اوتاکو</span></a>
</nav>

<script>
function addQuality() {
    const container = document.getElementById('qualityContainer');
    const row = document.createElement('div');
    row.className = 'quality-row';
    row.innerHTML = `
        <input type="text" name="quality[]" placeholder="مثلاً 720p">
        <input type="url" name="qlink[]" placeholder="لینک دانلود/استریم">
        <button type="button" class="btn-danger" onclick="removeQuality(this)">حذف</button>
    `;
    container.appendChild(row);
    document.querySelectorAll('.quality-row button').forEach(btn => btn.style.display = 'inline-block');
}

function removeQuality(btn) {
    const row = btn.parentElement;
    row.remove();
    const rows = document.querySelectorAll('.quality-row');
    if (rows.length === 1) {
        rows[0].querySelector('button').style.display = 'none';
    }
}

function jumpToPage(e) {
    e.preventDefault();
    const page = document.getElementById('pageNumber').value;
    const search = '<?= urlencode($postSearch) ?>';
    if (page && page >= 1 && page <= <?= $totalPages ?>) {
        window.location.href = `?p=${page}&post_search=${search}`;
    } else {
        alert('شماره صفحه نامعتبر است.');
    }
}
</script>
</body>
</html>