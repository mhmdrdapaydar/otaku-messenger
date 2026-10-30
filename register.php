<?php
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username   = trim($_POST['username'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $password   = $_POST['password'] ?? '';
    $mobile     = trim($_POST['mobile'] ?? '');
    $email      = trim($_POST['email'] ?? '');

    // اعتبارسنجی نام کاربری
    if (empty($username) || empty($first_name) || empty($last_name) || empty($password)) {
        $error = 'نام کاربری، نام، نام خانوادگی و رمز عبور اجباری هستند.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{5,}$/', $username)) {
        $error = 'نام کاربری باید حداقل ۵ کاراکتر و فقط شامل حروف انگلیسی، اعداد و زیرخط (_) باشد.';
    } elseif (strlen($password) < 6) {
        $error = 'رمز عبور باید حداقل ۶ کاراکتر باشد.';
    } else {
        // تکراری بودن نام کاربری
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        if ($stmt->fetch()) {
            $error = 'این نام کاربری قبلاً ثبت شده است.';
        } else {
            // بررسی موبایل
            if (!empty($mobile)) {
                if (!preg_match('/^09\d{9}$/', $mobile)) {
                    $error = 'شماره موبایل باید با 09 شروع شده و 11 رقم باشد (مثال: 09012345678).';
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE mobile = :mobile");
                    $stmt->execute([':mobile' => $mobile]);
                    if ($stmt->fetch()) {
                        $error = 'این شماره موبایل قبلاً ثبت شده است.';
                    }
                }
            }
            // بررسی ایمیل
            if (empty($error) && !empty($email)) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'ایمیل نامعتبر است.';
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
                    $stmt->execute([':email' => $email]);
                    if ($stmt->fetch()) {
                        $error = 'این ایمیل قبلاً ثبت شده است.';
                    }
                }
            }

            if (empty($error)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, first_name, last_name, password, mobile, email) 
                                       VALUES (:username, :fname, :lname, :pass, :mobile, :email)");
                $stmt->execute([
                    ':username' => $username,
                    ':fname'    => $first_name,
                    ':lname'    => $last_name,
                    ':pass'     => $hashed_password,
                    ':mobile'   => $mobile ?: null,
                    ':email'    => $email ?: null
                ]);

                $user_id = $pdo->lastInsertId();
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $username;
                header('Location: index.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ثبت‌نام | دنیای اوتاکو</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: #050510;
            background-image: 
                radial-gradient(circle at 20% 30%, #1a1a3e 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, #2a0a2a 0%, transparent 50%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
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
            padding: 35px 30px;
            width: 100%;
            max-width: 420px;
            border: 1px solid rgba(255, 77, 109, 0.3);
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
            animation: fadeInUp 0.6s ease;
            color: #fff;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h2 {
            text-align: center;
            color: #ff4d6d;
            font-size: 2rem;
            margin-bottom: 25px;
            text-shadow: 0 0 15px #ff4d6d;
        }

        .warning {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid #ffc107;
            color: #ffc107;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            text-align: center;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.9rem;
            color: #b0b0c0;
        }

        input {
            width: 100%;
            padding: 12px 15px;
            border-radius: 12px;
            border: 1px solid rgba(255, 77, 109, 0.2);
            background: rgba(255, 255, 255, 0.07);
            color: #fff;
            font-family: inherit;
            font-size: 1rem;
            transition: border 0.3s, background 0.3s;
            outline: none;
        }

        input:focus {
            border-color: #ff4d6d;
            background: rgba(255, 77, 109, 0.1);
            box-shadow: 0 0 10px rgba(255, 77, 109, 0.2);
        }

        input::placeholder {
            color: #777;
        }

        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(45deg, #ff4d6d, #8a2387);
            border: none;
            border-radius: 12px;
            color: #fff;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
            transition: transform 0.2s, box-shadow 0.3s;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 77, 109, 0.4);
        }

        .error {
            background: rgba(255, 77, 109, 0.15);
            border: 1px solid #ff4d6d;
            color: #ff6b81;
            padding: 10px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 0.9rem;
            animation: shake 0.5s ease;
        }
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }

        .login-link {
            text-align: center;
            margin-top: 20px;
            color: #aaa;
        }
        .login-link a {
            color: #ff4d6d;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s;
        }
        .login-link a:hover {
            color: #ff8fa3;
            text-shadow: 0 0 10px #ff4d6d;
        }

        small {
            display: block;
            margin-top: 4px;
            font-size: 0.75rem;
            color: #888;
        }
    </style>
</head>
<body>
    <div class="particles">
        <div class="particle"></div><div class="particle"></div><div class="particle"></div>
        <div class="particle"></div><div class="particle"></div><div class="particle"></div>
    </div>

    <div class="card">
        <h2>🌸 عضویت در دنیای اوتاکو</h2>

        <!-- اخطار نگهداری داده -->
        <div class="warning">
            ⚠️ سایت هیچ مسئولیتی در قبال نگهداری اطلاعات کاربرانی که شماره موبایل ثبت نمی‌کنند ندارد. این حساب‌ها ممکن است در آینده حذف شوند.
        </div>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label>نام کاربری *</label>
                <input type="text" name="username" placeholder="حداقل ۵ حرف انگلیسی یا عدد" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                <small>فقط حروف انگلیسی، اعداد و _ (حداقل ۵ کاراکتر)</small>
            </div>
            <div class="form-group">
                <label>نام *</label>
                <input type="text" name="first_name" placeholder="نام شما" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>نام خانوادگی *</label>
                <input type="text" name="last_name" placeholder="نام خانوادگی" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>رمز عبور *</label>
                <input type="password" name="password" placeholder="حداقل ۶ کاراکتر" required>
            </div>
            <div class="form-group">
                <label>شماره موبایل (اختیاری)</label>
                <input type="text" name="mobile" placeholder="09123456789" value="<?= htmlspecialchars($_POST['mobile'] ?? '') ?>">
                <small>11 رقم، شروع با 09</small>
            </div>
            <div class="form-group">
                <label>ایمیل (اختیاری)</label>
                <input type="email" name="email" placeholder="email@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <button type="submit">ایجاد حساب</button>
        </form>

        <div class="login-link">
            قبلاً ثبت‌نام کرده‌اید؟ <a href="login.php">وارد شوید</a>
        </div>
    </div>
</body>
</html>