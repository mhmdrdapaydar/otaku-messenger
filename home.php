<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>در دست تعمیر | دنیای اوتاکو</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: var(--bg);
            background-image: 
                radial-gradient(circle at 20% 30%, #1a1a3e 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, #2a0a2a 0%, transparent 50%);
            height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding-bottom: calc(150px + env(safe-area-inset-bottom, 20px));
            color: var(--text);
            text-shadow: 0 0 2px rgba(255, 255, 255, 0.25);
            transition: padding-bottom 0.3s ease;
            overflow: hidden;
        }
        body.nav-hidden {
            padding-bottom: calc(20px + env(safe-area-inset-bottom, 20px));
        }
        body.nav-hidden .bottom-nav {
            opacity: 0;
            pointer-events: none;
        }

        /* ============= ذرات پیشرفته ============= */
        .particles {
            position: fixed; top:0; left:0; width:100%; height:100%; pointer-events: none; z-index:0;
        }
        .particle {
            position: absolute; border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, rgba(255,77,109,0.6), rgba(255,77,109,0.05));
            box-shadow: 0 0 30px rgba(255,77,109,0.4), 0 0 60px rgba(255,77,109,0.1);
            animation: floatParticle 14s infinite ease-in-out;
            filter: blur(0.8px);
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

        /* ============= محفظه مرکزی ============= */
        .maintenance-box {
            position: relative;
            z-index: 2;
            text-align: center;
            background: rgba(15, 15, 35, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 30px;
            padding: 45px 35px;
            margin: 20px;
            max-width: 420px;
            width: 90%;
            box-shadow: 
                0 15px 35px rgba(0,0,0,0.6),
                inset 0 0 20px rgba(255,77,109,0.05);
            animation: boxFloat 6s ease-in-out infinite;
        }
        @keyframes boxFloat {
            0%,100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .gear-wrapper {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }
        .gear {
            font-size: 3.5rem;
            animation: spin 4s linear infinite;
            filter: drop-shadow(0 0 15px var(--primary));
        }
        .gear-small {
            font-size: 2rem;
            animation: spin 5s linear infinite reverse;
            margin: 0 -10px;
            opacity: 0.8;
            filter: drop-shadow(0 0 10px var(--primary-hover));
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .maintenance-box h1 {
            font-size: 2rem;
            font-weight: 700;
            margin: 15px 0;
            background: linear-gradient(135deg, var(--primary), #fff, var(--primary-hover));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: none;
            letter-spacing: -0.5px;
        }

        .maintenance-box .subtitle {
            color: var(--secondary-text);
            font-size: 1rem;
            margin-bottom: 25px;
            line-height: 1.7;
        }
        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(255,255,255,0.1);
            border-radius: 3px;
            margin: 25px 0 20px;
            overflow: hidden;
        }
        .progress-fill {
            width: 70%;
            height: 100%;
            background: linear-gradient(90deg, var(--primary), #ff8fa3);
            border-radius: 3px;
            animation: progressPulse 2s ease-in-out infinite;
            box-shadow: 0 0 10px var(--primary);
        }
        @keyframes progressPulse {
            0%,100% { width: 60%; }
            50% { width: 80%; }
        }

        .btn-group {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        .btn {
            padding: 10px 22px;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,77,109,0.15);
            border: 1px solid var(--primary);
            color: var(--primary);
            backdrop-filter: blur(5px);
        }
        .btn:hover {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 0 20px rgba(255,77,109,0.5);
            transform: translateY(-2px);
        }
        .btn-outline {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.3);
            color: #ccc;
        }
        .btn-outline:hover {
            border-color: #fff;
            background: rgba(255,255,255,0.1);
            color: #fff;
        }

        /* ============= نوار پایین ============= */
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

        @media (max-width: 768px) {
            .maintenance-box {
                padding: 30px 20px;
            }
            .maintenance-box h1 {
                font-size: 1.6rem;
            }
        }
    </style>
</head>
<body>
<div class="particles">
    <div class="particle"></div><div class="particle"></div><div class="particle"></div>
    <div class="particle"></div><div class="particle"></div><div class="particle"></div>
</div>

<div class="maintenance-box">
    <!-- چرخ‌دنده‌های چرخان -->
    <div class="gear-wrapper">
        <span class="gear">⚙️</span>
        <span class="gear gear-small">⚙️</span>
    </div>
    
    <h1>در دست تعمیر</h1>
    <p class="subtitle">
        ما در حال بروزرسانی و بهینه‌سازی سایت هستیم.<br>
        به زودی با امکانات جدید برمی‌گردیم!
    </p>
    
    <!-- نوار پیشرفت تزئینی -->
    <div class="progress-bar">
        <div class="progress-fill"></div>
    </div>
    
    <div class="btn-group">
        <a href="index.php" class="btn">🔄 تلاش مجدد</a>
        <a href="logout.php" class="btn btn-outline">🚪 خروج</a>
    </div>
</div>

<nav class="bottom-nav" id="bottomNav">
    <a href="home.php" class="nav-item active" aria-label="خانه"><span class="icon">🏠</span><span>خانه</span></a>
    <a href="index.php" class="nav-item" aria-label="پیام‌رسان"><span class="icon">💬</span><span>پیام‌رسان</span></a>
    <a href="<?= isset($_SESSION['user_id']) ? 'account.php' : 'login.php' ?>" class="nav-item" aria-label="حساب"><span class="icon">👤</span><span>حساب</span></a>
    <a href="explore.php" class="nav-item" aria-label="اکسپلور"><span class="icon">🔍</span><span>اکسپلور</span></a>
    <a href="otaku.php" class="nav-item" aria-label="اوتاکو"><span class="icon">🎬</span><span>اوتاکو</span></a>
</nav>

<script>
    // مدیریت مخفی‌سازی نوار ناوبری (همانند نسخه اصلی)
    (function() {
        const stored = localStorage.getItem('navHidden');
        if (stored === 'true') document.body.classList.add('nav-hidden');
    })();
    function toggleNavVisibility() {
        const isHidden = document.body.classList.toggle('nav-hidden');
        localStorage.setItem('navHidden', isHidden);
    }
</script>
</body>
</html>