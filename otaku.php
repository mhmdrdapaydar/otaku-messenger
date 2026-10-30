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
    <title>⚡ در حال توسعه | منطقه اوتاکو</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #ff4d6d;
            --primary-dark: #c9184a;
            --primary-light: #ff8fa3;
            --bg-dark: #0a0a1a;
            --card-bg: rgba(15, 15, 35, 0.65);
            --border-glow: rgba(255, 77, 109, 0.3);
            --text: #ffffff;
            --text-muted: #b8b8d0;
        }

        body {
            font-family: 'Segoe UI', 'Tahoma', system-ui, sans-serif;
            background: var(--bg-dark);
            background-image: radial-gradient(circle at 10% 20%, #1a1a3e 0%, transparent 45%),
                              radial-gradient(circle at 90% 75%, #2a0a2a 0%, transparent 55%),
                              repeating-linear-gradient(45deg, rgba(255,77,109,0.02) 0px, rgba(255,77,109,0.02) 2px, transparent 2px, transparent 8px);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            padding-bottom: 100px;
            color: var(--text);
        }

        /* ذرات متحرک پس‌زمینه */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .particle {
            position: absolute;
            border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, rgba(255, 77, 109, 0.5), rgba(255, 77, 109, 0.02));
            filter: blur(2px);
            animation: floatParticle 18s infinite alternate ease-in-out;
        }

        .particle:nth-child(1) { width: 120px; height: 120px; left: 5%; top: 15%; animation-duration: 20s; }
        .particle:nth-child(2) { width: 80px; height: 80px; left: 85%; top: 70%; background: radial-gradient(circle, rgba(156, 39, 176, 0.5), transparent); animation-duration: 25s; animation-delay: -3s; }
        .particle:nth-child(3) { width: 150px; height: 150px; left: 40%; top: 45%; animation-duration: 22s; animation-delay: -7s; }
        .particle:nth-child(4) { width: 60px; height: 60px; left: 20%; top: 80%; animation-duration: 17s; animation-delay: -2s; }
        .particle:nth-child(5) { width: 100px; height: 100px; left: 75%; top: 15%; background: radial-gradient(circle, rgba(255, 179, 198, 0.4), transparent); animation-duration: 28s; }
        .particle:nth-child(6) { width: 45px; height: 45px; left: 60%; top: 30%; animation-duration: 15s; }
        .particle:nth-child(7) { width: 90px; height: 90px; left: 15%; top: 40%; background: radial-gradient(circle, rgba(255, 200, 100, 0.3), transparent); animation-duration: 24s; }

        @keyframes floatParticle {
            0% { transform: translate(0, 0) scale(1) rotate(0deg); opacity: 0.6; }
            100% { transform: translate(30px, -40px) scale(1.2) rotate(8deg); opacity: 0.2; }
        }

        /* کارت اصلی */
        .develop-card {
            position: relative;
            z-index: 2;
            max-width: 560px;
            width: 100%;
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            border-radius: 56px;
            border: 1px solid var(--border-glow);
            padding: 2.2rem 1.8rem;
            text-align: center;
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.5), inset 0 1px 1px rgba(255, 255, 255, 0.08);
            transition: transform 0.25s ease;
        }

        .develop-card:hover {
            transform: translateY(-5px);
        }

        /* آیکون‌های اوتاکو */
        .otaku-icons {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 1.2rem;
        }

        .otaku-icons span {
            font-size: 3.2rem;
            filter: drop-shadow(0 0 10px var(--primary));
            animation: bounceLight 2.4s infinite;
            display: inline-block;
        }

        .otaku-icons span:nth-child(2) { animation-delay: 0.3s; }
        .otaku-icons span:nth-child(3) { animation-delay: 0.6s; }
        .otaku-icons span:nth-child(4) { animation-delay: 0.9s; }

        @keyframes bounceLight {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-12px); }
        }

        h1 {
            font-size: 2.4rem;
            background: linear-gradient(130deg, #fff, var(--primary), #ffb347);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
        }

        .tagline {
            font-size: 0.9rem;
            color: var(--primary-light);
            border-bottom: 2px dotted rgba(255, 77, 109, 0.5);
            display: inline-block;
            padding-bottom: 5px;
            margin-bottom: 1.5rem;
        }

        /* ویژگی‌های آینده */
        .features-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 12px;
            margin: 1.8rem 0;
        }

        .feature-chip {
            background: rgba(255, 255, 255, 0.07);
            border-radius: 60px;
            padding: 8px 18px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(255, 77, 109, 0.3);
            transition: all 0.2s;
        }

        .feature-chip:hover {
            background: rgba(255, 77, 109, 0.2);
            border-color: var(--primary);
            transform: scale(1.02);
        }

        /* نقل قول انیمه‌ای */
        .anime-quote {
            background: rgba(0, 0, 0, 0.45);
            border-radius: 32px;
            padding: 1rem 1.3rem;
            margin: 1.5rem 0;
            text-align: right;
            border-right: 4px solid var(--primary);
            font-style: normal;
        }

        .quote-text {
            font-size: 0.9rem;
            color: #e0e0ff;
            line-height: 1.5;
        }

        .quote-author {
            display: block;
            margin-top: 8px;
            font-size: 0.75rem;
            color: var(--primary-light);
            text-align: left;
        }

        /* نوار پیشرفت */
        .progress-area {
            margin: 1.5rem 0;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            margin-bottom: 6px;
            color: var(--text-muted);
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            width: 72%;
            height: 100%;
            background: linear-gradient(90deg, var(--primary), #ffb347);
            border-radius: 10px;
            position: relative;
            animation: shimmer 1.6s infinite;
        }

        @keyframes shimmer {
            0% { opacity: 0.7; }
            50% { opacity: 1; box-shadow: 0 0 6px var(--primary); }
            100% { opacity: 0.7; }
        }

        .note {
            font-size: 0.7rem;
            color: #aaa;
            margin-top: 8px;
            text-align: center;
        }

        /* دکمه‌ها */
        .button-group {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 14px;
            margin-top: 28px;
        }

        .btn {
            padding: 10px 24px;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            transition: 0.2s;
            background: rgba(255, 77, 109, 0.12);
            border: 1px solid var(--primary);
            color: var(--primary);
            backdrop-filter: blur(4px);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
            box-shadow: 0 5px 12px rgba(255, 77, 109, 0.3);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(255, 77, 109, 0.4);
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.12);
            color: white;
            border-color: white;
        }

        /* ناوبری پایین (بدون قابلیت مخفی‌سازی) */
        .bottom-nav {
            position: fixed;
            bottom: 18px;
            left: 50%;
            transform: translateX(-50%);
            width: 90%;
            max-width: 480px;
            background: rgba(8, 8, 20, 0.9);
            backdrop-filter: blur(32px);
            display: flex;
            justify-content: space-around;
            align-items: center;
            padding: 8px 12px;
            border-radius: 50px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 77, 109, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.08);
            z-index: 100;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #aaa;
            font-size: 0.68rem;
            font-weight: 500;
            padding: 6px 10px;
            border-radius: 30px;
            transition: all 0.2s;
        }

        .nav-item .icon {
            font-size: 1.5rem;
            margin-bottom: 3px;
            transition: 0.2s;
        }

        .nav-item.active .icon {
            color: var(--primary);
            filter: drop-shadow(0 0 8px var(--primary));
            text-shadow: 0 0 4px var(--primary);
        }

        .nav-item.active span {
            color: white;
            font-weight: bold;
        }

        .nav-item:hover .icon {
            transform: translateY(-3px);
            color: var(--primary-light);
        }

        @media (max-width: 550px) {
            .develop-card {
                padding: 1.6rem;
            }
            h1 {
                font-size: 1.9rem;
            }
            .otaku-icons span {
                font-size: 2.6rem;
            }
            .feature-chip {
                padding: 5px 12px;
                font-size: 0.75rem;
            }
            .btn {
                padding: 7px 18px;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
<div class="particles">
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
</div>

<div class="develop-card">
    <div class="otaku-icons">
        <span>🎌</span>
        <span>🍥</span>
        <span>🗻</span>
        <span>⚡</span>
    </div>
    <h1>در حال توسعه</h1>
    <div class="tagline">✦ منطقه اوتاکو ✦</div>

    <div class="features-grid">
        <div class="feature-chip"><span>📺</span> جدیدترین انیمه‌ها</div>
        <div class="feature-chip"><span>📖</span> معرفی مانگا</div>
        <div class="feature-chip"><span>🎵</span> اوپنینگ‌های ماندگار</div>
        <div class="feature-chip"><span>🗿</span> مجسمه و کالکشن</div>
        <div class="feature-chip"><span>🎴</span> کاراکترهای محبوب</div>
    </div>

    <div class="anime-quote">
        <div class="quote-text">
            «هیچ وقت تسلیم نشو، مهم نیست چه اتفاقی می‌افته. اون روز می‌رسه که به آرزوت می‌رسی.»
        </div>
        <div class="quote-author">— ناروتو اوزوماکی (ناروتو)</div>
    </div>

    <div class="progress-area">
        <div class="progress-header">
            <span>⚙️ پیشرفت راه‌اندازی</span>
            <span>۷۲٪</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>
        <div class="note">
            به زودی پر از اخبار، نقد و تحلیل انیمه، و فروشگاه اوتاکویی!
        </div>
    </div>

    <div class="button-group">
        <a href="index.php" class="btn btn-primary">🏠 صفحه اصلی</a>
        <a href="profile.php" class="btn btn-outline">👤 پروفایل من</a>
        <a href="logout.php" class="btn btn-outline">🚪 خروج</a>
    </div>
</div>

<nav class="bottom-nav">
    <a href="home.php" class="nav-item"><span class="icon">🏠</span><span>خانه</span></a>
    <a href="index.php" class="nav-item"><span class="icon">💬</span><span>پیام‌رسان</span></a>
    <a href="<?= isset($_SESSION['user_id']) ? 'account.php' : 'login.php' ?>" class="nav-item"><span class="icon">👤</span><span>حساب</span></a>
    <a href="explore.php" class="nav-item"><span class="icon">🔍</span><span>اکسپلور</span></a>
    <a href="otaku.php" class="nav-item active"><span class="icon">🎬</span><span>اوتاکو</span></a>
</nav>

</body>
</html>