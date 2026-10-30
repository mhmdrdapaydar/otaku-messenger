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

    // ---- حذف پست خاص ----
    if ($action === 'delete_post') {
        $post_id = (int)($_POST['post_id'] ?? 0);
        if ($post_id > 0) {
            $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$post_id]);
            $pdo->prepare("DELETE FROM post_likes WHERE post_id = ?")->execute([$post_id]);
            $pdo->prepare("DELETE FROM post_comments WHERE post_id = ?")->execute([$post_id]);
            $message = "پست شماره {$post_id} و تمام داده‌های وابسته حذف شد.";
        } else {
            $error = "شناسه پست نامعتبر است.";
        }
    }

    // ---- حذف تمام پست‌های خودکار ----
    elseif ($action === 'delete_auto_posts') {
        $stmt = $pdo->prepare("SELECT id FROM posts WHERE is_auto_generated = 1");
        $stmt->execute();
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($ids)) {
            $pdo->exec("DELETE FROM post_likes WHERE post_id IN (SELECT id FROM posts WHERE is_auto_generated = 1)");
            $pdo->exec("DELETE FROM post_comments WHERE post_id IN (SELECT id FROM posts WHERE is_auto_generated = 1)");
            $pdo->exec("DELETE FROM posts WHERE is_auto_generated = 1");
            $message = "تمام پست‌های خودکار (" . count($ids) . " عدد) حذف شدند.";
        } else {
            $message = "هیچ پست خودکاری یافت نشد.";
        }
    }

    // ---- حذف پست‌های قدیمی ----
    elseif ($action === 'delete_old_posts') {
        $days = (int)($_POST['days'] ?? 0);
        if ($days > 0) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $stmt = $pdo->prepare("SELECT id FROM posts WHERE created_at < ?");
            $stmt->execute([$cutoff]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($ids)) {
                $pdo->prepare("DELETE FROM post_likes WHERE post_id IN (SELECT id FROM posts WHERE created_at < ?)")->execute([$cutoff]);
                $pdo->prepare("DELETE FROM post_comments WHERE post_id IN (SELECT id FROM posts WHERE created_at < ?)")->execute([$cutoff]);
                $pdo->prepare("DELETE FROM posts WHERE created_at < ?")->execute([$cutoff]);
                $message = "پست‌های قدیمی‌تر از {$days} روز (" . count($ids) . " عدد) حذف شدند.";
            } else {
                $message = "هیچ پستی قدیمی‌تر از {$days} روز یافت نشد.";
            }
        } else {
            $error = "تعداد روز باید بیشتر از صفر باشد.";
        }
    }

    // ---- حذف تمام پست‌های یک کاربر ----
    elseif ($action === 'delete_user_posts') {
        $target_user_id = (int)($_POST['user_id'] ?? 0);
        if ($target_user_id > 0) {
            $pdo->prepare("DELETE FROM post_likes WHERE post_id IN (SELECT id FROM posts WHERE user_id = ?)")->execute([$target_user_id]);
            $pdo->prepare("DELETE FROM post_comments WHERE post_id IN (SELECT id FROM posts WHERE user_id = ?)")->execute([$target_user_id]);
            $pdo->prepare("DELETE FROM posts WHERE user_id = ?")->execute([$target_user_id]);
            $message = "تمام پست‌های کاربر شماره {$target_user_id} حذف شدند.";
        } else {
            $error = "شناسه کاربر نامعتبر است.";
        }
    }

}

// ---------------------- جستجوی کاربران ----------------------
$userSearch = $_GET['user_search'] ?? '';
$searchResults = [];
$totalUserPosts = 0;
$searchedUserId = null;

if (!empty($userSearch)) {
    $like = "%$userSearch%";
    $stmt = $pdo->prepare("SELECT id, username, display_name, first_name, last_name FROM users WHERE username LIKE ? OR display_name LIKE ? OR first_name LIKE ? OR last_name LIKE ? LIMIT 10");
    $stmt->execute([$like, $like, $like, $like]);
    $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// اگر کاربر خاصی انتخاب شده باشد (برای نمایش پست‌هایش)
$searchedUserId = isset($_GET['searched_user_id']) ? (int)$_GET['searched_user_id'] : null;
$userPosts = [];
$userInfo = null;
if ($searchedUserId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$searchedUserId]);
    $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($userInfo) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = ?");
        $stmt->execute([$searchedUserId]);
        $totalUserPosts = $stmt->fetchColumn();
        $page = max(1, (int)($_GET['up'] ?? 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT * FROM posts WHERE user_id = ? ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute([$searchedUserId]);
        $userPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ---------------------- آمار کلی ----------------------
$total_auto_posts = $pdo->query("SELECT COUNT(*) FROM posts WHERE is_auto_generated = 1")->fetchColumn();
$total_user_posts = $pdo->query("SELECT COUNT(*) FROM posts WHERE is_auto_generated = 0")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>مدیریت پست‌ها | پنل ادمین اوتاکو</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
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
        h2 { font-size: 1.3rem; margin-bottom: 15px; border-bottom: 1px solid var(--border); padding-bottom: 8px; }
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
            font-size: 0.9rem;
        }
        .tab:hover { background: var(--primary); border-color: var(--primary); }
        .card {
            background: var(--surface);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
        }
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
        .message { background: rgba(76, 175, 80, 0.2); border: 1px solid #4caf50; padding: 10px; border-radius: 10px; margin-bottom: 15px; }
        .error { background: rgba(255, 77, 109, 0.2); border: 1px solid var(--primary); padding: 10px; border-radius: 10px; margin-bottom: 15px; }
        input, select, textarea {
            background: rgba(255,255,255,0.1); border: 1px solid var(--border);
            padding: 8px 12px; border-radius: 10px; color: #fff; margin: 4px 0;
            font-family: inherit; width: auto;
        }
        input[type="text"], input[type="number"], input[type="date"], input[type="file"] { width: 100%; max-width: 300px; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; margin-bottom: 4px; font-size: 0.9rem; color: var(--secondary-text); }
        .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 15px; text-align: center; }
        .stat-card h3 { font-size: 2rem; margin: 5px 0; }
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 15px; }
        .pagination a, .pagination span {
            padding: 4px 12px; background: rgba(255,255,255,0.1); border-radius: 8px; color: #fff; text-decoration: none;
        }
        .pagination a:hover { background: var(--primary); }
        .pagination .current { background: var(--primary); }
        .search-box { display: flex; gap: 10px; align-items: center; margin-bottom: 15px; flex-wrap: wrap; }
        .search-box input { flex: 1; min-width: 200px; }
    </style>
</head>
<body>
<div class="container">
    <h1>📋 مدیریت پست‌ها</h1>

    <?php if ($message): ?><div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="tabs">
        <a href="admin.php" class="tab">🛡️ پنل اصلی</a>
        <a href="admin2.php" class="tab" style="background:var(--primary);">📋 پست‌ها</a>
    </div>

    <div class="stats">
        <div class="stat-card"><h3><?= $total_auto_posts ?></h3>پست خودکار</div>
        <div class="stat-card"><h3><?= $total_user_posts ?></h3>پست کاربران</div>
    </div>

    <!-- ──────── بارگذاری JSON ──────── -->
    <div class="card">
        <h2>📥 بارگذاری پست‌های خودکار از JSON</h2>
        <p>بارگذاری فایل غیرفعال است؛ برای اشتراک رسانه از لینک دامنه مجاز استفاده کنید.</p>
    </div>

    <!-- ──────── حذف گروهی ──────── -->
    <div class="card">
        <h2>🗑️ حذف گروهی پست‌ها</h2>
        <form method="post" style="margin-bottom:15px;" onsubmit="return confirm('همه پست‌های خودکار حذف شوند؟')">
            <input type="hidden" name="action" value="delete_auto_posts">
            <button class="btn btn-danger">🗑️ حذف تمام پست‌های خودکار</button>
        </form>
        <form method="post" onsubmit="return confirm('پست‌های قدیمی حذف شوند؟')">
            <input type="hidden" name="action" value="delete_old_posts">
            <label>حذف پست‌های قدیمی‌تر از (روز):</label>
            <input type="number" name="days" placeholder="مثلاً 30" min="1" required>
            <button class="btn btn-danger">🗑️ حذف</button>
        </form>
    </div>

    <!-- ──────── جستجو و حذف کاربر ──────── -->
    <div class="card">
        <h2>🔍 جستجوی کاربر و مدیریت پست‌های او</h2>
        <form method="get" class="search-box">
            <input type="hidden" name="tab" value="user_posts">
            <input type="text" name="user_search" placeholder="نام کاربری، نام نمایشی..." value="<?= htmlspecialchars($userSearch) ?>">
            <button class="btn" type="submit">جستجو</button>
        </form>

        <?php if (!empty($searchResults)): ?>
            <table style="margin-top:10px;">
                <tr><th>ID</th><th>نام کاربری</th><th>نام</th><th>عملیات</th></tr>
                <?php foreach ($searchResults as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><?= htmlspecialchars($u['display_name'] ?: $u['first_name'].' '.$u['last_name']) ?></td>
                    <td>
                        <a href="?searched_user_id=<?= $u['id'] ?>" class="btn">مشاهده پست‌ها</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php elseif (!empty($userSearch)): ?>
            <p style="color:var(--secondary-text);">کاربری یافت نشد.</p>
        <?php endif; ?>

        <?php if ($userInfo): ?>
            <h3 style="margin-top:20px;">پست‌های <?= htmlspecialchars($userInfo['display_name'] ?: $userInfo['username']) ?> (<?= $totalUserPosts ?>)</h3>
            <form method="post" style="margin:10px 0;" onsubmit="return confirm('تمام پست‌های این کاربر حذف شوند؟')">
                <input type="hidden" name="action" value="delete_user_posts">
                <input type="hidden" name="user_id" value="<?= $userInfo['id'] ?>">
                <button class="btn btn-danger">🗑️ حذف همه پست‌های این کاربر</button>
            </form>

            <?php if (!empty($userPosts)): ?>
                <table>
                    <tr><th>ID</th><th>عنوان</th><th>تاریخ</th><th>بازدید</th><th>عملیات</th></tr>
                    <?php foreach ($userPosts as $post): ?>
                    <tr>
                        <td><?= $post['id'] ?></td>
                        <td><?= htmlspecialchars(mb_substr($post['title'], 0, 40)) ?></td>
                        <td><?= $post['created_at'] ?></td>
                        <td><?= $post['view_count'] ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="delete_post">
                                <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                                <button class="btn btn-danger" onclick="return confirm('حذف شود؟')">🗑️</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php
                $totalPages = ceil($totalUserPosts / 15);
                if ($totalPages > 1):
                ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?searched_user_id=<?= $userInfo['id'] ?>&up=<?= $i ?>" class="<?= ($_GET['up'] ?? 1) == $i ? 'current' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <p style="color:var(--secondary-text);">این کاربر هنوز پستی ندارد.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ──────── جستجوی پست با شناسه ──────── -->
    <div class="card">
        <h2>🔢 حذف پست با شناسه</h2>
        <form method="post">
            <input type="hidden" name="action" value="delete_post">
            <input type="number" name="post_id" placeholder="شناسه پست" required>
            <button class="btn btn-danger">🗑️ حذف پست</button>
        </form>
    </div>
</div>
</body>
</html>