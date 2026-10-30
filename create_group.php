<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$current_user_id = $_SESSION['user_id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $type = $_POST['type'];
    $privacy = $_POST['privacy'];
    $desc = trim($_POST['description'] ?? '');
    $avatar_url = trim($_POST['avatar_url'] ?? '');
    $slug = trim($_POST['slug'] ?? '');

    if (empty($name)) {
        $error = 'نام اجباری است.';
    } elseif (!empty($slug)) {
        // بررسی یکتایی slug
        $stmt = $pdo->prepare("SELECT 1 FROM groups_channels WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetchColumn()) {
            $error = 'این لینک یکتا قبلاً استفاده شده است.';
        } else {
            $stmt = $pdo->prepare("SELECT 1 FROM users WHERE username = ?");
            $stmt->execute([$slug]);
            if ($stmt->fetchColumn()) {
                $error = 'این لینک یکتا توسط یک کاربر ثبت شده است.';
            }
        }
    }

    // بررسی دامنه آواتار
    if (empty($error) && !empty($avatar_url)) {
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
        $pdo->prepare("INSERT INTO groups_channels (name, description, type, privacy, created_by, avatar_url, slug) 
                       VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$name, $desc, $type, $privacy, $current_user_id, $avatar_url ?: null, $slug ?: null]);
        $new_id = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO group_channel_members (group_id, user_id, role) VALUES (?, ?, 'owner')")->execute([$new_id, $current_user_id]);

        if ($slug) {
            header("Location: index.php?page=group&slug=" . urlencode($slug));
        } else {
            header("Location: index.php?page=group&id=$new_id");
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ایجاد گروه/کانال | اوتاکو</title>
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
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
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

        .card {
            position: relative; z-index: 2;
            background: rgba(15, 15, 35, 0.8);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border-radius: 24px;
            padding: 30px 25px;
            width: 100%;
            max-width: 420px;
            border: 1px solid var(--border);
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
            animation: fadeInUp 0.6s ease;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        h2 {
            text-align: center;
            margin-bottom: 20px;
            font-size: 1.8rem;
            color: var(--primary);
            text-shadow: 0 0 15px var(--primary);
        }
        .error {
            background: rgba(255, 77, 109, 0.15);
            border: 1px solid var(--primary);
            color: var(--primary);
            padding: 10px;
            border-radius: 12px;
            margin-bottom: 15px;
            text-align: center;
            font-size: 0.9rem;
        }
        input, select, textarea {
            width: 100%;
            padding: 10px 14px;
            margin-bottom: 12px;
            background: rgba(255,255,255,0.08);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text);
            outline: none;
            font-family: inherit;
            font-size: 0.95rem;
            transition: border 0.3s;
        }
        input:focus, select:focus, textarea:focus {
            border-color: var(--primary);
        }
        select option {
            background: #1a1a3e;
            color: #fff;
        }
        button {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            border: none;
            border-radius: 12px;
            color: #fff;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s, transform 0.2s;
        }
        button:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
        }
        small {
            color: var(--secondary-text);
            font-size: 0.8rem;
            display: block;
            margin-bottom: 10px;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 15px;
            color: var(--secondary-text);
            text-decoration: none;
            font-size: 0.9rem;
        }
        .back-link:hover { color: var(--primary); }
    </style>
</head>
<body>
    <div class="particles">
        <div class="particle"></div><div class="particle"></div><div class="particle"></div>
        <div class="particle"></div><div class="particle"></div><div class="particle"></div>
    </div>

    <div class="card">
        <h2>✨ ساخت گروه/کانال جدید</h2>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="text" name="name" placeholder="نام گروه یا کانال *" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
            <select name="type" required>
                <option value="group" <?= (($_POST['type'] ?? '') === 'group') ? 'selected' : '' ?>>گروه</option>
                <option value="channel" <?= (($_POST['type'] ?? '') === 'channel') ? 'selected' : '' ?>>کانال</option>
            </select>
            <select name="privacy" required>
                <option value="public" <?= (($_POST['privacy'] ?? '') === 'public') ? 'selected' : '' ?>>عمومی</option>
                <option value="private" <?= (($_POST['privacy'] ?? '') === 'private') ? 'selected' : '' ?>>خصوصی</option>
            </select>
            <textarea name="description" rows="3" placeholder="توضیحات (اختیاری)"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
            <input type="url" name="avatar_url" placeholder="لینک عکس پروفایل (اختیاری)" value="<?= htmlspecialchars($_POST['avatar_url'] ?? '') ?>">
            <small style="color: var(--secondary-text);">فقط دامنه‌های مجاز (مانند uploadkon.ir)</small>
            <input type="text" name="slug" placeholder="لینک یکتا (مثلاً my-anime-group)" value="<?= htmlspecialchars($_POST['slug'] ?? '') ?>">
            <small>فقط حروف، اعداد و خط تیره. در صورت عمومی بودن، در آدرس استفاده می‌شود.</small>
            <button type="submit">ایجاد</button>
        </form>
        <a href="index.php" class="back-link">← بازگشت به پیام‌رسان</a>
    </div>
</body>
</html>