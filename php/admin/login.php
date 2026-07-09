<?php
require_once 'config.php';
checkAuth();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    
    if ($user === ADMIN_USER && $pass === ADMIN_PASS) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user'] = $user;
        header('Location: index.php');
        exit;
    } else {
        $error = '账号或密码错误';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>桃之后台 - 登录</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
            background: linear-gradient(135deg, #e64340 0%, #ff7a7a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-box {
            background: #fff;
            border-radius: 20px;
            padding: 40px 32px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 16px 48px rgba(0,0,0,0.18);
        }
        .logo {
            text-align: center;
            margin-bottom: 32px;
        }
        .logo .peach {
            font-size: 52px;
            line-height: 1;
            margin-bottom: 12px;
        }
        .logo h1 {
            font-size: 26px;
            color: #1a1a1a;
            margin-bottom: 6px;
            font-weight: 700;
        }
        .logo p {
            color: #999;
            font-size: 13px;
            letter-spacing: 1px;
        }
        .form-item {
            margin-bottom: 18px;
            position: relative;
        }
        .form-item input {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid #ececec;
            border-radius: 10px;
            font-size: 15px;
            background: #fafafa;
            transition: border-color 0.2s, background 0.2s;
            -webkit-appearance: none;
        }
        .form-item input:focus {
            outline: none;
            border-color: #e64340;
            background: #fff;
        }
        .error {
            color: #e64340;
            font-size: 13px;
            margin-bottom: 14px;
            text-align: center;
            background: #fff0f0;
            padding: 10px;
            border-radius: 8px;
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: #e64340;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            cursor: pointer;
            font-weight: 600;
            letter-spacing: 4px;
            transition: background 0.2s, transform 0.1s;
        }
        .btn:hover { background: #d63838; }
        .btn:active { transform: scale(0.98); }

        @media (max-width: 480px) {
            .login-box { padding: 32px 22px; border-radius: 16px; }
            .logo .peach { font-size: 44px; }
            .logo h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="logo">
            <div class="peach">🍑</div>
            <h1>桃之</h1>
            <p>后台管理系统</p>
        </div>
        <?php if ($error): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-item">
                <input type="text" name="username" placeholder="账号" value="admin" required autocomplete="username">
            </div>
            <div class="form-item">
                <input type="password" name="password" placeholder="密码" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn">登 录</button>
        </form>
    </div>
</body>
</html>
