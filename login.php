<?php
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'نام کاربری و رمز عبور را وارد کنید.';
    } else {
        // جستجوی کاربر با نام کاربری، ایمیل یا موبایل
        $stmt = $pdo->prepare("SELECT id, username, password, restricted_until FROM users 
                               WHERE username = :user OR email = :user OR mobile = :user LIMIT 1");
        $stmt->execute([':user' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // بررسی محدودیت
            if ($user['restricted_until'] && strtotime($user['restricted_until']) > time()) {
                $error = '⛔ حساب شما تا ' . date('Y/m/d ساعت H:i', strtotime($user['restricted_until'])) . ' محدود شده است.';
            } else {
                // به‌روزرسانی وضعیت آنلاین
                $stmt = $pdo->prepare("UPDATE users SET is_online = 1, last_seen = NOW() WHERE id = :id");
                $stmt->execute([':id' => $user['id']]);
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                header('Location: index.php');
                exit;
            }
        } else {
            $error = '❌ نام کاربری یا رمز عبور اشتباه است.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود | پیام‌رسان انیمه</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Tahoma, 'Segoe UI', sans-serif;
            background: #0f0c29;
            background: radial-gradient(ellipse at center, #1a1a3e 0%, #0c0c1e 100%);
            color: #eee;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            position: relative;
            overflow: hidden;
        }
        
        /* ذرات پس‌زمینه */
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
            background: rgba(233, 69, 96, 0.2);
            box-shadow: 0 0 15px rgba(233, 69, 96, 0.3);
            animation: float 12s infinite ease-in-out;
        }
        
        .particle:nth-child(1) { width: 80px; height: 80px; left: 10%; top: 20%; animation-delay: 0s; }
        .particle:nth-child(2) { width: 60px; height: 60px; left: 80%; top: 70%; animation-delay: -4s; background: rgba(15, 52, 96, 0.3); }
        .particle:nth-child(3) { width: 100px; height: 100px; left: 50%; top: 40%; animation-delay: -8s; background: rgba(233, 69, 96, 0.15); }
        .particle:nth-child(4) { width: 50px; height: 50px; left: 30%; top: 80%; animation-delay: -2s; background: rgba(255, 107, 129, 0.25); }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) scale(1) rotate(0deg); }
            25% { transform: translateY(-30px) scale(1.05) rotate(5deg); }
            50% { transform: translateY(-15px) scale(0.95) rotate(-3deg); }
            75% { transform: translateY(-40px) scale(1.02) rotate(2deg); }
        }
        
        .login-box {
            position: relative;
            z-index: 2;
            background: rgba(20, 20, 45, 0.55);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            padding: 40px 30px;
            border-radius: 30px;
            width: 380px;
            border: 1px solid rgba(233, 69, 96, 0.3);
            box-shadow: 0 20px 40px rgba(0,0,0,0.6), inset 0 0 30px rgba(233,69,96,0.1);
            animation: fadeInUp 0.8s ease;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .login-box h2 {
            text-align: center;
            color: #e94560;
            margin-bottom: 25px;
            font-size: 2rem;
            text-shadow: 0 0 15px #e94560;
        }
        
        .input-group {
            margin-bottom: 20px;
        }
        
        .input-group label {
            display: block;
            margin-bottom: 8px;
            color: #ccc;
            font-size: 0.9rem;
        }
        
        .input-group input {
            width: 100%;
            padding: 12px 15px;
            border-radius: 15px;
            border: 1px solid rgba(233, 69, 96, 0.2);
            background: rgba(15, 52, 96, 0.5);
            color: #fff;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .input-group input:focus {
            outline: none;
            border-color: #e94560;
            box-shadow: 0 0 15px rgba(233, 69, 96, 0.3);
            background: rgba(15, 52, 96, 0.8);
        }
        
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(45deg, #e94560, #53346d);
            border: none;
            border-radius: 15px;
            color: #fff;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(233, 69, 96, 0.4);
        }
        
        .error {
            background: rgba(255, 107, 129, 0.1);
            border: 1px solid #ff6b81;
            color: #ff6b81;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 0.9rem;
            animation: shake 0.5s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        
        .register-link {
            text-align: center;
            margin-top: 20px;
            color: #aaa;
        }
        
        .register-link a {
            color: #e94560;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s;
        }
        
        .register-link a:hover {
            color: #ff6b81;
            text-shadow: 0 0 10px #e94560;
        }
    </style>
</head>
<body>
    <!-- ذرات پس‌زمینه -->
    <div class="particles">
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
    </div>

    <div class="login-box">
        <h2>🔑 ورود به حساب</h2>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="post">
            <div class="input-group">
                <label>نام کاربری، ایمیل یا شماره موبایل</label>
                <input type="text" name="username" placeholder="مثال: username یا email@example.com" required 
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            
            <div class="input-group">
                <label>رمز عبور</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            
            <button type="submit" class="btn-login">ورود به پیام‌رسان</button>
        </form>
        
        <div class="register-link">
            حساب کاربری ندارید؟ <a href="register.php">ثبت‌نام کنید</a>
        </div>
    </div>
</body>
</html>