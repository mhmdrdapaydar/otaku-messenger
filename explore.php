<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$current_user_id = $_SESSION['user_id'];

// ---------- توابع کمکی (بدون تغییر) ----------
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

// ---------- درخواست‌های AJAX (بدون تغییر) ----------
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_GET['action'] === 'get_post') {
        $post_id = (int)($_GET['post_id'] ?? 0);
        if (!$post_id) { echo json_encode(null); exit; }
        $stmt = $pdo->prepare("SELECT p.*, u.username, u.display_name FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
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

        $userWhere = "WHERE p.is_auto_generated = 0";
        $autoWhere = "WHERE p.is_auto_generated = 1";
        $params = [];
        if (!empty($search)) {
            $like = "%$search%";
            $userWhere .= " AND p.title LIKE ?";
            $autoWhere .= " AND p.title LIKE ?";
            $params[] = $like;
            $params[] = $like;
        }

        $userPosts = [];
        $autoPosts = [];

        if ($page === 1) {
            $stmt = $pdo->prepare("SELECT p.*, u.username, u.display_name FROM posts p JOIN users u ON p.user_id = u.id $userWhere ORDER BY RAND() LIMIT 30");
            $stmt->execute($params ? [$params[0]] : []);
            $userPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $autoStmt = $pdo->prepare("SELECT p.*, u.username, u.display_name FROM posts p JOIN users u ON p.user_id = u.id $autoWhere ORDER BY RAND() LIMIT $perPage OFFSET $offset");
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
            $stmt = $pdo->prepare("SELECT p.*, u.username, u.display_name FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id != ? ORDER BY RAND() LIMIT 1");
            $stmt->execute([$post_id]);
        } else {
            $likeClauses = [];
            $params = [];
            foreach ($words as $word) {
                $likeClauses[] = "p.title LIKE ?";
                $params[] = '%' . $word . '%';
            }
            $where = "p.id != ? AND (" . implode(' OR ', $likeClauses) . ")";
            $params = array_merge([$post_id], $params);
            $stmt = $pdo->prepare("SELECT p.*, u.username, u.display_name FROM posts p JOIN users u ON p.user_id = u.id WHERE $where ORDER BY p.view_count DESC LIMIT 1");
            $stmt->execute($params);
            if (!$stmt->rowCount()) {
                $stmt = $pdo->prepare("SELECT p.*, u.username, u.display_name FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id != ? ORDER BY RAND() LIMIT 1");
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

// ---------- بارگذاری اولیه ----------
$initialPosts = [];
$userStmt = $pdo->query("SELECT p.*, u.username, u.display_name FROM posts p JOIN users u ON p.user_id = u.id WHERE p.is_auto_generated = 0 ORDER BY RAND() LIMIT 30");
$userPosts = $userStmt->fetchAll(PDO::FETCH_ASSOC);
$autoStmt = $pdo->query("SELECT p.*, u.username, u.display_name FROM posts p JOIN users u ON p.user_id = u.id WHERE p.is_auto_generated = 1 ORDER BY RAND() LIMIT 12");
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>اکسپلور | اوتاکو</title>
    <style>
        :root {
            --primary: #ff4d6d;
            --primary-glow: rgba(255,77,109,0.6);
            --bg: #000;
            --text: #fff;
            --secondary: #ccc;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        html, body {
            height: 100%;
            background: #000;
            font-family: 'Segoe UI', Tahoma, sans-serif;
            overflow: hidden;
            touch-action: pan-y;
            color: var(--text);
        }
        body.nav-hidden .bottom-nav {
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s;
        }
        /* ذرات */
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

        .slider-wrapper {
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 1;
        }
        .slider-container {
            display: flex;
            flex-direction: column;
            height: 100%;
            transition: transform 0.3s cubic-bezier(0.25,0.46,0.45,0.94);
            will-change: transform;
        }
        .slide {
            flex: 0 0 100%;
            height: 100%;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
            overflow: hidden;
        }
        .media-container {
            width: 100%;
            height: 100%;
            position: relative;
        }
        .media-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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
        .video-container {
            display: none; position: absolute; top:0; left:0; width:100%; height:100%; background:#000;
        }
        .video-container iframe { width:100%; height:100%; border:none; }
        .slide-info {
            position: absolute; bottom: 100px; right: 16px; left: 16px;
            color: #fff; text-shadow: 0 1px 8px rgba(0,0,0,0.8); z-index:10;
            pointer-events: none;
        }
        .slide-info .username { font-weight: bold; font-size: 15px; }
        .slide-info .caption { font-size: 14px; }
        .action-buttons {
            position: absolute; left: 12px; bottom: 160px;
            display: flex; flex-direction: column; align-items: center; gap: 20px; z-index: 10;
        }
        .action-btn {
            background: none; border: none; color: #fff; font-size: 26px;
            text-shadow: 0 1px 6px black; cursor: pointer;
            display: flex; flex-direction: column; align-items: center; gap: 2px;
        }
        .action-btn span { font-size: 12px; }
        .quality-btns {
            position: absolute; bottom: 80px; left: 50%; transform: translateX(-50%);
            display: flex; gap: 8px; z-index: 10; flex-wrap: wrap; justify-content: center;
        }
        .quality-btns button {
            background: rgba(0,0,0,0.6); border: 1px solid rgba(255,255,255,0.3);
            color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem;
            backdrop-filter: blur(5px); cursor: pointer;
        }
        .quality-btns button.active { background: var(--primary); border-color: var(--primary); }
        .custom-pagination {
            position: absolute; bottom: 16px; left: 50%; transform: translateX(-50%);
            display: flex; gap: 6px; z-index: 20;
        }
        .custom-pagination .dot {
            width: 6px; height: 6px; border-radius: 3px; background: rgba(255,255,255,0.4);
            transition: all 0.3s; cursor: pointer;
        }
        .custom-pagination .dot.active { width: 18px; background: #fff; }
        .app-header {
            position: absolute; top: 0; left: 0; right: 0; padding: 16px;
            display: flex; justify-content: space-between; align-items: center; z-index: 30;
            pointer-events: auto;
        }
        .app-header .logo {
            font-size: 22px; font-weight: 900; color: #fff; text-shadow: 0 1px 8px black;
        }
        .header-icons { display: flex; gap: 10px; align-items: center; }
        .search-icon, .showroom-icon {
            font-size: 24px; color: #fff; text-shadow: 0 1px 6px black; cursor: pointer;
        }
        .search-bar {
            position: absolute; top: 60px; left: 16px; right: 16px; z-index: 40; display: none;
        }
        .search-bar.active { display: block; }
        .search-bar input {
            width: 100%; padding: 12px 20px; border-radius: 30px;
            background: rgba(0,0,0,0.7); backdrop-filter: blur(15px);
            border: 1px solid rgba(255,255,255,0.3); color: #fff; font-size: 1rem;
        }
        .comments-modal {
            position: fixed; bottom: 0; left: 0; right: 0; background: rgba(20,20,30,0.95);
            backdrop-filter: blur(20px); border-radius: 20px 20px 0 0; padding: 20px;
            z-index: 200; max-height: 50%; overflow-y: auto; display: none;
        }
        .comments-modal .comment { padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.1); }

        .nav-arrows {
            position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
            display: flex; flex-direction: column; gap: 15px; z-index: 25;
        }
        .nav-arrow {
            width: 44px; height: 44px; border-radius: 50%;
            background: rgba(0,0,0,0.4); backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.25);
            color: #fff; font-size: 1.3rem; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: 0.3s;
        }
        .nav-arrow:hover { background: var(--primary); border-color: var(--primary); }
        .nav-arrow:active { transform: scale(0.9); }

        .bottom-nav {
            position: fixed; bottom: 15px; left: 50%; transform: translateX(-50%);
            width: 90%; max-width: 480px;
            background: rgba(15, 15, 35, 0.8); backdrop-filter: blur(30px);
            display: flex; justify-content: space-around; align-items: center;
            padding: 6px 10px; border-radius: 35px;
            box-shadow: 0 20px 35px rgba(0,0,0,0.7), 0 0 0 1px rgba(255,77,109,0.4);
            z-index: 100; border: 1px solid rgba(255,255,255,0.15);
            opacity: 1;
            transition: opacity 0.3s;
        }
        .bottom-nav .nav-item {
            display: flex; flex-direction: column; align-items: center; text-decoration: none;
            color: #999; font-size: 0.65rem; font-weight: 500; padding: 6px 8px;
            border-radius: 20px;
        }
        .bottom-nav .nav-item .icon { font-size: 1.5rem; margin-bottom: 2px; }
        .bottom-nav .nav-item.active .icon { color: #ff4d6d; filter: drop-shadow(0 0 10px #ff4d6d); }
        .bottom-nav .nav-item.active span { color: #fff; font-weight: bold; }
        .bottom-nav .nav-item:not(.active):hover .icon { color: #ff8fa3; }
    </style>
</head>
<body>
<div class="particles">
    <div class="particle"></div><div class="particle"></div><div class="particle"></div>
    <div class="particle"></div><div class="particle"></div><div class="particle"></div>
</div>

<div class="app-header">
    <div class="logo">🌟 اکسپلور</div>
    <div class="header-icons">
        <span class="showroom-icon" onclick="window.location.href='explore2.php'" title="ویترین">📺</span>
        <span class="search-icon" onclick="toggleSearch()">🔍</span>
    </div>
</div>
<div class="search-bar" id="searchBar">
    <input type="text" id="searchInput" placeholder="جستجوی پست‌ها..." oninput="onSearchInput()">
</div>

<div class="slider-wrapper" id="sliderWrapper">
    <div class="slider-container" id="sliderContainer"></div>
    <div class="custom-pagination" id="customDots"></div>

    <div class="nav-arrows">
        <button class="nav-arrow" onclick="event.stopPropagation(); goToSlide(currentIndex - 1)" title="اسلاید قبلی">🔼</button>
        <button class="nav-arrow" onclick="event.stopPropagation(); goToSlide(currentIndex + 1)" title="اسلاید بعدی">🔽</button>
    </div>
</div>

<!-- مودال نظرات -->
<div class="comments-modal" id="commentsModal">
    <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
        <h3>نظرات</h3>
        <span onclick="closeComments()" style="cursor:pointer;">✖</span>
    </div>
    <div id="commentsList"></div>
    <textarea id="commentInput" rows="2" placeholder="نظر شما..." style="width:100%; margin-top:10px; background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.2); border-radius:8px; padding:8px; color:#fff;"></textarea>
    <button onclick="submitComment()" style="background:var(--primary); border:none; color:#fff; padding:8px 16px; border-radius:20px; margin-top:5px; width:100%;">ارسال</button>
</div>

<!-- نوار پایین -->
<nav class="bottom-nav" id="bottomNav">
    <a href="home.php" class="nav-item"><span class="icon">🏠</span><span>خانه</span></a>
    <a href="index.php" class="nav-item"><span class="icon">💬</span><span>پیام‌رسان</span></a>
    <a href="account.php" class="nav-item"><span class="icon">👤</span><span>حساب</span></a>
    <a href="explore.php" class="nav-item active"><span class="icon">🔍</span><span>اکسپلور</span></a>
    <a href="otaku.php" class="nav-item"><span class="icon">🎬</span><span>اوتاکو</span></a>
</nav>

<script>
const currentUserId = <?= $current_user_id ?>;
let posts = [];
let currentIndex = 0;
let isLoadingMore = false;
let hasMore = true;
let currentPage = 2;
let searchQuery = '';
let activeCommentPostId = null;

const initialPosts = <?= json_encode($initialPosts) ?>;
posts = initialPosts;

function createSlideElement(post, index) {
    const slide = document.createElement('div');
    slide.className = 'slide';
    slide.dataset.index = index;
    slide.innerHTML = `
        <div class="media-container">
            <img src="${post.media.img}" alt="${post.title}" id="img-${index}">
            <div class="play-btn-overlay" id="playBtn-${index}" onclick="event.stopPropagation(); startPlayback(${index})"><span class="play-icon">▶</span></div>
            <div class="video-container" id="video-${index}">
                <iframe id="videoFrame-${index}" allowfullscreen></iframe>
            </div>
        </div>
        <div class="slide-info">
            <div class="username">@${post.username || 'user'}</div>
            <div class="caption">${post.title}${post.subtitle ? ' · '+post.subtitle : ''}</div>
        </div>
        <div class="action-buttons">
            <button class="action-btn" id="eyeBtn-${index}" onclick="event.stopPropagation(); toggleNavVisibility()" title="نمایش/مخفی نوار">👁️</button>
            <button class="action-btn" id="likeBtn-${index}" onclick="event.stopPropagation(); toggleLike(${post.id}, ${index})">❤️<span id="likeCount-${index}">${post.stats.likes}</span></button>
            <button class="action-btn" onclick="event.stopPropagation(); openComments(${post.id})">💬<span id="commentCount-${index}">${post.stats.comments}</span></button>
            <button class="action-btn" onclick="event.stopPropagation(); sharePost('${post.share_hash}')">📤<span>اشتراک</span></button>
            <button class="action-btn" onclick="event.stopPropagation(); downloadCurrent(${index})">📥<span>دانلود</span></button>
        </div>
        <div class="quality-btns" id="qualityBtns-${index}">
            ${Object.entries(post.media.qualities).map(([label, url]) => `<button onclick="event.stopPropagation(); selectQuality(${index}, '${label}')">${label}</button>`).join('')}
        </div>
    `;
    return slide;
}

const sliderContainer = document.getElementById('sliderContainer');
function renderAllSlides() {
    sliderContainer.innerHTML = '';
    posts.forEach((post, i) => {
        sliderContainer.appendChild(createSlideElement(post, i));
    });
    updateDots();
    goToSlide(0);
}

function updateDots() {
    const dotsContainer = document.getElementById('customDots');
    dotsContainer.innerHTML = '';
    posts.forEach((_, i) => {
        const dot = document.createElement('div');
        dot.className = 'dot' + (i === currentIndex ? ' active' : '');
        dot.addEventListener('click', (e) => {
            e.stopPropagation();
            goToSlide(i);
        });
        dotsContainer.appendChild(dot);
    });
}

function goToSlide(index) {
    if (index < 0 || index >= posts.length) return;
    currentIndex = index;
    sliderContainer.style.transform = `translateY(-${currentIndex * 100}%)`;
    updateDots();
    // مخفی کردن همه ویدیوها و توقف کامل iframe ها
    document.querySelectorAll('.video-container').forEach(vc => vc.style.display = 'none');
    document.querySelectorAll('.video-container iframe').forEach(iframe => iframe.src = ''); // ** توقف کامل **
    document.querySelectorAll('.media-container img').forEach(img => img.style.opacity = '1');
    document.querySelectorAll('.play-btn-overlay').forEach(btn => btn.style.display = 'flex');
    if (currentIndex >= posts.length - 3) loadMorePosts();
}

// Swipe logic (بدون تغییر)
let startY = 0, currentY = 0, isDragging = false;
const sliderWrapper = document.getElementById('sliderWrapper');

function shouldIgnoreSwipe(e) {
    return e.target.closest('.action-btn, .play-btn-overlay, .quality-btns button, .search-bar, .comments-modal, .bottom-nav, .app-header, .nav-arrows') !== null;
}

sliderWrapper.addEventListener('touchstart', (e) => {
    if (shouldIgnoreSwipe(e)) return;
    startY = e.touches[0].clientY;
    currentY = startY;
    isDragging = true;
    sliderContainer.style.transition = 'none';
}, { passive: true });

sliderWrapper.addEventListener('touchmove', (e) => {
    if (!isDragging) return;
    currentY = e.touches[0].clientY;
    const delta = currentY - startY;
    sliderContainer.style.transform = `translateY(${-currentIndex * window.innerHeight + delta}px)`;
}, { passive: true });

sliderWrapper.addEventListener('touchend', () => {
    if (!isDragging) return;
    isDragging = false;
    sliderContainer.style.transition = 'transform 0.3s ease';
    const delta = currentY - startY;
    if (Math.abs(delta) > 60) {
        if (delta > 0 && currentIndex > 0) goToSlide(currentIndex - 1);
        else if (delta < 0 && currentIndex < posts.length - 1) goToSlide(currentIndex + 1);
        else goToSlide(currentIndex);
    } else {
        goToSlide(currentIndex);
    }
});

sliderWrapper.addEventListener('mousedown', (e) => {
    if (shouldIgnoreSwipe(e)) return;
    e.preventDefault();
    startY = e.clientY;
    currentY = startY;
    isDragging = true;
    sliderContainer.style.transition = 'none';
});
window.addEventListener('mousemove', (e) => {
    if (!isDragging) return;
    currentY = e.clientY;
    const delta = currentY - startY;
    sliderContainer.style.transform = `translateY(${-currentIndex * window.innerHeight + delta}px)`;
});
window.addEventListener('mouseup', () => {
    if (!isDragging) return;
    isDragging = false;
    sliderContainer.style.transition = 'transform 0.3s ease';
    const delta = currentY - startY;
    if (Math.abs(delta) > 60) {
        if (delta > 0 && currentIndex > 0) goToSlide(currentIndex - 1);
        else if (delta < 0 && currentIndex < posts.length - 1) goToSlide(currentIndex + 1);
        else goToSlide(currentIndex);
    } else {
        goToSlide(currentIndex);
    }
});

function toggleNavVisibility() {
    document.body.classList.toggle('nav-hidden');
}

function startPlayback(index) {
    const post = posts[index];
    if (!post || !post.media.qualities || Object.keys(post.media.qualities).length === 0) return;
    const firstLabel = Object.keys(post.media.qualities)[0];
    selectQuality(index, firstLabel);
    document.getElementById(`video-${index}`).style.display = 'block';
    document.getElementById(`playBtn-${index}`).style.display = 'none';
    document.getElementById(`img-${index}`).style.opacity = '0.3';
}

function selectQuality(index, label) {
    const post = posts[index];
    const url = post.media.qualities[label];
    if (url) {
        document.getElementById(`videoFrame-${index}`).src = url;
        const btns = document.querySelectorAll(`#qualityBtns-${index} button`);
        btns.forEach(b => b.classList.remove('active'));
        btns.forEach(b => { if (b.textContent === label) b.classList.add('active'); });
    }
}

function downloadCurrent(index) {
    const active = document.querySelector(`#qualityBtns-${index} button.active`);
    if (active) {
        const label = active.textContent;
        const url = posts[index].media.qualities[label];
        if (url) window.open(url, '_blank');
    } else {
        const first = Object.keys(posts[index].media.qualities)[0];
        if (first) window.open(posts[index].media.qualities[first], '_blank');
    }
}

async function toggleLike(postId, index) {
    const res = await fetch('explore.php?action=toggle_like', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `post_id=${postId}`
    });
    const result = await res.json();
    if (result.success) {
        document.getElementById(`likeCount-${index}`).textContent = result.likes;
        const btn = document.getElementById(`likeBtn-${index}`);
        btn.innerHTML = result.liked ? '❤️<span>' + result.likes + '</span>' : '🤍<span>' + result.likes + '</span>';
    }
}

function openComments(postId) {
    activeCommentPostId = postId;
    document.getElementById('commentsModal').style.display = 'block';
    loadComments(postId);
}
function closeComments() { document.getElementById('commentsModal').style.display = 'none'; }
async function loadComments(postId) {
    const res = await fetch(`explore.php?action=get_comments&post_id=${postId}`);
    const comments = await res.json();
    const list = document.getElementById('commentsList');
    list.innerHTML = comments.map(c => 
        `<div class="comment"><strong>${c.display_name || c.username}</strong>: ${c.comment}</div>`
    ).join('');
}
async function submitComment() {
    const input = document.getElementById('commentInput');
    const comment = input.value.trim();
    if (!comment || !activeCommentPostId) return;
    const res = await fetch('explore.php?action=add_comment', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `post_id=${activeCommentPostId}&comment=${encodeURIComponent(comment)}`
    });
    const result = await res.json();
    if (result.success) {
        input.value = '';
        loadComments(activeCommentPostId);
        const idx = posts.findIndex(p => p.id === activeCommentPostId);
        if (idx !== -1) {
            document.getElementById(`commentCount-${idx}`).textContent = result.comments;
            posts[idx].stats.comments = result.comments;
        }
    }
}

function sharePost(hash) {
    const basePath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
    const url = `${window.location.origin}${basePath}/post.php?hash=${hash}`;
    if (navigator.share) {
        navigator.share({ title: 'پست اوتاکو', url: url });
    } else {
        prompt('لینک پست:', url);
    }
}

function toggleSearch() {
    document.getElementById('searchBar').classList.toggle('active');
}

function onSearchInput() {
    searchQuery = document.getElementById('searchInput').value.trim();
    currentPage = 1;
    hasMore = true;
    posts = [];
    loadMorePosts(true);
}

async function loadMorePosts(reset = false) {
    if (isLoadingMore || (!hasMore && !reset)) return;
    isLoadingMore = true;
    const res = await fetch(`explore.php?action=load_posts&p=${currentPage}&search=${encodeURIComponent(searchQuery)}`);
    const data = await res.json();
    if (data.posts && data.posts.length > 0) {
        if (reset) {
            posts = data.posts;
        } else {
            posts = posts.concat(data.posts);
        }
        hasMore = data.hasMore;
        currentPage++;
        renderAllSlides();
        goToSlide(reset ? 0 : posts.length - data.posts.length);
    } else if (reset) {
        posts = [];
        renderAllSlides();
    }
    isLoadingMore = false;
}

let lastViewedIndex = -1;
function observeSlideChange() {
    if (currentIndex !== lastViewedIndex && posts[currentIndex]) {
        fetch(`explore.php?action=view&post_id=${posts[currentIndex].id}`);
        lastViewedIndex = currentIndex;
    }
}
setInterval(observeSlideChange, 1000);

renderAllSlides();
</script>
</body>
</html>