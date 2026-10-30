<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] != 1) {
    die("دسترسی غیرمجاز");
}

$message = '';
$error = '';
$user_id = (int)($_GET['id'] ?? 0);
if (!$user_id) {
    die("شناسه کاربر نامعتبر.");
}

// دریافت اطلاعات کاربر
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    die("کاربر یافت نشد.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $bio = trim($_POST['bio'] ?? '');

    if (empty($username) || empty($first_name) || empty($last_name)) {
        $error = "نام کاربری، نام و نام خانوادگی الزامی هستند.";
    } else {
        // بررسی تکراری بودن نام کاربری (به جز کاربر جاری)
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->execute([$username, $user_id]);
        if ($check->fetch()) {
            $error = "این نام کاربری قبلاً استفاده شده است.";
        } else {
            $stmt = $pdo->prepare("UPDATE users SET username=?, first_name=?, last_name=?, display_name=?, email=?, mobile=?, bio=? WHERE id=?");
            $stmt->execute([$username, $first_name, $last_name, $display_name, $email, $mobile, $bio, $user_id]);
            $message = "اطلاعات کاربر با موفقیت بروزرسانی شد.";
            // بازخوانی اطلاعات
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ویرایش کاربر</title>
    <style>
        body { font-family: Tahoma; background: #0a0a1a; color: #fff; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .box { background: rgba(255,255,255,0.05); backdrop-filter: blur(10px); padding: 25px; border-radius: 16px; width: 400px; border: 1px solid #ff4d6d; }
        input, textarea { width: 100%; padding: 8px; margin: 6px 0; border-radius: 8px; border: 1px solid #ff4d6d; background: rgba(0,0,0,0.3); color: #fff; }
        button { background: #ff4d6d; color: #fff; border: none; padding: 10px 20px; border-radius: 20px; cursor: pointer; }
        .msg { color: #4caf50; margin: 10px 0; }
        .err { color: #ff4d6d; margin: 10px 0; }
    </style>
</head>
<body>
<div class="box">
    <h2>✏️ ویرایش کاربر (ID: <?= $user_id ?>)</h2>
    <?php if ($message): ?><div class="msg"><?= $message ?></div><?php endif; ?>
    <?php if ($error): ?><div class="err"><?= $error ?></div><?php endif; ?>
    <form method="post">
        <label>نام کاربری:</label>
        <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
        <label>نام:</label>
        <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" required>
        <label>نام خانوادگی:</label>
        <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" required>
        <label>نام نمایشی:</label>
        <input type="text" name="display_name" value="<?= htmlspecialchars($user['display_name'] ?? '') ?>">
        <label>ایمیل:</label>
        <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
        <label>شماره موبایل:</label>
        <input type="text" name="mobile" value="<?= htmlspecialchars($user['mobile'] ?? '') ?>">
        <label>بیوگرافی:</label>
        <textarea name="bio" rows="3"><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
        <br>
        <button type="submit">💾 ذخیره تغییرات</button>
        <a href="admin.php?tab=users" style="color:#ff4d6d; display: block; margin-top: 10px;">بازگشت</a>
    </form>
</div>
</body>
</html>