<?php
require_once 'config.php';
session_start();

// ---------- فقط کاربران لاگین شده ----------
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$hash = $_GET['hash'] ?? '';
if (empty($hash)) {
    die('پست یافت نشد.');
}

// دریافت پست با hash
$stmt = $pdo->prepare("SELECT p.*, u.username, u.display_name FROM posts p JOIN users u ON p.user_id = u.id WHERE p.share_hash = ?");
$stmt->execute([$hash]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    die('پست یافت نشد یا لینک نامعتبر است.');
}

// افزایش بازدید
$pdo->prepare("UPDATE posts SET view_count = view_count + 1 WHERE id = ?")->execute([$post['id']]);

// پارس media_url
$media = ['img' => '', 'qualities' => []];
$parts = explode(',', $post['media_url']);
foreach ($parts as $part) {
    $kv = explode('=', $part, 2);
    if (count($kv) == 2) {
        $key = trim($kv[0]);
        $value = trim($kv[1]);
        if ($key === 'img') {
            $media['img'] = $value;
        } else {
            $media['qualities'][$key] = $value;
        }
    }
}

// تعداد لایک و کامنت
$likes = $pdo->prepare("SELECT COUNT(*) FROM post_likes WHERE post_id = ?");
$likes->execute([$post['id']]);
$likeCount = $likes->fetchColumn();

$commentsStmt = $pdo->prepare("SELECT pc.comment, pc.created_at, u.username, u.display_name, u.avatar_url
                               FROM post_comments pc
                               JOIN users u ON pc.user_id = u.id
                               WHERE pc.post_id = ?
                               ORDER BY pc.created_at DESC");
$commentsStmt->execute([$post['id']]);
$comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= htmlspecialchars($post['title']) ?> | اوتاکو</title>
    <style>
        :root {
            --primary: #ff4d6d;
            --bg: #000;
            --text: #fff;
            --secondary: #ccc;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .container {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px 16px 100px;
        }
        .header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 0;
            margin-bottom: 15px;
        }
        .header .back {
            color: var(--primary);
            text-decoration: none;
            font-size: 1.2rem;
        }
        .media-container {
            position: relative;
            width: 100%;
            border-radius: 16px;
            overflow: hidden;
            background: #000;
        }
        .media-container img {
            width: 100%;
            display: block;
            transition: opacity 0.4s;
        }
        .play-btn-overlay {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
            width: 70px; height: 70px; background: rgba(0,0,0,0.5); backdrop-filter: blur(10px);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            cursor: pointer; z-index: 5; border: 2px solid rgba(255,255,255,0.5);
            transition: 0.3s;
        }
        .play-btn-overlay:hover { background: var(--primary); border-color: #fff; transform: translate(-50%,-50%) scale(1.1); }
        .play-icon { font-size: 2rem; color: #fff; margin-right: 4px; }

        /* overlay تمام‌صفحه */
        .fullscreen-overlay {
            display: none;
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.95);
            z-index: 999;
            justify-content: center; align-items: center;
        }
        .fullscreen-overlay.active { display: flex; }
        .fullscreen-overlay iframe {
            width: 100%; height: 100%; border: none;
        }
        .close-btn {
            position: absolute; top: 20px; right: 20px;
            width: 44px; height: 44px; background: rgba(0,0,0,0.7);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 1.8rem; color: #fff; cursor: pointer; z-index: 1000;
            transition: 0.3s;
        }
        .close-btn:hover { background: var(--primary); }

        .post-info { margin: 15px 0; }
        .post-info h1 { font-size: 1.4rem; margin-bottom: 5px; }
        .post-info .subtitle { color: var(--secondary); margin-bottom: 10px; }
        .meta { display: flex; gap: 15px; color: var(--secondary); font-size: 0.9rem; margin-bottom: 10px; }
        .qualities { display: flex; gap: 10px; flex-wrap: wrap; margin: 15px 0; }
        .qualities button {
            background: rgba(255,255,255,0.1); border: 1px solid var(--primary);
            padding: 6px 14px; border-radius: 20px; color: #fff;
            cursor: pointer; transition: 0.3s;
        }
        .qualities button.active { background: var(--primary); border-color: var(--primary); }
        .comments-section { margin-top: 20px; }
        .comments-section h2 { font-size: 1.2rem; margin-bottom: 10px; }
        .comment { padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .comment strong { color: var(--primary); }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <a href="javascript:history.back()" class="back">← بازگشت</a>
        <span style="color:var(--secondary);">@<?= htmlspecialchars($post['username']) ?></span>
    </div>

    <div class="media-container" id="mediaContainer">
        <?php if (!empty($media['img'])): ?>
            <img src="<?= htmlspecialchars($media['img']) ?>" alt="<?= htmlspecialchars($post['title']) ?>" id="postImage">
            <div class="play-btn-overlay" id="playBtn" onclick="startPlayback()">
                <span class="play-icon">▶</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="post-info">
        <h1><?= htmlspecialchars($post['title']) ?></h1>
        <?php if (!empty($post['subtitle'])): ?>
            <p class="subtitle"><?= htmlspecialchars($post['subtitle']) ?></p>
        <?php endif; ?>
        <div class="meta">
            <span>❤️ <?= $likeCount ?></span>
            <span>💬 <?= count($comments) ?></span>
            <span>👁️ <?= $post['view_count'] ?></span>
        </div>
    </div>

    <?php if (!empty($media['qualities'])): ?>
        <div class="qualities" id="qualityButtons">
            <?php foreach ($media['qualities'] as $label => $url): ?>
                <button onclick="selectQuality('<?= addslashes($label) ?>')" data-label="<?= htmlspecialchars($label) ?>"><?= htmlspecialchars($label) ?></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="comments-section">
        <h2>نظرات (<?= count($comments) ?>)</h2>
        <?php if ($comments): ?>
            <?php foreach ($comments as $c): ?>
                <div class="comment">
                    <strong><?= htmlspecialchars($c['display_name'] ?: $c['username']) ?></strong>:
                    <?= htmlspecialchars($c['comment']) ?>
                    <div style="font-size:0.75rem; color:#888;"><?= $c['created_at'] ?></div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color:var(--secondary);">هنوز نظری ثبت نشده است.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Overlay تمام‌صفحه -->
<div class="fullscreen-overlay" id="fullscreenOverlay">
    <span class="close-btn" onclick="closeFullscreen()">✖</span>
    <iframe id="fullscreenFrame" allowfullscreen></iframe>
</div>

<script>
const qualities = <?= json_encode($media['qualities']) ?>;

function startPlayback() {
    const firstLabel = Object.keys(qualities)[0];
    if (!firstLabel) return;
    selectQuality(firstLabel);
    // باز کردن overlay تمام‌صفحه
    document.getElementById('fullscreenOverlay').classList.add('active');
    // انتقال iframe به overlay
    document.getElementById('fullscreenFrame').src = qualities[firstLabel];
}

function selectQuality(label) {
    const url = qualities[label];
    if (!url) return;
    // اگر overlay باز است، iframe را همانجا آپدیت کن
    if (document.getElementById('fullscreenOverlay').classList.contains('active')) {
        document.getElementById('fullscreenFrame').src = url;
    }
    // بروزرسانی دکمه‌های کیفیت
    document.querySelectorAll('.qualities button').forEach(b => b.classList.remove('active'));
    const activeBtn = document.querySelector(`.qualities button[data-label="${label}"]`);
    if (activeBtn) activeBtn.classList.add('active');
}

function closeFullscreen() {
    document.getElementById('fullscreenOverlay').classList.remove('active');
    // توقف ویدیو با خالی کردن src
    document.getElementById('fullscreenFrame').src = '';
}
</script>
</body>
</html>