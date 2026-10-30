<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$current_user_id = $_SESSION['user_id'];

// ---------- توابع کمکی ----------
function parseMediaUrl($media_url) {
    $result = ['img' => '', 'qualities' => []];
    if (empty($media_url)) return $result;
    $parts = explode(',', $media_url);
    foreach ($parts as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) == 2) {
            $key = trim($kv[0]);
            $value = trim($kv[1]);
            if ($key === 'img') {
                $result['img'] = $value;
            } else {
                $result['qualities'][$key] = $value;
            }
        }
    }
    return $result;
}

function getPostStats($pdo, $post_id) {
    $likes = $pdo->prepare("SELECT COUNT(*) FROM post_likes WHERE post_id = ?");
    $likes->execute([$post_id]);
    $comments = $pdo->prepare("SELECT COUNT(*) FROM post_comments WHERE post_id = ?");
    $comments->execute([$post_id]);
    return [
        'likes' => (int)$likes->fetchColumn(),
        'comments' => (int)$comments->fetchColumn()
    ];
}

// ---------- درخواست‌های AJAX ----------
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_GET['action'] === 'get_post') {
        $post_id = (int)($_GET['post_id'] ?? 0);
        if (!$post_id) { echo json_encode(null); exit; }
        $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
        $stmt->execute([$post_id]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($post) {
            $post['stats'] = getPostStats($pdo, $post['id']);
            $post['media'] = parseMediaUrl($post['media_url']);
            echo json_encode($post);
        } else {
            echo json_encode(null);
        }
        exit;
    }

    if ($_GET['action'] === 'load_posts') {
        $page = max(1, (int)($_GET['p'] ?? 1));
        $perPage = 12;
        $search = $_GET['search'] ?? '';
        $offset = ($page - 1) * $perPage;

        $userWhere = "WHERE is_auto_generated = 0";
        $autoWhere = "WHERE is_auto_generated = 1";
        $params = [];
        if (!empty($search)) {
            $like = "%$search%";
            $userWhere .= " AND title LIKE ?";
            $autoWhere .= " AND title LIKE ?";
            $params[] = $like;
            $params[] = $like;
        }

        $userPosts = [];
        $autoPosts = [];

        if ($page === 1) {
            $stmt = $pdo->prepare("SELECT * FROM posts $userWhere ORDER BY RAND() LIMIT 30");
            $stmt->execute($params ? [$params[0]] : []);
            $userPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $autoStmt = $pdo->prepare("SELECT * FROM posts $autoWhere ORDER BY RAND() LIMIT $perPage OFFSET $offset");
        $autoStmt->execute($params ? [$params[1] ?? $params[0]] : []);
        $autoPosts = $autoStmt->fetchAll(PDO::FETCH_ASSOC);

        $allPosts = array_merge($userPosts, $autoPosts);
        foreach ($allPosts as &$post) {
            $post['stats'] = getPostStats($pdo, $post['id']);
            $post['media'] = parseMediaUrl($post['media_url']);
        }
        unset($post);

        echo json_encode(['posts' => $allPosts, 'hasMore' => count($autoPosts) === $perPage]);
        exit;
    }

    if ($_GET['action'] === 'toggle_like' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_id = (int)($_POST['post_id'] ?? 0);
        if ($post_id) {
            $check = $pdo->prepare("SELECT 1 FROM post_likes WHERE post_id = ? AND user_id = ?");
            $check->execute([$post_id, $current_user_id]);
            if ($check->fetch()) {
                $pdo->prepare("DELETE FROM post_likes WHERE post_id = ? AND user_id = ?")->execute([$post_id, $current_user_id]);
                $liked = false;
            } else {
                $pdo->prepare("INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)")->execute([$post_id, $current_user_id]);
                $liked = true;
            }
            $stats = getPostStats($pdo, $post_id);
            echo json_encode(['success' => true, 'liked' => $liked, 'likes' => $stats['likes']]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    if ($_GET['action'] === 'add_comment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_id = (int)($_POST['post_id'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        if ($post_id && $comment) {
            $stmt = $pdo->prepare("INSERT INTO post_comments (post_id, user_id, comment) VALUES (?, ?, ?)");
            $stmt->execute([$post_id, $current_user_id, $comment]);
            $stats = getPostStats($pdo, $post_id);
            echo json_encode(['success' => true, 'comments' => $stats['comments']]);
        } else {
            echo json_encode(['success' => false, 'error' => 'پارامترها ناقص']);
        }
        exit;
    }

    if ($_GET['action'] === 'related_post') {
        $post_id = (int)($_GET['post_id'] ?? 0);
        if (!$post_id) { echo json_encode(null); exit; }

        $stmt = $pdo->prepare("SELECT title FROM posts WHERE id = ?");
        $stmt->execute([$post_id]);
        $currentTitle = $stmt->fetchColumn();
        if (!$currentTitle) { echo json_encode(null); exit; }

        $words = array_filter(explode(' ', $currentTitle), function($w) { return mb_strlen($w) > 2; });
        if (empty($words)) {
            $stmt = $pdo->prepare("SELECT * FROM posts WHERE id != ? ORDER BY RAND() LIMIT 1");
            $stmt->execute([$post_id]);
        } else {
            $likeClauses = [];
            $params = [];
            foreach ($words as $word) {
                $likeClauses[] = "title LIKE ?";
                $params[] = '%' . $word . '%';
            }
            $where = "id != ? AND (" . implode(' OR ', $likeClauses) . ")";
            $params = array_merge([$post_id], $params);
            $stmt = $pdo->prepare("SELECT * FROM posts WHERE $where ORDER BY view_count DESC LIMIT 1");
            $stmt->execute($params);
            if (!$stmt->rowCount()) {
                $stmt = $pdo->prepare("SELECT * FROM posts WHERE id != ? ORDER BY RAND() LIMIT 1");
                $stmt->execute([$post_id]);
            }
        }

        $related = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($related) {
            $related['stats'] = getPostStats($pdo, $related['id']);
            $related['media'] = parseMediaUrl($related['media_url']);
            echo json_encode($related);
        } else {
            echo json_encode(null);
        }
        exit;
    }

    if ($_GET['action'] === 'get_comments') {
        $post_id = (int)($_GET['post_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT pc.id, pc.comment, pc.created_at, u.username, u.display_name, u.avatar_url
                               FROM post_comments pc
                               JOIN users u ON pc.user_id = u.id
                               WHERE pc.post_id = ?
                               ORDER BY pc.created_at DESC LIMIT 50");
        $stmt->execute([$post_id]);
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($comments);
        exit;
    }

    if ($_GET['action'] === 'view') {
        $post_id = (int)($_GET['post_id'] ?? 0);
        $pdo->prepare("UPDATE posts SET view_count = view_count + 1 WHERE id = ?")->execute([$post_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    exit;
}

// ---------- خروجی HTML (بار اول) ----------
$initialPosts = [];
$userStmt = $pdo->query("SELECT * FROM posts WHERE is_auto_generated = 0 ORDER BY RAND() LIMIT 30");
$userPosts = $userStmt->fetchAll(PDO::FETCH_ASSOC);
$autoStmt = $pdo->query("SELECT * FROM posts WHERE is_auto_generated = 1 ORDER BY RAND() LIMIT 12");
$autoPosts = $autoStmt->fetchAll(PDO::FETCH_ASSOC);
$initialPosts = array_merge($userPosts, $autoPosts);

foreach ($initialPosts as &$post) {
    $post['stats'] = getPostStats($pdo, $post['id']);
    $post['media'] = parseMediaUrl($post['media_url']);
}
unset($post);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>اکسپلور | اوتاکو</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <style>
        :root {
            --primary: #ff4d6d;
            --primary-glow: rgba(255,77,109,0.6);
            --surface: rgba(15,15,35,0.7);
            --bg: #050510;
            --border: rgba(255,77,109,0.25);
            --text: #fff;
            --secondary: #c0c0d0;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: var(--bg);
            background-image: 
                radial-gradient(circle at 20% 30%, #1a1a3e 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, #2a0a2a 0%, transparent 50%);
            color: var(--text);
            padding: 20px 16px 100px;
            min-height: 100vh;
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

        .container { max-width: 900px; margin: 0 auto; position: relative; z-index: 1; }
        h1 { text-align: center; color: var(--primary); margin-bottom: 25px; font-size: 2.5rem; text-shadow: 0 0 20px var(--primary-glow); letter-spacing: 2px; }
        .search-bar {
            display: flex; gap: 10px; margin-bottom: 25px;
        }
        .search-bar input {
            flex: 1; padding: 14px 20px; border-radius: 30px;
            background: rgba(255,255,255,0.06); backdrop-filter: blur(15px);
            border: 1px solid var(--border); color: #fff; font-size: 1rem;
            transition: 0.3s;
        }
        .search-bar input:focus { border-color: var(--primary); box-shadow: 0 0 15px var(--primary-glow); outline: none; }

        .posts-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }
        @media (max-width: 768px) {
            .posts-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            h1 { font-size: 2rem; }
        }

        .post-card {
            position: relative;
            border-radius: 18px; overflow: hidden;
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(5px);
            cursor: pointer;
            aspect-ratio: 1 / 1;
            border: 1px solid rgba(255,255,255,0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            animation: cardAppear 0.6s ease forwards;
            opacity: 0;
            transform: translateY(20px);
        }
        @keyframes cardAppear {
            to { opacity: 1; transform: translateY(0); }
        }
        .post-card:hover {
            transform: scale(1.03);
            box-shadow: 0 15px 30px rgba(255,77,109,0.4);
            border-color: var(--primary);
        }
        .post-card img {
            width: 100%; height: 100%; object-fit: cover; transition: 0.4s;
        }
        .post-card:hover img { filter: brightness(1.1); }
        .post-card .overlay {
            position: absolute; bottom: 0; left: 0; right: 0;
            background: linear-gradient(transparent, rgba(0,0,0,0.8));
            padding: 10px; color: #fff;
            display: flex; justify-content: space-between; align-items: flex-end;
        }
        .overlay .title { font-size: 0.85rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 70%; }
        .overlay .stats { font-size: 0.75rem; display: flex; gap: 6px; }

        .loader { text-align: center; padding: 20px; display: none; color: var(--secondary); }

        /* ── مودال ── */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.9); backdrop-filter: blur(10px);
            z-index: 200; display: flex; align-items: center; justify-content: center;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .modal {
            background: linear-gradient(145deg, rgba(25,25,50,0.95), rgba(15,15,35,0.95));
            border: 1px solid var(--border);
            border-radius: 24px;
            width: 95%; max-width: 650px; max-height: 90vh;
            overflow-y: auto; padding: 20px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.8), 0 0 0 1px rgba(255,77,109,0.3);
        }
        .modal-close {
            float: left; font-size: 1.8rem; cursor: pointer; color: var(--secondary); transition: 0.3s;
        }
        .modal-close:hover { color: var(--primary); }

        .media-container {
            position: relative; border-radius: 16px; overflow: hidden; margin-bottom: 15px;
            background: #000; min-height: 250px;
        }
        .media-container img {
            width: 100%; display: block; transition: opacity 0.5s;
        }
        .media-container img.hidden { opacity: 0; position: absolute; top: 0; }

        .play-btn-overlay {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            width: 80px; height: 80px; background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px); border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: 0.3s; border: 2px solid rgba(255,255,255,0.3);
            z-index: 10;
        }
        .play-btn-overlay:hover { background: var(--primary); border-color: #fff; transform: translate(-50%, -50%) scale(1.1); }
        .play-icon { font-size: 2.5rem; color: #fff; margin-right: 5px; }

        .video-container {
            display: none; 
            position: relative; z-index: 15;
            border-radius: 16px; overflow: hidden; background: #000;
        }
        .video-container iframe { width: 100%; height: 400px; border: none; display: block; }
        @media (max-width: 500px) { .video-container iframe { height: 250px; } }

        .post-title { font-size: 1.5rem; font-weight: 600; margin: 10px 0; }
        .post-subtitle { color: var(--secondary); margin-bottom: 10px; }

        .quality-btns { display: flex; gap: 10px; flex-wrap: wrap; margin: 15px 0; }
        .quality-btns button {
            background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15);
            color: #fff; padding: 8px 18px; border-radius: 25px; cursor: pointer;
            transition: 0.3s; backdrop-filter: blur(10px);
        }
        .quality-btns button.active { background: var(--primary); border-color: var(--primary); box-shadow: 0 0 15px var(--primary-glow); }

        .btn-primary {
            background: var(--primary); border: none; color: #fff; padding: 10px 24px;
            border-radius: 25px; cursor: pointer; font-weight: bold; transition: 0.3s;
        }
        .btn-primary:hover { box-shadow: 0 0 20px var(--primary-glow); }

        .comments-section { margin-top: 20px; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 15px; }
        .comment { padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }

        /* نوار پایین */
        .bottom-nav {
            position: fixed; bottom: 15px; left: 50%; transform: translateX(-50%);
            width: 90%; max-width: 480px;
            background: rgba(15, 15, 35, 0.8); backdrop-filter: blur(30px);
            display: flex; justify-content: space-around; align-items: center;
            padding: 8px 12px; border-radius: 35px;
            box-shadow: 0 20px 35px rgba(0,0,0,0.7), 0 0 0 1px rgba(255,77,109,0.4);
            z-index: 100; border: 1px solid rgba(255,255,255,0.15);
        }
        .bottom-nav .nav-item {
            display: flex; flex-direction: column; align-items: center; text-decoration: none;
            color: #999; font-size: 0.65rem; font-weight: 500; padding: 6px 10px;
            border-radius: 20px;
        }
        .bottom-nav .nav-item .icon { font-size: 1.5rem; margin-bottom: 3px; }
        .bottom-nav .nav-item.active .icon { color: var(--primary); filter: drop-shadow(0 0 10px var(--primary-glow)); }
        .bottom-nav .nav-item.active span { color: #fff; font-weight: bold; }
    </style>
</head>
<body>
<div class="particles">
    <div class="particle"></div><div class="particle"></div><div class="particle"></div>
    <div class="particle"></div><div class="particle"></div><div class="particle"></div>
</div>

<div class="container">
    <h1>🌟 اکسپلور</h1>
    <div class="search-bar">
        <input type="text" id="searchInput" placeholder="جستجوی پست‌ها...">
    </div>
    <div class="posts-grid" id="postsGrid">
        <?php foreach ($initialPosts as $index => $post): ?>
            <div class="post-card" style="animation-delay: <?= $index * 0.05 ?>s" onclick="openPost(<?= $post['id'] ?>)">
                <img src="<?= htmlspecialchars($post['media']['img']) ?>" alt="<?= htmlspecialchars($post['title']) ?>">
                <div class="overlay">
                    <span class="title"><?= htmlspecialchars($post['title']) ?></span>
                    <span class="stats">❤️<?= $post['stats']['likes'] ?> 💬<?= $post['stats']['comments'] ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="loader" id="loader">⏳ در حال بارگذاری پست‌های بیشتر...</div>
</div>

<!-- مودال -->
<div class="modal-overlay" id="postModal" style="display:none;" onclick="if(event.target===this) closeModal()">
    <div class="modal" id="modalContent"></div>
</div>

<!-- نوار پایین -->
<nav class="bottom-nav">
    <a href="home.php" class="nav-item"><span class="icon">🏠</span><span>خانه</span></a>
    <a href="index.php" class="nav-item"><span class="icon">💬</span><span>پیام‌رسان</span></a>
    <a href="account.php" class="nav-item"><span class="icon">👤</span><span>حساب</span></a>
    <a href="explore.php" class="nav-item active"><span class="icon">🔍</span><span>اکسپلور</span></a>
    <a href="otaku.php" class="nav-item"><span class="icon">🎬</span><span>اوتاکو</span></a>
</nav>

<script>
let currentPage = 2;
let isLoading = false;
let hasMore = true;
let currentPostId = null;
let currentQualities = [];
let currentImg = '';
let currentShareHash = '';

window.addEventListener('scroll', () => {
    if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 500) loadMore();
});

async function loadMore() {
    if (isLoading || !hasMore) return;
    isLoading = true;
    document.getElementById('loader').style.display = 'block';
    const search = document.getElementById('searchInput').value;
    const res = await fetch(`explore.php?action=load_posts&p=${currentPage}&search=${encodeURIComponent(search)}`);
    const data = await res.json();
    if (data.posts && data.posts.length > 0) {
        data.posts.forEach(post => renderPostCard(post));
        currentPage++;
        hasMore = data.hasMore;
    } else hasMore = false;
    document.getElementById('loader').style.display = 'none';
    isLoading = false;
}

function renderPostCard(post) {
    const grid = document.getElementById('postsGrid');
    const div = document.createElement('div');
    div.className = 'post-card';
    div.onclick = () => openPost(post.id);
    div.innerHTML = `
        <img src="${post.media.img}" alt="${post.title}">
        <div class="overlay">
            <span class="title">${post.title}</span>
            <span class="stats">❤️${post.stats.likes} 💬${post.stats.comments}</span>
        </div>
    `;
    grid.appendChild(div);
}

document.getElementById('searchInput').addEventListener('input', function() {
    currentPage = 1;
    hasMore = true;
    document.getElementById('postsGrid').innerHTML = '';
    loadMore();
});

async function openPost(postId) {
    currentPostId = postId;
    fetch(`explore.php?action=view&post_id=${postId}`);

    const modal = document.getElementById('postModal');
    modal.style.display = 'flex';
    document.getElementById('modalContent').innerHTML = '<p style="text-align:center; padding:40px;">⏳</p>';

    const res = await fetch(`explore.php?action=get_post&post_id=${postId}`);
    const post = await res.json();
    if (!post) {
        document.getElementById('modalContent').innerHTML = '<p>پست یافت نشد</p>';
        return;
    }

    currentQualities = Object.entries(post.media.qualities);
    currentImg = post.media.img;
    currentShareHash = post.share_hash;

    renderModal(post);
    loadComments(post.id);
    autoSelectQuality();
}

function renderModal(post) {
    const qualitiesHtml = currentQualities.map(([label, url]) =>
        `<button onclick="selectQuality('${label}')" data-label="${label}">${label}</button>`
    ).join('');

    document.getElementById('modalContent').innerHTML = `
        <span class="modal-close" onclick="closeModal()">✖</span>

        <div class="media-container" id="mediaContainer">
            <img src="${currentImg}" alt="${post.title}" id="postImage">
            <div class="play-btn-overlay" id="playBtn" onclick="startPlayback()">
                <span class="play-icon">▶</span>
            </div>
            <div class="video-container" id="videoContainer">
                <iframe id="videoFrame" allowfullscreen></iframe>
            </div>
        </div>

        <h2 class="post-title">${post.title}</h2>
        ${post.subtitle ? `<p class="post-subtitle">${post.subtitle}</p>` : ''}

        <div style="display:flex; gap: 15px; align-items: center; flex-wrap: wrap;">
            <span>❤️ <span id="likeCount">${post.stats.likes}</span></span>
            <span>💬 <span id="commentCount">${post.stats.comments}</span></span>
            <span>👁️ ${post.view_count}</span>
        </div>

        <div style="display: flex; gap: 10px; margin: 10px 0;">
            <button class="btn-primary" onclick="toggleLike(${post.id})" id="likeBtn">❤️ لایک</button>
            <button class="btn-primary" onclick="sharePost()" style="background: #3390ec;">🔗 لینک ویدیو</button>
        </div>

        <div class="quality-btns" id="qualityButtons">
            ${qualitiesHtml}
            <button onclick="downloadCurrent()">📥 دانلود</button>
        </div>

        <button class="btn-primary" onclick="loadNextRelated()" style="margin: 10px 0;">⏭️ پست بعدی</button>

        <div class="comments-section">
            <h3>💬 نظرات</h3>
            <div id="commentsList"></div>
            <textarea id="commentInput" rows="2" placeholder="نظر خود را بنویسید..." style="width:100%; margin:10px 0; background:rgba(255,255,255,0.05); border:1px solid var(--border); border-radius:12px; padding:10px; color:#fff;"></textarea>
            <button class="btn-primary" onclick="addComment(${post.id})">ارسال نظر</button>
        </div>
    `;
}

function sharePost() {
    if (!currentShareHash) {
        alert('لینک قابل اشتراک‌گذاری موجود نیست.');
        return;
    }
    // ساخت لینک اشتراک با توجه به پوشه anime
    const basePath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
    const url = `${window.location.origin}${basePath}/post.php?hash=${currentShareHash}`;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(() => {
            alert('لینک ویدیو در کلیپ‌بورد کپی شد:\n' + url);
        });
    } else {
        prompt('لینک ویدیو (کپی کنید):', url);
    }
}

function startPlayback() {
    if (currentQualities.length === 0) return;
    let activeQuality = document.querySelector('.quality-btns button.active');
    if (!activeQuality) {
        selectQuality(currentQualities[0][0]);
        activeQuality = document.querySelector(`.quality-btns button[data-label="${currentQualities[0][0]}"]`);
    }
    const label = activeQuality.getAttribute('data-label');
    const quality = currentQualities.find(([l]) => l === label);
    if (quality) {
        const iframe = document.getElementById('videoFrame');
        iframe.src = quality[1];
        document.getElementById('videoContainer').style.display = 'block';
        document.getElementById('playBtn').style.display = 'none';
        document.getElementById('postImage').classList.add('hidden');
    }
}

function selectQuality(label) {
    document.querySelectorAll('.quality-btns button').forEach(b => b.classList.remove('active'));
    const btn = document.querySelector(`.quality-btns button[data-label="${label}"]`);
    if (btn) btn.classList.add('active');
    const iframe = document.getElementById('videoFrame');
    if (iframe && iframe.src) {
        const quality = currentQualities.find(([l]) => l === label);
        if (quality) iframe.src = quality[1];
    }
}

function downloadCurrent() {
    const active = document.querySelector('.quality-btns button.active');
    if (active) {
        const label = active.getAttribute('data-label');
        const quality = currentQualities.find(([l]) => l === label);
        if (quality) window.open(quality[1], '_blank');
    }
}

async function autoSelectQuality() {
    if (currentQualities.length === 0) return;
    const testImg = currentImg;
    const start = Date.now();
    const img = new Image();
    img.src = testImg + '?cache=' + Math.random();
    try {
        await new Promise((resolve, reject) => {
            img.onload = resolve;
            img.onerror = reject;
            setTimeout(() => reject('timeout'), 3000);
        });
    } catch (e) {
        selectQuality(currentQualities[0][0]);
        return;
    }
    const duration = (Date.now() - start) / 1000;
    const speedKbps = (50 * 1024 * 8) / duration / 1024;
    let selected = currentQualities[0][0];
    if (speedKbps > 2000 && currentQualities.length > 2) selected = currentQualities[currentQualities.length-1][0];
    else if (speedKbps > 800 && currentQualities.length >= 2) selected = currentQualities[Math.floor(currentQualities.length/2)][0];
    selectQuality(selected);
}

async function loadComments(postId) {
    const res = await fetch(`explore.php?action=get_comments&post_id=${postId}`);
    const comments = await res.json();
    const list = document.getElementById('commentsList');
    list.innerHTML = comments.map(c =>
        `<div class="comment"><strong>${c.display_name || c.username}</strong>: ${c.comment}</div>`
    ).join('');
}

async function toggleLike(postId) {
    const res = await fetch(`explore.php?action=toggle_like`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `post_id=${postId}`
    });
    const result = await res.json();
    if (result.success) {
        document.getElementById('likeCount').textContent = result.likes;
        document.getElementById('likeBtn').textContent = result.liked ? '❤️ لایک شده' : '❤️ لایک';
    }
}

async function addComment(postId) {
    const input = document.getElementById('commentInput');
    const comment = input.value.trim();
    if (!comment) return;
    const res = await fetch(`explore.php?action=add_comment`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `post_id=${postId}&comment=${encodeURIComponent(comment)}`
    });
    const result = await res.json();
    if (result.success) {
        input.value = '';
        loadComments(postId);
        document.getElementById('commentCount').textContent = result.comments;
    }
}

async function loadNextRelated() {
    const res = await fetch(`explore.php?action=related_post&post_id=${currentPostId}`);
    const post = await res.json();
    if (post) {
        closeModal();
        openPost(post.id);
    } else {
        alert('پست مرتبطی یافت نشد.');
    }
}

function closeModal() {
    document.getElementById('postModal').style.display = 'none';
    const iframe = document.getElementById('videoFrame');
    if (iframe) iframe.src = '';
}
</script>
</body>
</html>